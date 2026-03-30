<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Normalized product brands (CATALOG-CANONICAL-FOUNDATION-01). Legacy `products.brand` string remains for reads/search.
 * Duplicate names: live rows only, same branch_id scope, same TRIM(name)—enforced in ProductBrandService; uk_product_brands_branch_name is on raw name, not TRIM semantics.
 *
 * Tenant semantics (FOUNDATION-TENANCY slice):
 *
 * | Class | Entry points |
 * | --- | --- |
 * | **1–2. Branch-in-org ∪ org-global-null (operation branch)** | {@see findInTenantScope}, {@see listInTenantScope} — {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()} |
 * | **1–2. Branch-in-org ∪ org-global-null (org-has-live-branch)** | {@see findLiveInResolvedTenantCatalogScope}, {@see listSelectableForProductBranch}, scoped soft-delete, duplicate TRIM(name) family — {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()} |
 * | **3. Legacy / tooling** | {@see list}, {@see listDuplicateTrimmedNameGroups} — **no** org EXISTS |
 * | **4. Control-plane / id-only** | {@see find}, {@see update}, {@see softDelete}, {@see rowByIdIncludingDeleted} — caller must prove tenancy |
 */
final class ProductBrandRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Duplicate-name paths: live rows in **resolved-tenant** taxonomy catalog plus scope bucket
     * ({@code $scopeBranchId} null → global-null brands only; int → that {@code branch_id} only).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function whereLiveTrimmedNameInResolvedTenantCatalogScope(?int $scopeBranchId, string $trimmedName): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pb');
        if ($scopeBranchId === null) {
            $sql = 'pb.deleted_at IS NULL AND TRIM(pb.name) = ? AND (' . $union['sql'] . ') AND pb.branch_id IS NULL';
            $params = array_merge([$trimmedName], $union['params']);
        } else {
            $sql = 'pb.deleted_at IS NULL AND TRIM(pb.name) = ? AND (' . $union['sql'] . ') AND pb.branch_id = ?';
            $params = array_merge([$trimmedName], $union['params'], [$scopeBranchId]);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM product_brands WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );
    }

    /**
     * Tenant fail-closed: row must belong to resolved org — branch-owned via {@code branch_id}, or org-global ({@code branch_id IS NULL})
     * when {@code $operationBranchId} is a live branch in the same organization.
     */
    public function findInTenantScope(int $id, int $operationBranchId): ?array
    {
        if ($id <= 0 || $operationBranchId <= 0) {
            return null;
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pb', $operationBranchId);

        return $this->db->fetchOne(
            'SELECT pb.* FROM product_brands pb
             WHERE pb.id = ? AND pb.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$id], $union['params'])
        );
    }

    /**
     * Live row visible in resolved-tenant catalog scope (branch-owned in org or org-global with live-branch anchor).
     * For CLI/repair when session branch is unset but org context is branch-derived.
     */
    public function findLiveInResolvedTenantCatalogScope(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pb');

        return $this->db->fetchOne(
            'SELECT pb.* FROM product_brands pb
             WHERE pb.id = ? AND pb.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$id], $union['params'])
        );
    }

    /**
     * Row by id ignoring soft-delete (backfill anomaly detection).
     *
     * @return array<string, mixed>|null
     */
    public function rowByIdIncludingDeleted(int $id): ?array
    {
        return $this->db->fetchOne('SELECT id, branch_id, name, deleted_at FROM product_brands WHERE id = ?', [$id]);
    }

    /**
     * Lowest-id live row in scope with same TRIM(name) (canonical for backfill / duplicate rule).
     *
     * @return array<string, mixed>|null
     */
    public function findCanonicalLiveByScopeAndTrimmedName(?int $branchId, string $trimmedName): ?array
    {
        $trimmedName = trim($trimmedName);
        if ($trimmedName === '') {
            return null;
        }
        $w = $this->whereLiveTrimmedNameInResolvedTenantCatalogScope($branchId, $trimmedName);

        return $this->db->fetchOne(
            'SELECT pb.* FROM product_brands pb WHERE ' . $w['sql'] . ' ORDER BY pb.id ASC LIMIT 1',
            $w['params']
        );
    }

    /**
     * Another live row in the same scope with the same TRIM(name), optionally excluding one id (update checks).
     *
     * @return array<string, mixed>|null
     */
    public function findOtherLiveByScopeAndTrimmedName(?int $branchId, string $trimmedName, ?int $excludeId): ?array
    {
        $trimmedName = trim($trimmedName);
        if ($trimmedName === '') {
            return null;
        }
        $w = $this->whereLiveTrimmedNameInResolvedTenantCatalogScope($branchId, $trimmedName);
        $sql = 'SELECT pb.* FROM product_brands pb WHERE ' . $w['sql'];
        $params = $w['params'];
        if ($excludeId !== null) {
            $sql .= ' AND pb.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY pb.id ASC LIMIT 1';

        return $this->db->fetchOne($sql, $params);
    }

    public function countLiveByScopeAndTrimmedName(?int $branchId, string $trimmedName): int
    {
        $trimmedName = trim($trimmedName);
        if ($trimmedName === '') {
            return 0;
        }
        $w = $this->whereLiveTrimmedNameInResolvedTenantCatalogScope($branchId, $trimmedName);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM product_brands pb WHERE ' . $w['sql'],
            $w['params']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<int>
     */
    public function listLiveIdsByScopeAndTrimmedName(?int $branchId, string $trimmedName): array
    {
        $trimmedName = trim($trimmedName);
        if ($trimmedName === '') {
            return [];
        }
        $w = $this->whereLiveTrimmedNameInResolvedTenantCatalogScope($branchId, $trimmedName);
        $rows = $this->db->fetchAll(
            'SELECT pb.id FROM product_brands pb WHERE ' . $w['sql'] . ' ORDER BY pb.id ASC',
            $w['params']
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Active row in the same scope as the product: global product → global rows only; branch product → that branch only (no global reuse).
     *
     * @return array<string, mixed>|null
     */
    public function findActiveInProductScope(?int $productBranchId, string $trimmedName): ?array
    {
        return $this->findCanonicalLiveByScopeAndTrimmedName($productBranchId, $trimmedName);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?int $branchId = null): array
    {
        $sql = 'SELECT * FROM product_brands WHERE deleted_at IS NULL';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY sort_order, name';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Tenant taxonomy index: live rows visible for {@param $operationBranchId} (branch-scoped in org or org-global).
     */
    public function listInTenantScope(int $operationBranchId): array
    {
        if ($operationBranchId <= 0) {
            return [];
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pb', $operationBranchId);

        return $this->db->fetchAll(
            'SELECT pb.* FROM product_brands pb
             WHERE pb.deleted_at IS NULL AND (' . $union['sql'] . ')
             ORDER BY pb.sort_order, pb.name',
            $union['params']
        );
    }

    /**
     * Normalized brand selects on product forms: global product → global rows only; branch product → global + that branch.
     *
     * @return list<array<string, mixed>>
     */
    public function listSelectableForProductBranch(?int $productBranchId): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pb');
        $tenantVis = '(' . $union['sql'] . ')';
        $baseParams = $union['params'];
        if ($productBranchId === null) {
            $sql = 'SELECT pb.* FROM product_brands pb
                WHERE pb.deleted_at IS NULL AND pb.branch_id IS NULL AND ' . $tenantVis . '
                ORDER BY pb.sort_order, pb.name';

            return $this->db->fetchAll($sql, $baseParams);
        }
        $sql = 'SELECT pb.* FROM product_brands pb
            WHERE pb.deleted_at IS NULL AND (pb.branch_id IS NULL OR pb.branch_id = ?) AND ' . $tenantVis . '
            ORDER BY pb.sort_order, pb.name';

        return $this->db->fetchAll($sql, array_merge([$productBranchId], $baseParams));
    }

    public function create(array $data): int
    {
        $this->db->insert('product_brands', $this->normalize($data));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE product_brands SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * @deprecated Id-only WHERE — tooling only. Prefer {@see softDeleteLiveInResolvedTenantCatalogScope} for tenant repair apply paths.
     */
    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE product_brands SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * @return int Rows affected (0 or 1)
     */
    public function softDeleteLiveInResolvedTenantCatalogScope(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pb');
        $stmt = $this->db->query(
            'UPDATE product_brands pb SET pb.deleted_at = NOW()
             WHERE pb.id = ? AND pb.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$id], $union['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * Another non-deleted row with same branch scope and TRIM(name) (for uniqueness before INSERT/UPDATE).
     */
    public function findOtherByBranchAndName(?int $branchId, string $name, ?int $excludeId = null): ?array
    {
        return $this->findOtherLiveByScopeAndTrimmedName($branchId, $name, $excludeId);
    }

    /**
     * Report-only: non-deleted rows grouped by scope + trimmed name where more than one row exists.
     *
     * @return list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}>
     */
    /**
     * @deprecated Unscoped. Prefer {@see listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope}.
     */
    public function listDuplicateTrimmedNameGroups(): array
    {
        return $this->db->fetchAll(
            'SELECT branch_id, TRIM(name) AS trimmed_name, COUNT(*) AS cnt
             FROM product_brands
             WHERE deleted_at IS NULL
             GROUP BY branch_id, TRIM(name)
             HAVING COUNT(*) > 1'
        );
    }

    /**
     * @return list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}>
     */
    public function listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope(): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pb');

        return $this->db->fetchAll(
            'SELECT pb.branch_id, TRIM(pb.name) AS trimmed_name, COUNT(*) AS cnt
             FROM product_brands pb
             WHERE pb.deleted_at IS NULL
             AND (' . $union['sql'] . ')
             GROUP BY pb.branch_id, TRIM(pb.name)
             HAVING COUNT(*) > 1',
            $union['params']
        );
    }

    /**
     * Duplicate live groups: same scope + TRIM(name), more than one row (for canonical relink audit).
     *
     * @return list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}>
     */
    public function listDuplicateLiveBrandGroupsByScopeAndTrimmedName(): array
    {
        return $this->listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope();
    }

    /**
     * Ids of live duplicate rows excluding the canonical (lowest id), for a known duplicate group key.
     *
     * @return list<int>
     */
    public function listNoncanonicalLiveBrandIdsForDuplicateGroup(?int $branchId, string $trimmedName): array
    {
        $ids = $this->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
        if (count($ids) < 2) {
            return [];
        }

        return array_values(array_slice($ids, 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $allowed = ['name', 'sort_order', 'branch_id'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('branch_id', $out) && ($out['branch_id'] === '' || $out['branch_id'] === null)) {
            $out['branch_id'] = null;
        } elseif (array_key_exists('branch_id', $out)) {
            $out['branch_id'] = (int) $out['branch_id'];
        }

        return $out;
    }
}
