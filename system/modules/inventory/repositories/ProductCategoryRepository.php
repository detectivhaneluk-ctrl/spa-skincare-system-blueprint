<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Normalized product categories (CATALOG-CANONICAL-FOUNDATION-01). Legacy `products.category` string remains for reads/search.
 * Duplicate names: live rows only, same branch_id scope, same TRIM(name)—enforced in ProductCategoryService; no DB unique on name.
 *
 * Tenant semantics (FOUNDATION-TENANCY slice):
 *
 * | Class | Entry points |
 * | --- | --- |
 * | **1–2. Branch-in-org ∪ org-global-null (operation branch)** | {@see findInTenantScope}, {@see listInTenantScope}, {@see mapByIdsForParentLabelLookupInTenantScope} — {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()} |
 * | **1–2. Branch-in-org ∪ org-global-null (org-has-live-branch)** | {@see listAllLiveInResolvedTenantCatalogScope}, {@see findLiveInResolvedTenantCatalogScope}, selectable lists, scoped soft-delete, duplicate TRIM(name) family, live child list/count, parent relink/clear UPDATEs, {@see clearChildParentLinks} — {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()} |
 * | **3. Legacy / tooling** | {@see findUnscopedLiveByIdForRepair}, {@see listUnscopedCatalogForRepair}, {@see mapByIdsForParentLabelLookupUnscopedForRepair}, unscoped graph/duplicate repair family — **no** org EXISTS |
 * | **4. Control-plane / id-only** | {@see update}, {@see softDelete}, {@see rowByIdIncludingDeleted} — caller must prove tenancy |
 */
