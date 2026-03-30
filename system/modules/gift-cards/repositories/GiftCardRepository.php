<?php

declare(strict_types=1);

namespace Modules\GiftCards\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * {@code gift_cards} tenancy contract (data-plane reads/writes must use the right class):
 *
 * 1. **Strict branch-owned** — {@code branch_id} non-null and that branch is in the branch-derived resolved org
 *    ({@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()} inside
 *    {@see OrganizationRepositoryScope::giftCardVisibleFromBranchContextClause()}).
 * 2. **Org-global but safe** — not used for gift-card *rows* (no org-wide template catalog here). The **global-only admin index** slice
 *    ({@see INDEX_SCOPE_GLOBAL_ONLY}) still uses branch-derived org proof via
 *    {@see OrganizationRepositoryScope::giftCardGlobalNullClientAnchoredInResolvedOrgClause()} (only null-{@code branch_id} cards whose
 *    client’s home branch is in that org).
 * 3. **Null-branch legacy (intentionally gated)** — {@code branch_id IS NULL} + {@code client_id} set: visible only when the client’s home
 *    branch’s organization matches the **context branch’s org** ({@code bctx} subquery, same family as client_memberships). No silent
 *    cross-tenant read; {@see listEligibleForClient()} delegates here when branch context is positive.
 * 4. **Control-plane / id-only unscoped** — {@see find()}, {@see findByCode()}, {@see list()}, {@see count()}, {@see update()}:
 *    no tenant predicates; callers must authorize by id or run in non-HTTP repair contexts.
 */
