<?php

declare(strict_types=1);

namespace Modules\GiftCards\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Core\Repository\RepositoryContractGuard;

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
 * 4. **Compatibility-only legacy generics** — ambiguous generic reads/mutations are locked fail-closed; runtime must use
 *    explicit tenant-scoped methods, and non-runtime uniqueness probes use {@see findByCodeForUniquenessCheck()}.
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
     * @return array{code: ?string, client_name: ?string, client_id: ?int, status: ?string, issued_from: ?string, issued_to: ?string}
     */
    private function normalizeIndexFieldFilters(array $filters): array
    {
        $code = isset($filters['code']) && $filters['code'] !== null && trim((string) $filters['code']) !== ''
            ? trim((string) $filters['code'])
            : null;
        $clientName = isset($filters['client_name']) && $filters['client_name'] !== null && trim((string) $filters['client_name']) !== ''
            ? trim((string) $filters['client_name'])
            : null;
        $clientId = null;
        if (isset($filters['client_id']) && $filters['client_id'] !== null && $filters['client_id'] !== '') {
            $cid = (int) $filters['client_id'];
            if ($cid > 0) {
                $clientId = $cid;
            }
        }
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
            'client_id' => $clientId,
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
        if ($fieldFilters['client_id'] !== null) {
            $sql .= ' AND gc.client_id = ?';
            $params[] = $fieldFilters['client_id'];
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
     * Unscoped uniqueness probe for gift-card code generation; not a tenant visibility API.
     *
     * @return array<string, mixed>|null
     */
    public function findByCodeForUniquenessCheck(string $code, bool $withTrashed = false): ?array
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
     * Compatibility-only legacy generic read. Mixed-semantics primary-key reads are locked fail-closed.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('GiftCardRepository::find', ['findInTenantScope']);
    }

    /**
     * Compatibility-only legacy code lookup. Use {@see findByCodeForUniquenessCheck()} for non-visibility uniqueness probes.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code, bool $withTrashed = false): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('GiftCardRepository::findByCode', ['findByCodeForUniquenessCheck']);
    }

    /**
     * Unscoped listing (optional filter narrowing only). **Not** a tenant-safe index; prefer {@see listInTenantScope}.
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('GiftCardRepository::list', ['listInTenantScope']);
    }

    /**
     * Unscoped count companion to {@see list()}.
     */
    public function count(array $filters = []): int
    {
        RepositoryContractGuard::denyMixedSemanticsApi('GiftCardRepository::count', ['countInTenantScope']);
    }

    public function create(array $data): int
    {
        $this->db->insert('gift_cards', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function updateInTenantScope(int $id, int $branchId, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $vis = $this->giftCardTenantVisibilityFromBranch($branchId);
        $cols = array_map(fn ($k) => "gc.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query(
            'UPDATE gift_cards gc SET ' . implode(', ', $cols) . ' WHERE gc.id = ? AND (' . $vis['sql'] . ')',
            array_merge($vals, $vis['params'])
        );
    }

    /**
     * Compatibility-only legacy generic mutation. Mixed-semantics primary-key writes are locked fail-closed.
     */
    public function update(int $id, array $data): void
    {
        RepositoryContractGuard::denyMixedSemanticsApi('GiftCardRepository::update', ['updateInTenantScope']);
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