final class ProductCategoryRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Duplicate-name + parent/child repair paths: live rows in **resolved-tenant** taxonomy catalog
     * ({@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()}) plus optional
     * scope bucket ({@code $scopeBranchId} null → global-null categories only; int → that branch id only).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function whereLiveTrimmedNameInResolvedTenantCatalogScope(?int $scopeBranchId, string $trimmedName): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');
        if ($scopeBranchId === null) {
            $sql = 'pc.deleted_at IS NULL AND TRIM(pc.name) = ? AND (' . $union['sql'] . ') AND pc.branch_id IS NULL';
            $params = array_merge([$trimmedName], $union['params']);
        } else {
            $sql = 'pc.deleted_at IS NULL AND TRIM(pc.name) = ? AND (' . $union['sql'] . ') AND pc.branch_id = ?';
            $params = array_merge([$trimmedName], $union['params'], [$scopeBranchId]);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function whereLiveChildOfParentInResolvedTenantCatalogScope(int $parentId): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');
        $sql = 'pc.parent_id = ? AND pc.deleted_at IS NULL AND (' . $union['sql'] . ')';
        $params = array_merge([$parentId], $union['params']);

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @deprecated Locked fail-closed to prevent ambiguous runtime reads. Use
     * {@see findInTenantScope} (tenant runtime) or {@see findUnscopedLiveByIdForRepair} (repair/control-plane).
     */
    public function find(int $id): ?array
    {
        throw new \LogicException('ProductCategoryRepository::find() is locked. Use findInTenantScope() or findUnscopedLiveByIdForRepair().');
    }

    /**
     * Explicit unscoped live-row lookup for repair/control-plane paths.
     *
     * @return array<string, mixed>|null
     */
    public function findUnscopedLiveByIdForRepair(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM product_categories WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );
    }

    /**
     * @see ProductBrandRepository::findInTenantScope()
     */
    public function findInTenantScope(int $id, int $operationBranchId): ?array
    {
        if ($id <= 0 || $operationBranchId <= 0) {
            return null;
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pc', $operationBranchId);

        return $this->db->fetchOne(
            'SELECT pc.* FROM product_categories pc
             WHERE pc.id = ? AND pc.deleted_at IS NULL AND (' . $union['sql'] . ')',
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
        return $this->db->fetchOne('SELECT id, branch_id, name, deleted_at FROM product_categories WHERE id = ?', [$id]);
    }

    /**
     * @deprecated Locked fail-closed to prevent ambiguous runtime reads. Use
     * {@see mapByIdsForParentLabelLookupInTenantScope} (tenant runtime) or
     * {@see mapByIdsForParentLabelLookupUnscopedForRepair} (repair/control-plane).
     */
    public function mapByIdsForParentLabelLookup(array $ids): array
    {
        throw new \LogicException('ProductCategoryRepository::mapByIdsForParentLabelLookup() is locked. Use mapByIdsForParentLabelLookupInTenantScope() or mapByIdsForParentLabelLookupUnscopedForRepair().');
    }

    /**
     * Batch load rows for repair/control-plane parent-label workflows (includes soft-deleted parents).
     *
     * @param list<int> $ids
     * @return array<int, array{id: int|string, name: string, deleted_at: string|null}>
     */
    public function mapByIdsForParentLabelLookupUnscopedForRepair(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, name, deleted_at FROM product_categories WHERE id IN ({$placeholders})",
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * Batch parent labels for taxonomy index/show — same rows as {@see mapByIdsForParentLabelLookup} but org-scoped
     * (branch-owned or org-global visible from {@param $operationBranchId}).
     *
     * @param list<int> $ids
     * @return array<int, array{id: int|string, name: string, deleted_at: string|null}>
     */
    public function mapByIdsForParentLabelLookupInTenantScope(array $ids, int $operationBranchId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === [] || $operationBranchId <= 0) {
            return [];
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pc', $operationBranchId);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT pc.id, pc.name, pc.deleted_at FROM product_categories pc
             WHERE pc.id IN ({$placeholders}) AND ({$union['sql']})",
            array_merge($ids, $union['params'])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
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
            'SELECT pc.* FROM product_categories pc WHERE ' . $w['sql'] . ' ORDER BY pc.id ASC LIMIT 1',
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
        $sql = 'SELECT pc.* FROM product_categories pc WHERE ' . $w['sql'];
        $params = $w['params'];
        if ($excludeId !== null) {
            $sql .= ' AND pc.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY pc.id ASC LIMIT 1';

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
            'SELECT COUNT(*) AS c FROM product_categories pc WHERE ' . $w['sql'],
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
            'SELECT pc.id FROM product_categories pc WHERE ' . $w['sql'] . ' ORDER BY pc.id ASC',
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
     * @deprecated Locked fail-closed to prevent ambiguous runtime reads. Use
     * {@see listInTenantScope} (tenant runtime) or {@see listUnscopedCatalogForRepair} (repair/control-plane).
     */
    public function list(?int $branchId = null): array
    {
        throw new \LogicException('ProductCategoryRepository::list() is locked. Use listInTenantScope() or listUnscopedCatalogForRepair().');
    }

    /**
     * Explicit unscoped category catalog listing for repair/control-plane paths.
     * Tenant runtime callers must use {@see listInTenantScope}.
     *
     * @return list<array<string, mixed>>
     */
    public function listUnscopedCatalogForRepair(?int $branchId = null): array
    {
        if ($branchId !== null && $branchId <= 0) {
            return [];
        }
        $sql = 'SELECT * FROM product_categories WHERE deleted_at IS NULL';
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
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pc', $operationBranchId);

        return $this->db->fetchAll(
            'SELECT pc.* FROM product_categories pc
             WHERE pc.deleted_at IS NULL AND (' . $union['sql'] . ')
             ORDER BY pc.sort_order, pc.name',
            $union['params']
        );
    }

    /**
     * All live product categories in the resolved tenant organization (branch-owned rows + org-global catalog rows).
     *
     * @return list<array<string, mixed>>
     */
    public function listAllLiveInResolvedTenantCatalogScope(): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');

        return $this->db->fetchAll(
            'SELECT pc.* FROM product_categories pc
             WHERE pc.deleted_at IS NULL AND (' . $union['sql'] . ')
             ORDER BY pc.sort_order, pc.name',
            $union['params']
        );
    }

    /**
     * Live row by id when it belongs to the resolved tenant catalog scope (same predicate as {@see listAllLiveInResolvedTenantCatalogScope}).
     *
     * @return array<string, mixed>|null
     */
    public function findLiveInResolvedTenantCatalogScope(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');

        return $this->db->fetchOne(
            'SELECT pc.* FROM product_categories pc
             WHERE pc.id = ? AND pc.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$id], $union['params'])
        );
    }

    /**
     * Normalized category selects on product forms: global product → global rows only; branch product → global + that branch.
     *
     * @return list<array<string, mixed>>
     */
    public function listSelectableForProductBranch(?int $productBranchId): array
    {
        if ($productBranchId !== null && $productBranchId <= 0) {
            return [];
        }
        if ($productBranchId === null) {
            return $this->listSelectableOrgGlobalOnlyInResolvedTenantCatalog();
        }

        return $this->listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog($productBranchId);
    }

    /**
     * Parent dropdown on category create/edit: global category → global parents only; branch category → global + that branch.
     *
     * @return list<array<string, mixed>>
     */
    public function listSelectableAsParentForCategoryBranch(?int $categoryBranchId): array
    {
        if ($categoryBranchId !== null && $categoryBranchId <= 0) {
            return [];
        }
        if ($categoryBranchId === null) {
            return $this->listSelectableOrgGlobalOnlyInResolvedTenantCatalog();
        }

        return $this->listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog($categoryBranchId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSelectableOrgGlobalOnlyInResolvedTenantCatalog(): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');

        return $this->db->fetchAll(
            'SELECT pc.* FROM product_categories pc
             WHERE pc.deleted_at IS NULL AND pc.branch_id IS NULL AND (' . $union['sql'] . ')
             ORDER BY pc.sort_order, pc.name',
            $union['params']
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSelectableBranchOwnedOrOrgGlobalInResolvedTenantCatalog(int $scopeBranchId): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');

        return $this->db->fetchAll(
            'SELECT pc.* FROM product_categories pc
             WHERE pc.deleted_at IS NULL AND (pc.branch_id IS NULL OR pc.branch_id = ?) AND (' . $union['sql'] . ')
             ORDER BY pc.sort_order, pc.name',
            array_merge([$scopeBranchId], $union['params'])
        );
    }

    /**
     * @deprecated Locked fail-closed to prevent ambiguous runtime reads. Use
     * {@see listLiveForParentGraphAuditInResolvedTenantCatalogScope} (tenant runtime) or
     * {@see listLiveForParentGraphAuditUnscopedForRepair} (repair/control-plane).
     */
    public function listLiveForParentGraphAudit(): array
    {
        throw new \LogicException('ProductCategoryRepository::listLiveForParentGraphAudit() is locked. Use listLiveForParentGraphAuditInResolvedTenantCatalogScope() or listLiveForParentGraphAuditUnscopedForRepair().');
    }

    /**
     * Live rows for unscoped repair/control-plane parent-graph and cycle audits (ordered by id).
     *
     * @return list<array<string, mixed>>
     */
    public function listLiveForParentGraphAuditUnscopedForRepair(): array
    {
        return $this->db->fetchAll(
            'SELECT id, parent_id, name, branch_id FROM product_categories WHERE deleted_at IS NULL ORDER BY id ASC'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLiveForParentGraphAuditInResolvedTenantCatalogScope(): array
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');

        return $this->db->fetchAll(
            'SELECT pc.id, pc.parent_id, pc.name, pc.branch_id FROM product_categories pc
             WHERE pc.deleted_at IS NULL AND (' . $union['sql'] . ')
             ORDER BY pc.id ASC',
            $union['params']
        );
    }

    public function getAnyLiveBranchIdForResolvedTenantOrganization(): ?int
    {
        return $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
    }

    public function create(array $data): int
    {
        $this->db->insert('product_categories', $this->normalize($data));

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
        $this->db->query('UPDATE product_categories SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * @deprecated Id-only WHERE — tooling only. Prefer {@see softDeleteLiveInResolvedTenantCatalogScope} for tenant repair apply paths.
     */
    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE product_categories SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Soft-delete a live category row only if it is visible in resolved-tenant catalog scope (branch-owned in org or org-global).
     *
     * @return int Rows affected (0 or 1)
     */
    public function softDeleteLiveInResolvedTenantCatalogScope(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');
        $stmt = $this->db->query(
            'UPDATE product_categories pc SET pc.deleted_at = NOW()
             WHERE pc.id = ? AND pc.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$id], $union['params'])
        );

        return $stmt->rowCount();
    }

    public function countLiveChildCategoriesWithParentId(int $parentId): int
    {
        $w = $this->whereLiveChildOfParentInResolvedTenantCatalogScope($parentId);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM product_categories pc WHERE ' . $w['sql'],
            $w['params']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Live rows with parent_id = {@param $parentId} (duplicate parent relink audit/apply).
     *
     * @return list<array<string, mixed>>
     */
    public function listLiveChildrenByParentId(int $parentId): array
    {
        $w = $this->whereLiveChildOfParentInResolvedTenantCatalogScope($parentId);

        return $this->db->fetchAll(
            'SELECT pc.id, pc.name, pc.parent_id, pc.branch_id FROM product_categories pc WHERE ' . $w['sql'] . ' ORDER BY pc.id ASC',
            $w['params']
        );
    }

    /**
     * Atomically set parent_id when the row is still live and still has the expected parent (apply-time guard).
     *
     * @return int Rows updated (0 or 1)
     */
    public function reassignParentIdForLiveCategoryIfMatches(int $childId, int $expectedParentId, int $newParentId): int
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');
        $stmt = $this->db->query(
            'UPDATE product_categories pc SET pc.parent_id = ?
             WHERE pc.id = ? AND pc.deleted_at IS NULL AND pc.parent_id = ? AND (' . $union['sql'] . ')',
            array_merge([$newParentId, $childId, $expectedParentId], $union['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * Clear parent_id only when the live row still has the expected FK (tree integrity repair).
     *
     * @return int Rows updated (0 or 1)
     */
    public function clearParentIdForLiveCategoryIfParentMatches(int $categoryId, int $expectedParentId): int
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');
        $stmt = $this->db->query(
            'UPDATE product_categories pc SET pc.parent_id = NULL
             WHERE pc.id = ? AND pc.deleted_at IS NULL AND pc.parent_id = ? AND (' . $union['sql'] . ')',
            array_merge([$categoryId, $expectedParentId], $union['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * Detach **tenant-visible** live children before soft-deleting a parent so trees do not reference deleted rows.
     */
    public function clearChildParentLinks(int $parentId): void
    {
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');
        $this->db->query(
            'UPDATE product_categories pc SET pc.parent_id = NULL
             WHERE pc.parent_id = ? AND pc.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$parentId], $union['params'])
        );
    }

    /**
     * @deprecated Locked fail-closed to prevent ambiguous runtime reads. Use
     * {@see ancestorChainContainsIdInTenantScope}, {@see ancestorChainContainsIdInResolvedTenantCatalogScope}, or
     * {@see ancestorChainContainsIdUnscopedForRepair}.
     */
    public function ancestorChainContainsId(?int $startParentId, int $needleId): bool
    {
        throw new \LogicException('ProductCategoryRepository::ancestorChainContainsId() is locked. Use ancestorChainContainsIdInTenantScope(), ancestorChainContainsIdInResolvedTenantCatalogScope(), or ancestorChainContainsIdUnscopedForRepair().');
    }

    /**
     * Unscoped repair/control-plane ancestor walk: true when walking from {@param $startParentId} reaches {@param $needleId}.
     */
    public function ancestorChainContainsIdUnscopedForRepair(?int $startParentId, int $needleId): bool
    {
        if ($startParentId === null) {
            return false;
        }
        $current = $startParentId;
        $guard = 0;
        while ($current !== null && $guard++ < 64) {
            if ($current === $needleId) {
                return true;
            }
            $row = $this->findUnscopedLiveByIdForRepair($current);
            if ($row === null) {
                return false;
            }
            $pb = $row['parent_id'] ?? null;
            $current = ($pb !== null && $pb !== '') ? (int) $pb : null;
        }

        return false;
    }

    /**
     * Ancestor walk with each hop via {@see findLiveInResolvedTenantCatalogScope} (branch-derived org context).
     */
    public function ancestorChainContainsIdInResolvedTenantCatalogScope(?int $startParentId, int $needleId): bool
    {
        if ($startParentId === null) {
            return false;
        }
        $current = $startParentId;
        $guard = 0;
        while ($current !== null && $guard++ < 64) {
            if ($current === $needleId) {
                return true;
            }
            $row = $this->findLiveInResolvedTenantCatalogScope($current);
            if ($row === null) {
                return false;
            }
            $pb = $row['parent_id'] ?? null;
            $current = ($pb !== null && $pb !== '') ? (int) $pb : null;
        }

        return false;
    }

    /**
     * Same as {@see ancestorChainContainsIdUnscopedForRepair} but each hop loads via {@see findInTenantScope} (tenant data-plane).
     */
    public function ancestorChainContainsIdInTenantScope(?int $startParentId, int $needleId, int $operationBranchId): bool
    {
        if ($startParentId === null || $operationBranchId <= 0) {
            return false;
        }
        $current = $startParentId;
        $guard = 0;
        while ($current !== null && $guard++ < 64) {
            if ($current === $needleId) {
                return true;
            }
            $row = $this->findInTenantScope($current, $operationBranchId);
            if ($row === null) {
                return false;
            }
            $pb = $row['parent_id'] ?? null;
            $current = ($pb !== null && $pb !== '') ? (int) $pb : null;
        }

        return false;
    }

    /**
     * @deprecated Locked fail-closed to prevent ambiguous runtime reads. Use
     * {@see listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope} (tenant runtime) or
     * {@see listDuplicateTrimmedNameGroupsUnscopedForRepair} (repair/control-plane).
     */
    public function listDuplicateTrimmedNameGroups(): array
    {
        throw new \LogicException('ProductCategoryRepository::listDuplicateTrimmedNameGroups() is locked. Use listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope() or listDuplicateTrimmedNameGroupsUnscopedForRepair().');
    }

    /**
     * Report-only unscoped duplicate-name groups for repair/control-plane.
     *
     * @return list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}>
     */
    public function listDuplicateTrimmedNameGroupsUnscopedForRepair(): array
    {
        return $this->db->fetchAll(
            'SELECT branch_id, TRIM(name) AS trimmed_name, COUNT(*) AS cnt
             FROM product_categories
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
        $union = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc');

        return $this->db->fetchAll(
            'SELECT pc.branch_id, TRIM(pc.name) AS trimmed_name, COUNT(*) AS cnt
             FROM product_categories pc
             WHERE pc.deleted_at IS NULL
             AND (' . $union['sql'] . ')
             GROUP BY pc.branch_id, TRIM(pc.name)
             HAVING COUNT(*) > 1',
            $union['params']
        );
    }

    /**
     * Duplicate live groups: same scope + TRIM(name), more than one row (for canonical relink audit).
     *
     * @return list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}>
     */
    public function listDuplicateLiveCategoryGroupsByScopeAndTrimmedName(): array
    {
        return $this->listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope();
    }

    /**
     * Ids of live duplicate rows excluding the canonical (lowest id), for a known duplicate group key.
     *
     * @return list<int>
     */
    public function listNoncanonicalLiveCategoryIdsForDuplicateGroup(?int $branchId, string $trimmedName): array
    {
        $ids = $this->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
        if (count($ids) < 2) {
            return [];
        }

        return array_values(array_slice($ids, 1));
    }

    /**
     * @throws \InvalidArgumentException when parent is missing, self-parent, or introduces a cycle
     */
    public function assertValidParentAssignment(?int $categoryId, ?int $parentId, int $operationBranchId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($operationBranchId <= 0) {
            throw new \InvalidArgumentException('Branch context is required for parent product category validation.');
        }
        if ($parentId <= 0) {
            throw new \InvalidArgumentException('Invalid parent product category.');
        }
        if ($categoryId !== null && $parentId === $categoryId) {
            throw new \InvalidArgumentException('Product category cannot be its own parent.');
        }
        $parent = $this->findInTenantScope($parentId, $operationBranchId);
        if ($parent === null) {
            throw new \InvalidArgumentException('Parent product category not found.');
        }
        if ($categoryId !== null && $this->ancestorChainContainsIdInTenantScope($parentId, $categoryId, $operationBranchId)) {
            throw new \InvalidArgumentException('Invalid parent: would create a cycle in product categories.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $allowed = ['name', 'sort_order', 'branch_id', 'parent_id'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('parent_id', $out)) {
            if ($out['parent_id'] === '' || $out['parent_id'] === null) {
                $out['parent_id'] = null;
            } else {
                $out['parent_id'] = (int) $out['parent_id'];
            }
        }

        return $out;
    }
}