final class GiftCardRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function giftCardTenantVisibilityFromBranch(int $branchContextId): array
    {
        return $this->orgScope->giftCardVisibleFromBranchContextClause('gc', $branchContextId);
    }

    /**
     * Branch-bound card in org, or client-anchored null-{@code branch_id} card under the same context-branch org proof.
     *
     * @return array<string, mixed>|null
     */
    public function findInTenantScope(int $id, int $branchId, bool $withTrashed = false): ?array
    {
        $vis = $this->giftCardTenantVisibilityFromBranch($branchId);
        $sql = 'SELECT gc.*,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE gc.id = ? AND (' . $vis['sql'] . ')';
        if (!$withTrashed) {
            $sql .= ' AND gc.deleted_at IS NULL';
        }
        $params = array_merge([$id], $vis['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLockedInTenantScope(int $id, int $branchId): ?array
    {
        $vis = $this->giftCardTenantVisibilityFromBranch($branchId);

        return $this->db->fetchOne(
            'SELECT gc.* FROM gift_cards gc
             WHERE gc.id = ? AND gc.deleted_at IS NULL AND (' . $vis['sql'] . ') FOR UPDATE',
            array_merge([$id], $vis['params'])
        );
    }

    public const INDEX_SCOPE_BRANCH_CARDS = 'branch_cards';

    public const INDEX_SCOPE_GLOBAL_ONLY = 'global_only';

    /**
     * @return list<array<string, mixed>>
     */
    public function listInTenantScope(array $filters, int $branchId, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $scopeMode = $filters['scope_mode'] ?? self::INDEX_SCOPE_BRANCH_CARDS;
        if ($scopeMode !== self::INDEX_SCOPE_BRANCH_CARDS && $scopeMode !== self::INDEX_SCOPE_GLOBAL_ONLY) {
            $scopeMode = self::INDEX_SCOPE_BRANCH_CARDS;
        }
        $fieldFilters = $this->normalizeIndexFieldFilters($filters);

        $base = $this->indexTenantWhereSqlAndParams($scopeMode, $branchId);
        $sql = 'SELECT gc.*,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE ' . $base['sql'];
        $params = $base['params'];
        $this->appendIndexFieldFilters($sql, $params, $fieldFilters);
        $sql .= ' ORDER BY gc.created_at DESC, gc.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function countInTenantScope(array $filters, int $branchId): int
    {
        $scopeMode = $filters['scope_mode'] ?? self::INDEX_SCOPE_BRANCH_CARDS;
        if ($scopeMode !== self::INDEX_SCOPE_BRANCH_CARDS && $scopeMode !== self::INDEX_SCOPE_GLOBAL_ONLY) {
            $scopeMode = self::INDEX_SCOPE_BRANCH_CARDS;
        }
        $fieldFilters = $this->normalizeIndexFieldFilters($filters);

        $base = $this->indexTenantWhereSqlAndParams($scopeMode, $branchId);
        $sql = 'SELECT COUNT(*) AS c
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE ' . $base['sql'];
        $params = $base['params'];
        $this->appendIndexFieldFilters($sql, $params, $fieldFilters);
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Branch slice: {@see OrganizationRepositoryScope::giftCardVisibleFromBranchContextClause()}.
     * Global-only admin slice: null {@code branch_id} + client home branch in **branch-derived** resolved org.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function indexTenantWhereSqlAndParams(string $scopeMode, int $listBranchId): array
    {
        if ($scopeMode === self::INDEX_SCOPE_GLOBAL_ONLY) {
            $g = $this->orgScope->giftCardGlobalNullClientAnchoredInResolvedOrgClause();

            return [
                'sql' => 'gc.deleted_at IS NULL AND ' . $g['sql'],
                'params' => $g['params'],
            ];
        }

        $vis = $this->giftCardTenantVisibilityFromBranch($listBranchId);

        return [
            'sql' => 'gc.deleted_at IS NULL AND (' . $vis['sql'] . ')',
            'params' => $vis['params'],
        ];
    }

    /**
     * Supported index filters only; strips internal keys.
     *
     * @return array{code: ?string, client_name: ?string, status: ?string, issued_from: ?string, issued_to: ?string}
     */
    private function normalizeIndexFieldFilters(array $filters): array
    {
        $code = isset($filters['code']) && $filters['code'] !== null && trim((string) $filters['code']) !== ''
            ? trim((string) $filters['code'])
            : null;
        $clientName = isset($filters['client_name']) && $filters['client_name'] !== null && trim((string) $filters['client_name']) !== ''
            ? trim((string) $filters['client_name'])
            : null;
        $status = isset($filters['status']) && $filters['status'] !== null && trim((string) $filters['status']) !== ''
            ? trim((string) $filters['status'])
            : null;
        $from = isset($filters['issued_from']) && $filters['issued_from'] !== null && trim((string) $filters['issued_from']) !== ''
            ? trim((string) $filters['issued_from'])
            : null;
        $to = isset($filters['issued_to']) && $filters['issued_to'] !== null && trim((string) $filters['issued_to']) !== ''
            ? trim((string) $filters['issued_to'])
            : null;

        return [
            'code' => $code,
            'client_name' => $clientName,
            'status' => $status,
            'issued_from' => $from,
            'issued_to' => $to,
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function appendIndexFieldFilters(string &$sql, array &$params, array $fieldFilters): void
    {
        if ($fieldFilters['code'] !== null) {
            $sql .= ' AND gc.code LIKE ?';
            $params[] = '%' . $fieldFilters['code'] . '%';
        }
        if ($fieldFilters['client_name'] !== null) {
            $q = '%' . $fieldFilters['client_name'] . '%';
            $sql .= ' AND (c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params[] = $q;
            $params[] = $q;
        }
        if ($fieldFilters['status'] !== null) {
            $sql .= ' AND gc.status = ?';
            $params[] = $fieldFilters['status'];
        }
        if ($fieldFilters['issued_from'] !== null) {
            $sql .= ' AND DATE(gc.issued_at) >= ?';
            $params[] = $fieldFilters['issued_from'];
        }
        if ($fieldFilters['issued_to'] !== null) {
            $sql .= ' AND DATE(gc.issued_at) <= ?';
            $params[] = $fieldFilters['issued_to'];
        }
    }

    /**
     * Control-plane / id-only: **no** org/branch predicates.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        $sql = 'SELECT gc.*,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE gc.id = ?';
        if (!$withTrashed) {
            $sql .= ' AND gc.deleted_at IS NULL';
        }
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Control-plane / code lookup: **no** tenant scope (unique code is not a tenancy proof).
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code, bool $withTrashed = false): ?array
    {
        $sql = 'SELECT gc.*,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE gc.code = ?';
        if (!$withTrashed) {
            $sql .= ' AND gc.deleted_at IS NULL';
        }
        return $this->db->fetchOne($sql, [$code]);
    }

    /**
     * Unscoped listing (optional filter narrowing only). **Not** a tenant-safe index; prefer {@see listInTenantScope}.
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT gc.*,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE gc.deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (gc.code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND gc.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND gc.branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND gc.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $sql .= ' ORDER BY gc.created_at DESC, gc.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Unscoped count companion to {@see list()}.
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c
                FROM gift_cards gc
                LEFT JOIN clients c ON c.id = gc.client_id
                WHERE gc.deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (gc.code LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND gc.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND gc.branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND gc.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('gift_cards', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    /**
     * Id-only UPDATE by primary key — **no** tenant WHERE; caller must prove authorization.
     */
    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE gift_cards SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * Legacy helper — prefer {@see listEligibleForClientInTenantScope} for tenant surfaces.
     */
    public function listEligibleForClient(int $clientId, ?int $branchContext = null): array
    {
        if ($branchContext === null || $branchContext <= 0) {
            return [];
        }

        return $this->listEligibleForClientInTenantScope($clientId, $branchContext);
    }

    /**
     * Active gift cards for a client visible under branch context (classes 1 + 3).
     *
     * @return list<array<string, mixed>>
     */
    public function listEligibleForClientInTenantScope(int $clientId, int $branchContextId): array
    {
        if ($clientId <= 0 || $branchContextId <= 0) {
            return [];
        }
        $vis = $this->giftCardTenantVisibilityFromBranch($branchContextId);

        return $this->db->fetchAll(
            'SELECT gc.*
             FROM gift_cards gc
             WHERE gc.deleted_at IS NULL
               AND gc.client_id = ?
               AND gc.status = ?
               AND (' . $vis['sql'] . ')
             ORDER BY gc.expires_at IS NULL ASC, gc.expires_at ASC, gc.id ASC',
            array_merge([$clientId, 'active'], $vis['params'])
        );
    }

    /**
     * Profile-style list: all statuses, tenant-visible cards for client at branch (classes 1 + 3).
     *
     * @return list<array<string, mixed>>
     */
    public function listByClientIdInBranchTenantScope(int $clientId, int $branchContextId, int $limit = 100): array
    {
        if ($clientId <= 0 || $branchContextId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $vis = $this->giftCardTenantVisibilityFromBranch($branchContextId);

        return $this->db->fetchAll(
            'SELECT gc.*
             FROM gift_cards gc
             WHERE gc.deleted_at IS NULL
               AND gc.client_id = ?
               AND (' . $vis['sql'] . ')
             ORDER BY gc.created_at DESC, gc.id DESC
             LIMIT ' . $limit,
            array_merge([$clientId], $vis['params'])
        );
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'branch_id', 'client_id', 'code', 'original_amount', 'currency', 'issued_at',
            'expires_at', 'status', 'notes', 'created_by', 'updated_by', 'deleted_at',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
