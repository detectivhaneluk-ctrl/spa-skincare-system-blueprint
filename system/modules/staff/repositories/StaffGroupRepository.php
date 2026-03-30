<?php

declare(strict_types=1);

namespace Modules\Staff\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * {@code staff_groups} tenancy contract:
 *
 * 1. **Strict branch-owned** — non-null {@code branch_id} and branch in branch-derived org
 *    ({@see OrganizationRepositoryScope::staffGroupVisibleFromBranchContextClause()} branch arm).
 * 2. **Org-global template (schema-limited)** — {@code branch_id IS NULL} rows: visible under the context branch’s org without a row-level
 *    org FK (see {@see OrganizationRepositoryScope::staffGroupVisibleFromBranchContextClause()}); **residual** multi-tenant risk on null rows.
 * 3. **Legacy/repair** — {@see list()} / {@see activeNameExists()} remain **unscoped** (class 4); prefer {@see listInTenantScope} /
 *    {@see activeNameExistsInTenantScope} from HTTP data-plane.
 * 4. **Control-plane / id-only** — {@see find()}, {@see update()}, {@see softDelete()}, pivot mutators: caller must authorize
 *    (e.g. {@see \Modules\Staff\Services\StaffGroupService::requireGroup} + {@see \Core\Branch\BranchContext}).
 */
