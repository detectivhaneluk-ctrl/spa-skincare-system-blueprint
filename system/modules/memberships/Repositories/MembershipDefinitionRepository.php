<?php

declare(strict_types=1);

namespace Modules\Memberships\Repositories;

use Core\App\Database;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationRepositoryScope;

final class MembershipDefinitionRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Tenant data-plane: branch-owned definition for pinned branch and resolved org only (NULL-branch rows excluded).
     */
    public function findInTenantScope(int $id, int $branchId, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        $sql = 'SELECT md.* FROM membership_definitions md WHERE md.id = ?
            AND md.branch_id IS NOT NULL AND md.branch_id = ?' . $frag['sql'];
        if (!$withTrashed) {
            $sql .= ' AND md.deleted_at IS NULL';
        }
        $params = array_merge([$id, $branchId], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Staff catalog list: branch-bound rows only (no cross-tenant global union in protected list).
     *
     * @return list<array<string, mixed>>
     */
    public function listInTenantScope(array $filters, int $branchId, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        $sql = 'SELECT md.* FROM membership_definitions md WHERE md.deleted_at IS NULL AND md.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND md.name LIKE ?';
            $params[] = $q;
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND md.status = ?';
            $params[] = $filters['status'];
        }
        $sql .= ' ORDER BY md.created_at DESC, md.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function countInTenantScope(array $filters, int $branchId): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        $sql = 'SELECT COUNT(*) AS c FROM membership_definitions md WHERE md.deleted_at IS NULL AND md.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND md.name LIKE ?';
            $params[] = $q;
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND md.status = ?';
            $params[] = $filters['status'];
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Active definitions assignable at a branch: branch-owned rows only.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveAssignableInTenantScope(int $branchId, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        $sql = 'SELECT md.id, md.name, md.duration_days, md.price FROM membership_definitions md
                WHERE md.deleted_at IS NULL AND md.status = ?
                  AND md.branch_id IS NOT NULL AND md.branch_id = ?' . $frag['sql'] . '
                ORDER BY md.name ASC LIMIT ' . $limit;
        $params = array_merge(['active', $branchId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Branch-owned definition in the resolved organization (non-null {@code branch_id} + org EXISTS).
     * Returns {@code null} when tenant org context is missing, not branch-derived, or row is not branch-owned in-org.
     *
     * Prefer {@see findInTenantScope()} when the issuance branch is known; {@see findForClientMembershipContext()}
     * when loading the definition attached to a {@code client_memberships} row.
     */
    public function findBranchOwnedInResolvedOrganization(int $id, bool $withTrashed = false): ?array
    {
        try {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        } catch (AccessDeniedException) {
            return null;
        }
        $sql = 'SELECT md.* FROM membership_definitions md WHERE md.id = ? AND md.branch_id IS NOT NULL' . $frag['sql'];
        if (!$withTrashed) {
            $sql .= ' AND md.deleted_at IS NULL';
        }
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Tenant-safe PK read: same as {@see findBranchOwnedInResolvedOrganization()} (no cross-org id guessing).
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        return $this->findBranchOwnedInResolvedOrganization($id, $withTrashed);
    }

    /**
     * Definition row for a membership FK: branch path uses {@see findInTenantScope()};
     * {@code cm.branch_id} NULL uses {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()} on the client anchor.
     */
    public function findForClientMembershipContext(int $definitionId, int $clientMembershipId): ?array
    {
        if ($definitionId <= 0 || $clientMembershipId <= 0) {
            return null;
        }
        $cm = $this->db->fetchOne(
            'SELECT id, branch_id, membership_definition_id FROM client_memberships WHERE id = ?',
            [$clientMembershipId]
        );
        if ($cm === null || (int) ($cm['membership_definition_id'] ?? 0) !== $definitionId) {
            return null;
        }
        $bid = isset($cm['branch_id']) && $cm['branch_id'] !== '' && $cm['branch_id'] !== null
            ? (int) $cm['branch_id']
            : 0;
        if ($bid > 0) {
            return $this->findInTenantScope($definitionId, $bid);
        }
        try {
            $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        } catch (AccessDeniedException) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT md.* FROM membership_definitions md
             INNER JOIN client_memberships cm ON cm.id = ? AND cm.membership_definition_id = md.id
             INNER JOIN clients c ON c.id = cm.client_id AND c.deleted_at IS NULL
             WHERE md.id = ? AND md.deleted_at IS NULL AND cm.branch_id IS NULL' . $frag['sql'],
            array_merge([$clientMembershipId, $definitionId], $frag['params'])
        ) ?: null;
    }

    /**
     * Fail-closed catalog list: requires branch-derived org context; {@code branch_scope=global} returns empty
     * (NULL-branch rows are not org-anchored in schema).
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        try {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        } catch (AccessDeniedException) {
            return [];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            return [];
        }
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT md.* FROM membership_definitions md WHERE md.deleted_at IS NULL' . $frag['sql'];
        $params = $frag['params'];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND md.name LIKE ?';
            $params[] = $q;
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND md.status = ?';
            $params[] = $filters['status'];
        }
        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND md.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $sql .= ' ORDER BY md.created_at DESC, md.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Active definitions assignable on an invoice: branch-owned rows for the invoice branch only.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveForInvoiceBranch(?int $branchId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        if ($branchId === null || $branchId <= 0) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        $sql = 'SELECT md.* FROM membership_definitions md
                WHERE md.deleted_at IS NULL AND md.status = ?
                  AND md.branch_id = ?' . $frag['sql'] . '
                ORDER BY md.name ASC, md.id ASC LIMIT ' . $limit;

        return $this->db->fetchAll($sql, array_merge(['active', $branchId], $frag['params']));
    }

    public function count(array $filters = []): int
    {
        try {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        } catch (AccessDeniedException) {
            return 0;
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            return 0;
        }
        $sql = 'SELECT COUNT(*) AS c FROM membership_definitions md WHERE md.deleted_at IS NULL' . $frag['sql'];
        $params = $frag['params'];
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND md.name LIKE ?';
            $params[] = $q;
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND md.status = ?';
            $params[] = $filters['status'];
        }
        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND md.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('membership_definitions', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function updateInTenantScope(int $id, int $branchId, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals[] = $branchId;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query(
            'UPDATE membership_definitions md SET ' . implode(', ', $cols) . ' WHERE md.id = ? AND md.branch_id = ?' . $frag['sql'],
            $vals
        );
    }

    public function updateForControlPlaneById(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE membership_definitions SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    public function softDeleteForControlPlaneById(int $id): void
    {
        $this->db->query('UPDATE membership_definitions SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Active definitions eligible for anonymous public commerce at a branch (global or same branch),
     * with explicit {@code public_online_eligible} and positive {@code price}.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicPurchasableForBranch(int $branchId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, name, description, duration_days, price, branch_id
                FROM membership_definitions
                WHERE deleted_at IS NULL
                  AND status = ?
                  AND public_online_eligible = 1
                  AND price IS NOT NULL
                  AND price > 0
                  AND branch_id = ?';
        $params = ['active', $branchId];
        $sql .= ' ORDER BY name ASC, id ASC LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Anonymous public purchase validation: branch-owned, active, online-eligible, priced (no org-context dependency).
     *
     * @return array<string, mixed>|null
     */
    public function findBranchOwnedPublicPurchasable(int $id, int $branchId): ?array
    {
        if ($id <= 0 || $branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM membership_definitions
             WHERE id = ?
               AND deleted_at IS NULL
               AND status = ?
               AND public_online_eligible = 1
               AND price IS NOT NULL AND price > 0
               AND branch_id = ?',
            [$id, 'active', $branchId]
        ) ?: null;
    }

    /** @return list<array> Active definitions for dropdown/assign (branch-owned in resolved org only when branch is set). */
    public function listActiveForBranch(?int $branchId): array
    {
        if ($branchId === null || $branchId <= 0) {
            return [];
        }
        try {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('md');
        } catch (AccessDeniedException) {
            return [];
        }
        $sql = 'SELECT md.id, md.name, md.duration_days, md.price FROM membership_definitions md
                WHERE md.deleted_at IS NULL AND md.status = ? AND md.branch_id = ?' . $frag['sql'] . '
                ORDER BY md.name ASC';
        $params = array_merge(['active', $branchId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'branch_id', 'name', 'description', 'duration_days', 'price',
            'billing_enabled', 'billing_interval_unit', 'billing_interval_count',
            'renewal_price', 'renewal_invoice_due_days', 'billing_auto_renew_enabled',
            'benefits_json',
            'status', 'public_online_eligible', 'created_by', 'updated_by', 'deleted_at',
        ];
        $out = array_intersect_key($data, array_flip($allowed));
        if (isset($out['benefits_json']) && is_array($out['benefits_json'])) {
            $out['benefits_json'] = json_encode($out['benefits_json']);
        }
        return $out;
    }
}