final class StaffGroupRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function staffGroupTenantVisibilityFromBranch(int $branchContextId): array
    {
        return $this->orgScope->staffGroupVisibleFromBranchContextClause('sg', $branchContextId);
    }

    public function create(array $data): int
    {
        $this->db->insert('staff_groups', $this->normalizeGroup($data));
        return $this->db->lastInsertId();
    }

    /**
     * Id-only read — **no** tenant WHERE; caller must enforce scope.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        $sql = 'SELECT * FROM staff_groups WHERE id = ?';
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * Unscoped listing — **not** tenant-safe; prefer {@see listInTenantScope} for branch-derived requests.
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT * FROM staff_groups WHERE deleted_at IS NULL';
        $params = [];
        if (array_key_exists('branch_id', $filters)) {
            if ($filters['branch_id'] === null) {
                $sql .= ' AND branch_id IS NULL';
            } else {
                $sql .= ' AND branch_id = ?';
                $params[] = (int) $filters['branch_id'];
            }
        }
        if (!empty($filters['active'])) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY name ASC LIMIT ? OFFSET ?';
        $params[] = (int) $limit;
        $params[] = (int) $offset;
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Tenant data-plane index: visibility from {@code $branchContextId} + optional {@code branch_id} / {@code active} filters.
     *
     * @return list<array<string, mixed>>
     */
    public function listInTenantScope(int $branchContextId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $vis = $this->staffGroupTenantVisibilityFromBranch($branchContextId);
        $sql = 'SELECT * FROM staff_groups sg WHERE sg.deleted_at IS NULL AND (' . $vis['sql'] . ')';
        $params = $vis['params'];
        if (!empty($filters['active'])) {
            $sql .= ' AND sg.is_active = 1';
        }
        if (array_key_exists('branch_id', $filters)) {
            if ($filters['branch_id'] === null) {
                $sql .= ' AND sg.branch_id IS NULL';
            } else {
                $sql .= ' AND sg.branch_id = ?';
                $params[] = (int) $filters['branch_id'];
            }
        }
        $sql .= ' ORDER BY sg.name ASC LIMIT ? OFFSET ?';
        $params[] = (int) $limit;
        $params[] = (int) $offset;

        try {
            return $this->db->fetchAll($sql, $params);
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Id-only UPDATE — **no** tenant WHERE; caller must authorize.
     */
    public function update(int $id, array $data): void
    {
        $norm = $this->normalizeGroup($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn (string $k): string => $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE staff_groups SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE staff_groups SET deleted_at = NOW(), is_active = 0 WHERE id = ?', [$id]);
    }

    /**
     * Unscoped name collision check — **not** tenant-safe; prefer {@see activeNameExistsInTenantScope}.
     */
    public function activeNameExists(?int $branchId, string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM staff_groups WHERE deleted_at IS NULL AND is_active = 1 AND LOWER(name) = LOWER(?)';
        $params = [$name];
        if ($branchId === null) {
            $sql .= ' AND branch_id IS NULL';
        } else {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Active-name uniqueness among groups **visible** from {@code $branchContextId}, scoped to {@code $groupBranchId} bucket
     * (null = template / global-null bucket, int = branch-pinned bucket).
     */
    public function activeNameExistsInTenantScope(int $branchContextId, ?int $groupBranchId, string $name, ?int $excludeId = null): bool
    {
        $vis = $this->staffGroupTenantVisibilityFromBranch($branchContextId);
        $sql = 'SELECT 1 FROM staff_groups sg WHERE sg.deleted_at IS NULL AND sg.is_active = 1 AND LOWER(sg.name) = LOWER(?) AND (' . $vis['sql'] . ')';
        $params = [$name];
        $params = array_merge($params, $vis['params']);
        if ($groupBranchId === null) {
            $sql .= ' AND sg.branch_id IS NULL';
        } else {
            $sql .= ' AND sg.branch_id = ?';
            $params[] = $groupBranchId;
        }
        if ($excludeId !== null) {
            $sql .= ' AND sg.id != ?';
            $params[] = $excludeId;
        }

        try {
            return $this->db->fetchOne($sql, $params) !== null;
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Assignable groups for a service branch: global service ({@code $serviceBranchId} null) → null-{@code branch_id} groups only, org-gated;
     * branch service → {@see OrganizationRepositoryScope::staffGroupVisibleFromBranchContextClause()}.
     *
     * @return list<array<string, mixed>>
     */
    public function listAssignableForServiceBranch(?int $serviceBranchId, int $limit = 500): array
    {
        $lim = max(1, min(2000, $limit));
        if ($serviceBranchId === null || $serviceBranchId <= 0) {
            $gate = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
            $sql = 'SELECT * FROM staff_groups sg WHERE sg.deleted_at IS NULL AND sg.is_active = 1 AND sg.branch_id IS NULL'
                . $gate['sql']
                . ' ORDER BY sg.name ASC LIMIT ' . $lim;
            $params = $gate['params'];
        } else {
            $vis = $this->orgScope->staffGroupVisibleFromBranchContextClause('sg', $serviceBranchId);
            $sql = 'SELECT * FROM staff_groups sg WHERE sg.deleted_at IS NULL AND sg.is_active = 1 AND (' . $vis['sql'] . ')'
                . ' ORDER BY sg.name ASC LIMIT ' . $lim;
            $params = $vis['params'];
        }

        try {
            return $this->db->fetchAll($sql, $params);
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @param list<int> $ids unique positive integers
     *
     * Read-only drift audit for existing pivots: `system/scripts/verify_service_staff_group_pivot_drift_readonly.php` (SERVICE-STAFF-GROUP-PIVOT-DRIFT-AUDIT-01).
     */
    public function assertIdsAssignableToService(?int $serviceBranchId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            if ($serviceBranchId === null || $serviceBranchId <= 0) {
                $gate = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
                $rows = $this->db->fetchAll(
                    "SELECT sg.id, sg.branch_id FROM staff_groups sg WHERE sg.id IN ({$placeholders}) AND sg.deleted_at IS NULL AND sg.is_active = 1 AND sg.branch_id IS NULL"
                    . $gate['sql'],
                    array_merge($ids, $gate['params'])
                );
            } else {
                $vis = $this->orgScope->staffGroupVisibleFromBranchContextClause('sg', $serviceBranchId);
                $rows = $this->db->fetchAll(
                    "SELECT sg.id, sg.branch_id FROM staff_groups sg WHERE sg.id IN ({$placeholders}) AND sg.deleted_at IS NULL AND sg.is_active = 1 AND (" . $vis['sql'] . ')',
                    array_merge($ids, $vis['params'])
                );
            }
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                throw new \DomainException('Staff groups are not available.');
            }
            throw $e;
        }
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r['branch_id'];
        }
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                throw new \DomainException('Unknown or inaccessible staff group.');
            }
            $gb = $byId[$id];
            $gBranch = $gb !== null && $gb !== '' ? (int) $gb : null;
            if ($serviceBranchId === null || $serviceBranchId <= 0) {
                if ($gBranch !== null) {
                    throw new \DomainException('Staff group is not allowed for this service branch scope.');
                }
            } elseif ($gBranch !== null && $gBranch !== $serviceBranchId) {
                throw new \DomainException('Staff group does not match the service branch.');
            }
        }
    }

    /**
     * Subset of $ids assignable to a service on $serviceBranchId (active, non-deleted groups; same branch rules as {@see assertIdsAssignableToService}).
     *
     * @param list<int|mixed> $ids
     * @return list<int>
     */
    public function filterIdsAssignableToServiceBranch(?int $serviceBranchId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int) $v, $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            if ($serviceBranchId === null || $serviceBranchId <= 0) {
                $gate = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
                $rows = $this->db->fetchAll(
                    "SELECT sg.id, sg.branch_id FROM staff_groups sg WHERE sg.id IN ({$placeholders}) AND sg.deleted_at IS NULL AND sg.is_active = 1 AND sg.branch_id IS NULL"
                    . $gate['sql'],
                    array_merge($ids, $gate['params'])
                );
            } else {
                $vis = $this->orgScope->staffGroupVisibleFromBranchContextClause('sg', $serviceBranchId);
                $rows = $this->db->fetchAll(
                    "SELECT sg.id, sg.branch_id FROM staff_groups sg WHERE sg.id IN ({$placeholders}) AND sg.deleted_at IS NULL AND sg.is_active = 1 AND (" . $vis['sql'] . ')',
                    array_merge($ids, $vis['params'])
                );
            }
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                return [];
            }
            throw $e;
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $gb = $r['branch_id'];
            $gBranch = $gb !== null && $gb !== '' ? (int) $gb : null;
            if ($serviceBranchId === null || $serviceBranchId <= 0) {
                if ($gBranch === null) {
                    $out[] = $id;
                }
            } elseif ($gBranch === null || $gBranch === $serviceBranchId) {
                $out[] = $id;
            }
        }
        sort($out);

        return $out;
    }

    public function listMemberStaff(int $groupId): array
    {
        return $this->db->fetchAll(
            'SELECT sgm.staff_id, s.first_name, s.last_name, s.branch_id, s.is_active
             FROM staff_group_members sgm
             INNER JOIN staff s ON s.id = sgm.staff_id
             WHERE sgm.staff_group_id = ? AND s.deleted_at IS NULL
             ORDER BY s.last_name, s.first_name',
            [$groupId]
        );
    }

    public function hasMember(int $groupId, int $staffId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM staff_group_members WHERE staff_group_id = ? AND staff_id = ?',
            [$groupId, $staffId]
        ) !== null;
    }

    public function attachStaff(int $groupId, int $staffId, ?int $createdBy): int
    {
        $this->db->insert('staff_group_members', [
            'staff_group_id' => $groupId,
            'staff_id' => $staffId,
            'created_by' => $createdBy,
        ]);
        return $this->db->lastInsertId();
    }

    public function detachStaff(int $groupId, int $staffId): void
    {
        $this->db->query(
            'DELETE FROM staff_group_members WHERE staff_group_id = ? AND staff_id = ?',
            [$groupId, $staffId]
        );
    }

    /**
     * Whether the branch has any active (non-deleted, is_active) staff groups in the **resolved tenant org**
     * (branch-pinned rows must reference a branch in org; null-template rows require branch-derived org context).
     */
    public function hasActiveGroupsForBranch(?int $branchId): bool
    {
        $sql = 'SELECT 1 FROM staff_groups sg WHERE sg.deleted_at IS NULL AND sg.is_active = 1';
        $params = [];
        if ($branchId === null || $branchId <= 0) {
            $sql .= ' AND sg.branch_id IS NULL';
            $gate = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
            $sql .= $gate['sql'];
            $params = $gate['params'];
        } else {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('sg', 'branch_id');
            $sql .= ' AND sg.branch_id = ?' . $frag['sql'];
            $params = array_merge([$branchId], $frag['params']);
        }
        $sql .= ' LIMIT 1';

        try {
            return $this->db->fetchOne($sql, $params) !== null;
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Whether staff is a member of at least one active group for the given branch bucket, **tenant-gated** the same way as {@see hasActiveGroupsForBranch}.
     */
    public function isStaffInAnyActiveGroupForBranch(int $staffId, ?int $branchId): bool
    {
        $sql = 'SELECT 1 FROM staff_group_members sgm
                INNER JOIN staff_groups sg ON sg.id = sgm.staff_group_id
                WHERE sgm.staff_id = ? AND sg.deleted_at IS NULL AND sg.is_active = 1';
        $params = [$staffId];
        if ($branchId === null || $branchId <= 0) {
            $sql .= ' AND sg.branch_id IS NULL';
            $gate = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
            $sql .= $gate['sql'];
            $params = array_merge($params, $gate['params']);
        } else {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('sg', 'branch_id');
            $sql .= ' AND sg.branch_id = ?' . $frag['sql'];
            $params = array_merge($params, [$branchId], $frag['params']);
        }
        $sql .= ' LIMIT 1';

        try {
            return $this->db->fetchOne($sql, $params) !== null;
        } catch (\PDOException $e) {
            if ($this->isUndefinedTableSqlState($e)) {
                return false;
            }
            throw $e;
        }
    }

    private function isUndefinedTableSqlState(\PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? '') === '42S02';
    }

    private function normalizeGroup(array $data): array
    {
        $allowed = ['branch_id', 'name', 'description', 'is_active', 'created_by', 'updated_by'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('is_active', $out)) {
            $out['is_active'] = $out['is_active'] ? 1 : 0;
        }
        return $out;
    }
}
