<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Tenant catalog semantics (FOUNDATION-TENANCY slice — method → contract class):
 *
 * | Class | Entry points |
 * | --- | --- |
 * | **1. Strict branch-owned** | {@see findInTenantScope}, {@see findLockedInTenantScope}, {@see listInTenantScope}, {@see countInTenantScope}, tenant-scoped writes; **search + taxonomy substring filters** use {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()} on {@code pc}/{@code pb} anchored to the list/count operation branch |
 * | **2. Org-global but safe** | {@see findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg}, {@see listActiveOrgGlobalCatalogInResolvedOrg} — {@code branch_id IS NULL} + org live-branch EXISTS |
 * | **1–2. Resolved-tenant full product catalog** | {@see detachActiveProductsFromCategory}, {@see detachActiveProductsFromBrand}, {@see countActiveProductsReferencingCategoryIds}, {@see countActiveProductsReferencingBrandIds}, {@see relinkActiveProductCategoryIdsToCanonical}, {@see relinkActiveProductBrandIdsToCanonical} — {@see resolvedTenantCatalogProductVisibilityClause()} (same as org-has taxonomy union on {@code products}) |
 * | **3. Null-branch / legacy / tooling** | {@see find}, {@see findLocked}, {@see list}, {@see listActiveForUnifiedCatalog}, {@see count} — **no** org scope; migration/repair only — **tenant modules must not call** (FND-TNT-20 readonly gate). **Resolved-catalog repair:** {@see updateTaxonomyFkPatchInResolvedTenantCatalog} (FND-TNT-21) |
 * | **4. Control-plane unscoped** | {@see update}, {@see softDelete} — id-only; **tenant modules must not call** (FND-TNT-21 readonly gate) |
 *
 * Branch **∪** org-global-null **product** reads for stock and unified catalog use
 * {@see OrganizationRepositoryScope::productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause()}.
 * **Stock on-hand write:** {@see updateStockQuantityForStockMutationInResolvedOrg} — same union as
 * {@see findLockedForStockMutationInResolvedOrg} (FND-TNT-24). **Generic tenant/catalog UPDATE** must not accept
 * {@code stock_quantity} — only {@see normalizeForCreate} includes it for INSERT; {@see normalizeForTenantScopedProductUpdate}
 * is used by {@see updateInTenantScope} and deprecated {@see update} (FND-TNT-25).
 */
final class ProductRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    )
    {
    }

    /**
     * @deprecated Tooling / migration only — no org scope. Prefer {@see findInTenantScope} or org-scoped settlement/catalog helpers.
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        $sql = 'SELECT * FROM products WHERE id = ?';
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }
        return $this->db->fetchOne($sql, [$id]);
    }

    /**
     * @deprecated Tooling only — no org scope. Prefer {@see findLockedInTenantScope} / {@see findLockedForStockMutationInResolvedOrg}.
     *
     * Same row as {@see find} but locks the product for update (caller must hold an open transaction).
     */
    public function findLocked(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            [$id]
        );
    }

    public function findInTenantScope(int $id, int $branchId, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT p.* FROM products p WHERE p.id = ? AND p.branch_id = ?';
        if (!$withTrashed) {
            $sql .= ' AND p.deleted_at IS NULL';
        }
        $sql .= $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id, $branchId], $frag['params']));
    }

    public function findLockedInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT p.* FROM products p
                WHERE p.id = ? AND p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'] . ' FOR UPDATE';

        return $this->db->fetchOne($sql, array_merge([$id, $branchId], $frag['params']));
    }

    /**
     * Lock a product for stock mutation from {@code $operationBranchId}: branch-scoped row matching that branch, or
     * org-global catalog row ({@code p.branch_id IS NULL}) when the operation branch belongs to the resolved org.
     * Aligns with {@see \Modules\Inventory\Services\InvoiceProductStockBranchContract} (branch invoice + global SKU).
     */
    public function findLockedForStockMutationInResolvedOrg(int $id, int $operationBranchId): ?array
    {
        if ($operationBranchId <= 0) {
            return null;
        }
        $union = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('p', $operationBranchId);
        $sql = 'SELECT p.* FROM products p
            WHERE p.id = ? AND p.deleted_at IS NULL AND (' . $union['sql'] . ') FOR UPDATE';

        return $this->db->fetchOne(
            $sql,
            array_merge([$id], $union['params'])
        );
    }

    /**
     * Non-locking read with the same branch/global org scope as {@see findLockedForStockMutationInResolvedOrg}.
     */
    public function findReadableForStockMutationInResolvedOrg(int $id, int $operationBranchId): ?array
    {
        if ($operationBranchId <= 0) {
            return null;
        }
        $union = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('p', $operationBranchId);
        $sql = 'SELECT p.* FROM products p
            WHERE p.id = ? AND p.deleted_at IS NULL AND (' . $union['sql'] . ')';

        return $this->db->fetchOne(
            $sql,
            array_merge([$id], $union['params'])
        );
    }

    /**
     * Set {@code products.stock_quantity} on the row selected by the same visibility as
     * {@see findLockedForStockMutationInResolvedOrg} (branch-owned ∪ org-global-null catalog for {@code $operationBranchId}).
     * Intended for use in the same transaction, immediately after that lock.
     *
     * @return int PDO row count (driver-dependent when value is unchanged; callers typically do not assert {@code 1})
     */
    public function updateStockQuantityForStockMutationInResolvedOrg(int $productId, int $operationBranchId, float $newStockQuantity): int
    {
        if ($productId <= 0 || $operationBranchId <= 0) {
            return 0;
        }
        $union = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('p', $operationBranchId);
        $stmt = $this->db->query(
            'UPDATE products p SET p.stock_quantity = ? WHERE p.id = ? AND p.deleted_at IS NULL AND (' . $union['sql'] . ')',
            array_merge([$newStockQuantity, $productId], $union['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * HQ invoice path: live org-global product ({@code branch_id IS NULL}) in the resolved tenant organization.
     */
    public function findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $orgHas = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();

        return $this->db->fetchOne(
            'SELECT p.* FROM products p
             WHERE p.id = ? AND p.deleted_at IS NULL AND p.branch_id IS NULL' . $orgHas['sql'],
            array_merge([$id], $orgHas['params'])
        );
    }

    /**
     * Non-locking product row for invoice line validation / stock-branch contract: branch invoice uses
     * {@see findReadableForStockMutationInResolvedOrg}; HQ ({@code null} branch) uses {@see findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg}.
     */
    public function findForInvoiceProductLineAssignmentContractInResolvedOrg(int $productId, ?int $invoiceBranchId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        if ($invoiceBranchId !== null && $invoiceBranchId > 0) {
            return $this->findReadableForStockMutationInResolvedOrg($productId, $invoiceBranchId);
        }

        return $this->findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg($productId);
    }

    /**
     * @deprecated No org EXISTS — cross-tenant leak risk when used under tenant runtime. Prefer {@see listInTenantScope} or
     * {@see listActiveForUnifiedCatalogInResolvedOrg} / {@see listActiveOrgGlobalCatalogInResolvedOrg}.
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT * FROM products WHERE deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            [$searchSql, $searchParams] = $this->genericSearchCondition($q);
            $sql .= $searchSql;
            $params = array_merge($params, $searchParams);
        }
        if (!empty($filters['taxonomy_category_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_category_substring']) . '%';
            $sql .= ' AND (
                EXISTS (
                    SELECT 1 FROM product_categories pc
                    WHERE pc.id = products.product_category_id AND pc.deleted_at IS NULL AND pc.name LIKE ?
                )
                OR (products.category IS NOT NULL AND products.category LIKE ?)
            )';
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($filters['taxonomy_brand_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_brand_substring']) . '%';
            $sql .= ' AND (
                EXISTS (
                    SELECT 1 FROM product_brands pb
                    WHERE pb.id = products.product_brand_id AND pb.deleted_at IS NULL AND pb.name LIKE ?
                )
                OR (products.brand IS NOT NULL AND products.brand LIKE ?)
            )';
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($filters['product_type'])) {
            $sql .= ' AND product_type = ?';
            $params[] = $filters['product_type'];
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = (int) (bool) $filters['is_active'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND branch_id IS NULL';
        } elseif (!empty($filters['branch_union_global']) && array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = (int) $filters['branch_id'];
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $sql .= ' ORDER BY name, sku LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function listInTenantScope(array $filters = [], int $branchId = 0, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT p.* FROM products p WHERE p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            [$searchSql, $searchParams] = $this->genericSearchConditionForAlias('p', $q, $branchId);
            $sql .= $searchSql;
            $params = array_merge($params, $searchParams);
        }
        if (!empty($filters['taxonomy_category_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_category_substring']) . '%';
            $uPc = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pc', $branchId);
            $sql .= ' AND EXISTS (
                SELECT 1 FROM product_categories pc
                WHERE pc.id = p.product_category_id AND pc.deleted_at IS NULL AND pc.name LIKE ?
                AND (' . $uPc['sql'] . ')
            )';
            $params[] = $q;
            $params = array_merge($params, $uPc['params']);
        }
        if (!empty($filters['taxonomy_brand_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_brand_substring']) . '%';
            $uPb = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pb', $branchId);
            $sql .= ' AND EXISTS (
                SELECT 1 FROM product_brands pb
                WHERE pb.id = p.product_brand_id AND pb.deleted_at IS NULL AND pb.name LIKE ?
                AND (' . $uPb['sql'] . ')
            )';
            $params[] = $q;
            $params = array_merge($params, $uPb['params']);
        }
        if (!empty($filters['product_type'])) {
            $sql .= ' AND p.product_type = ?';
            $params[] = $filters['product_type'];
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $sql .= ' AND p.is_active = ?';
            $params[] = (int) (bool) $filters['is_active'];
        }

        $sql .= ' ORDER BY p.name, p.sku LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @deprecated No org scope. Use {@see listActiveForUnifiedCatalogInResolvedOrg} when {@code $branchId} is a tenant branch, or
     * {@see listActiveOrgGlobalCatalogInResolvedOrg} for HQ / null-branch catalog slices.
     *
     * Active products for unified catalog reads: global ∪ branch when {@param $branchId} is set (matches service list overlay).
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveForUnifiedCatalog(?int $branchId, int $limit = 10000): array
    {
        $limit = max(1, min(20000, $limit));
        $sql = 'SELECT * FROM products WHERE deleted_at IS NULL AND is_active = 1';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY name, sku LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Active catalog for a branch-context invoice/cashier: branch-owned rows in the resolved org plus org-global SKUs
     * ({@code branch_id IS NULL}), same visibility as {@see findReadableForStockMutationInResolvedOrg}.
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveForUnifiedCatalogInResolvedOrg(int $operationBranchId, int $limit = 10000): array
    {
        if ($operationBranchId <= 0) {
            return [];
        }
        $limit = max(1, min(20000, $limit));
        $union = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('p', $operationBranchId);
        $sql = 'SELECT p.* FROM products p
            WHERE p.deleted_at IS NULL AND p.is_active = 1 AND (' . $union['sql'] . ')
            ORDER BY p.name, p.sku LIMIT ' . $limit;

        return $this->db->fetchAll(
            $sql,
            $union['params']
        );
    }

    /**
     * HQ / null-branch catalog: active org-global products only (resolved org must have a live branch).
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveOrgGlobalCatalogInResolvedOrg(int $limit = 10000): array
    {
        $limit = max(1, min(20000, $limit));
        $orgHas = $this->orgScope->resolvedTenantOrganizationHasLiveBranchExistsClause();
        $sql = 'SELECT p.* FROM products p
            WHERE p.deleted_at IS NULL AND p.is_active = 1 AND p.branch_id IS NULL' . $orgHas['sql'] . '
            ORDER BY p.name, p.sku LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $orgHas['params']);
    }

    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            [$searchSql, $searchParams] = $this->genericSearchCondition($q);
            $sql .= $searchSql;
            $params = array_merge($params, $searchParams);
        }
        if (!empty($filters['taxonomy_category_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_category_substring']) . '%';
            $sql .= ' AND (
                EXISTS (
                    SELECT 1 FROM product_categories pc
                    WHERE pc.id = products.product_category_id AND pc.deleted_at IS NULL AND pc.name LIKE ?
                )
                OR (products.category IS NOT NULL AND products.category LIKE ?)
            )';
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($filters['taxonomy_brand_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_brand_substring']) . '%';
            $sql .= ' AND (
                EXISTS (
                    SELECT 1 FROM product_brands pb
                    WHERE pb.id = products.product_brand_id AND pb.deleted_at IS NULL AND pb.name LIKE ?
                )
                OR (products.brand IS NOT NULL AND products.brand LIKE ?)
            )';
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($filters['product_type'])) {
            $sql .= ' AND product_type = ?';
            $params[] = $filters['product_type'];
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $sql .= ' AND is_active = ?';
            $params[] = (int) (bool) $filters['is_active'];
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND branch_id IS NULL';
        } elseif (!empty($filters['branch_union_global']) && array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = (int) $filters['branch_id'];
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function countInTenantScope(array $filters = [], int $branchId = 0): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT COUNT(*) AS c FROM products p WHERE p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            [$searchSql, $searchParams] = $this->genericSearchConditionForAlias('p', $q, $branchId);
            $sql .= $searchSql;
            $params = array_merge($params, $searchParams);
        }
        if (!empty($filters['taxonomy_category_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_category_substring']) . '%';
            $uPc = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pc', $branchId);
            $sql .= ' AND EXISTS (
                SELECT 1 FROM product_categories pc
                WHERE pc.id = p.product_category_id AND pc.deleted_at IS NULL AND pc.name LIKE ?
                AND (' . $uPc['sql'] . ')
            )';
            $params[] = $q;
            $params = array_merge($params, $uPc['params']);
        }
        if (!empty($filters['taxonomy_brand_substring'])) {
            $q = '%' . trim((string) $filters['taxonomy_brand_substring']) . '%';
            $uPb = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pb', $branchId);
            $sql .= ' AND EXISTS (
                SELECT 1 FROM product_brands pb
                WHERE pb.id = p.product_brand_id AND pb.deleted_at IS NULL AND pb.name LIKE ?
                AND (' . $uPb['sql'] . ')
            )';
            $params[] = $q;
            $params = array_merge($params, $uPb['params']);
        }
        if (!empty($filters['product_type'])) {
            $sql .= ' AND p.product_type = ?';
            $params[] = $filters['product_type'];
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $sql .= ' AND p.is_active = ?';
            $params[] = (int) (bool) $filters['is_active'];
        }

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('products', $this->normalizeForCreate($data));
        return $this->db->lastInsertId();
    }

    /**
     * @deprecated Id-only WHERE — tooling/migration only. Use {@see updateInTenantScope} from tenant inventory services.
     */
    public function update(int $id, array $data): void
    {
        $norm = $this->normalizeForTenantScopedProductUpdate($data);
        if (empty($norm)) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE products SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    public function updateInTenantScope(int $id, int $branchId, array $data): void
    {
        $norm = $this->normalizeForTenantScopedProductUpdate($data);
        if (empty($norm)) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals[] = $branchId;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query(
            'UPDATE products p SET ' . implode(', ', $cols) . ' WHERE p.id = ? AND p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'],
            $vals
        );
    }

    /**
     * Legacy taxonomy backfill / repair: patch only {@code product_category_id} and {@code product_brand_id} on a row
     * that satisfies {@see resolvedTenantCatalogProductVisibilityClause} (branch-owned-in-org ∪ org-global-null catalog).
     * Replaces id-only {@see update} for this flow so org context is enforced on the UPDATE predicate.
     */
    public function updateTaxonomyFkPatchInResolvedTenantCatalog(int $id, array $data): void
    {
        if ($id <= 0) {
            return;
        }
        $norm = array_intersect_key(
            $this->normalizeForTenantScopedProductUpdate($data),
            array_flip(['product_category_id', 'product_brand_id'])
        );
        if ($norm === []) {
            return;
        }
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $cols = array_map(fn ($k) => "p.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $vis['params']);
        $this->db->query(
            'UPDATE products p SET ' . implode(', ', $cols) . ' WHERE p.id = ? AND p.deleted_at IS NULL' . $vis['sql'],
            $vals
        );
    }

    /**
     * @deprecated Id-only WHERE — tooling/migration only. Use {@see softDeleteInTenantScope} from tenant inventory services.
     */
    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE products SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    public function softDeleteInTenantScope(int $id, int $branchId): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $this->db->query(
            'UPDATE products p SET p.deleted_at = NOW() WHERE p.id = ? AND p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * Full **resolved-tenant product catalog** visibility (backfill / counts / orphan audits) without a concrete operation branch:
     * same boolean as {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause()}
     * on {@code products} — branch-owned-in-org **or** org-global-null SKU with org live-branch anchor.
     *
     * @return array{sql: string, params: list<mixed>} Leading {@code AND (...)} for appending after {@code WHERE} predicates.
     */
    private function resolvedTenantCatalogProductVisibilityClause(string $alias = 'p'): array
    {
        $u = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause($alias);

        return ['sql' => ' AND (' . $u['sql'] . ')', 'params' => $u['params']];
    }

    /**
     * @deprecated Unscoped full catalog. Prefer {@see listNonDeletedForTaxonomyBackfillInResolvedTenantCatalog} under tenant org context.
     *
     * Non-deleted products for legacy taxonomy backfill (minimal columns).
     *
     * @return list<array<string, mixed>>
     */
    public function listNonDeletedForTaxonomyBackfill(): array
    {
        return $this->db->fetchAll(
            'SELECT id, branch_id, category, brand, product_category_id, product_brand_id FROM products WHERE deleted_at IS NULL ORDER BY id ASC'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listNonDeletedForTaxonomyBackfillInResolvedTenantCatalog(): array
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');

        return $this->db->fetchAll(
            'SELECT p.id, p.branch_id, p.category, p.brand, p.product_category_id, p.product_brand_id
             FROM products p
             WHERE p.deleted_at IS NULL' . $vis['sql'] . ' ORDER BY p.id ASC',
            $vis['params']
        );
    }

    /**
     * Clear normalized category FK on **tenant-catalog-visible** active products before soft-deleting the category.
     */
    public function detachActiveProductsFromCategory(int $productCategoryId): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $stmt = $this->db->query(
            'UPDATE products p SET p.product_category_id = NULL
             WHERE p.product_category_id = ? AND p.deleted_at IS NULL' . $vis['sql'],
            array_merge([$productCategoryId], $vis['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * Clear normalized brand FK on **tenant-catalog-visible** active products before soft-deleting the brand.
     */
    public function detachActiveProductsFromBrand(int $productBrandId): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $stmt = $this->db->query(
            'UPDATE products p SET p.product_brand_id = NULL
             WHERE p.product_brand_id = ? AND p.deleted_at IS NULL' . $vis['sql'],
            array_merge([$productBrandId], $vis['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * @deprecated Unscoped count. Prefer {@see countNonDeletedProductsInResolvedTenantCatalog}.
     */
    public function countNonDeletedProducts(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NULL');

        return (int) ($row['c'] ?? 0);
    }

    public function countNonDeletedProductsInResolvedTenantCatalog(): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM products p WHERE p.deleted_at IS NULL' . $vis['sql'],
            $vis['params']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @deprecated Unscoped orphan count. Prefer {@see countActiveWithOrphanProductCategoryFkInResolvedTenantCatalog}.
     */
    public function countActiveWithOrphanProductCategoryFk(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)'
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countActiveWithOrphanProductCategoryFkInResolvedTenantCatalog(): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)' . $vis['sql'],
            $vis['params']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @deprecated Unscoped orphan count. Prefer {@see countActiveWithOrphanProductBrandFkInResolvedTenantCatalog}.
     */
    public function countActiveWithOrphanProductBrandFk(): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)'
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countActiveWithOrphanProductBrandFkInResolvedTenantCatalog(): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)' . $vis['sql'],
            $vis['params']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array{branch_id: int|string|null, c: int|string}>
     * @deprecated Unscoped. Prefer {@see listOrphanProductCategoryFkCountsByProductBranchInResolvedTenantCatalog}.
     */
    public function listOrphanProductCategoryFkCountsByProductBranch(): array
    {
        return $this->db->fetchAll(
            'SELECT p.branch_id, COUNT(*) AS c FROM products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)
             GROUP BY p.branch_id'
        );
    }

    /**
     * @return list<array{branch_id: int|string|null, c: int|string}>
     */
    public function listOrphanProductCategoryFkCountsByProductBranchInResolvedTenantCatalog(): array
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');

        return $this->db->fetchAll(
            'SELECT p.branch_id, COUNT(*) AS c FROM products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)' . $vis['sql'] . '
             GROUP BY p.branch_id',
            $vis['params']
        );
    }

    /**
     * @return list<array{branch_id: int|string|null, c: int|string}>
     * @deprecated Unscoped. Prefer {@see listOrphanProductBrandFkCountsByProductBranchInResolvedTenantCatalog}.
     */
    public function listOrphanProductBrandFkCountsByProductBranch(): array
    {
        return $this->db->fetchAll(
            'SELECT p.branch_id, COUNT(*) AS c FROM products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)
             GROUP BY p.branch_id'
        );
    }

    /**
     * @return list<array{branch_id: int|string|null, c: int|string}>
     */
    public function listOrphanProductBrandFkCountsByProductBranchInResolvedTenantCatalog(): array
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');

        return $this->db->fetchAll(
            'SELECT p.branch_id, COUNT(*) AS c FROM products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)' . $vis['sql'] . '
             GROUP BY p.branch_id',
            $vis['params']
        );
    }

    /**
     * @return list<array{product_id: int|string, branch_id: int|string|null, fk_id: int|string}>
     * @deprecated Unscoped. Prefer {@see listOrphanProductCategoryFkExamplesInResolvedTenantCatalog}.
     */
    public function listOrphanProductCategoryFkExamples(int $limit): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->fetchAll(
            'SELECT p.id AS product_id, p.branch_id, p.product_category_id AS fk_id
             FROM products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)
             ORDER BY p.id ASC LIMIT ' . $limit
        );
    }

    /**
     * @return list<array{product_id: int|string, branch_id: int|string|null, fk_id: int|string}>
     */
    public function listOrphanProductCategoryFkExamplesInResolvedTenantCatalog(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');

        return $this->db->fetchAll(
            'SELECT p.id AS product_id, p.branch_id, p.product_category_id AS fk_id
             FROM products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)' . $vis['sql'] . '
             ORDER BY p.id ASC LIMIT ' . $limit,
            $vis['params']
        );
    }

    /**
     * @return list<array{product_id: int|string, branch_id: int|string|null, fk_id: int|string}>
     * @deprecated Unscoped. Prefer {@see listOrphanProductBrandFkExamplesInResolvedTenantCatalog}.
     */
    public function listOrphanProductBrandFkExamples(int $limit): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->fetchAll(
            'SELECT p.id AS product_id, p.branch_id, p.product_brand_id AS fk_id
             FROM products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)
             ORDER BY p.id ASC LIMIT ' . $limit
        );
    }

    /**
     * @return list<array{product_id: int|string, branch_id: int|string|null, fk_id: int|string}>
     */
    public function listOrphanProductBrandFkExamplesInResolvedTenantCatalog(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');

        return $this->db->fetchAll(
            'SELECT p.id AS product_id, p.branch_id, p.product_brand_id AS fk_id
             FROM products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)' . $vis['sql'] . '
             ORDER BY p.id ASC LIMIT ' . $limit,
            $vis['params']
        );
    }

    /**
     * Clear category FK when the referenced row is missing or soft-deleted (legacy `category` unchanged).
     *
     * @deprecated Unscoped UPDATE. Prefer {@see clearOrphanProductCategoryFkOnActiveProductsInResolvedTenantCatalog}.
     */
    public function clearOrphanProductCategoryFkOnActiveProducts(): int
    {
        $stmt = $this->db->query(
            'UPDATE products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             SET p.product_category_id = NULL
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)'
        );

        return $stmt->rowCount();
    }

    public function clearOrphanProductCategoryFkOnActiveProductsInResolvedTenantCatalog(): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $stmt = $this->db->query(
            'UPDATE products p
             LEFT JOIN product_categories pc ON pc.id = p.product_category_id
             SET p.product_category_id = NULL
             WHERE p.deleted_at IS NULL AND p.product_category_id IS NOT NULL
             AND (pc.id IS NULL OR pc.deleted_at IS NOT NULL)' . $vis['sql'],
            $vis['params']
        );

        return $stmt->rowCount();
    }

    /**
     * Clear brand FK when the referenced row is missing or soft-deleted (legacy `brand` unchanged).
     *
     * @deprecated Unscoped UPDATE. Prefer {@see clearOrphanProductBrandFkOnActiveProductsInResolvedTenantCatalog}.
     */
    public function clearOrphanProductBrandFkOnActiveProducts(): int
    {
        $stmt = $this->db->query(
            'UPDATE products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             SET p.product_brand_id = NULL
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)'
        );

        return $stmt->rowCount();
    }

    public function clearOrphanProductBrandFkOnActiveProductsInResolvedTenantCatalog(): int
    {
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $stmt = $this->db->query(
            'UPDATE products p
             LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
             SET p.product_brand_id = NULL
             WHERE p.deleted_at IS NULL AND p.product_brand_id IS NOT NULL
             AND (pb.id IS NULL OR pb.deleted_at IS NOT NULL)' . $vis['sql'],
            $vis['params']
        );

        return $stmt->rowCount();
    }

    public function countActiveProductsReferencingCategoryIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM products p WHERE p.deleted_at IS NULL AND p.product_category_id IN ({$placeholders})" . $vis['sql'],
            array_merge($ids, $vis['params'])
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countActiveProductsReferencingBrandIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM products p WHERE p.deleted_at IS NULL AND p.product_brand_id IN ({$placeholders})" . $vis['sql'],
            array_merge($ids, $vis['params'])
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countActiveProductsReferencingCategoryId(int $categoryId): int
    {
        $categoryId = (int) $categoryId;
        if ($categoryId <= 0) {
            return 0;
        }

        return $this->countActiveProductsReferencingCategoryIds([$categoryId]);
    }

    public function countActiveProductsReferencingBrandId(int $brandId): int
    {
        $brandId = (int) $brandId;
        if ($brandId <= 0) {
            return 0;
        }

        return $this->countActiveProductsReferencingBrandIds([$brandId]);
    }

    /**
     * @param list<int> $noncanonicalIds
     */
    public function relinkActiveProductCategoryIdsToCanonical(array $noncanonicalIds, int $canonicalId): int
    {
        $noncanonicalIds = array_values(array_unique(array_filter(array_map('intval', $noncanonicalIds), static fn (int $i): bool => $i > 0)));
        $canonicalId = (int) $canonicalId;
        if ($noncanonicalIds === [] || $canonicalId <= 0) {
            return 0;
        }
        $noncanonicalIds = array_values(array_filter($noncanonicalIds, static fn (int $i): bool => $i !== $canonicalId));
        if ($noncanonicalIds === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($noncanonicalIds), '?'));
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $params = array_merge([$canonicalId], $noncanonicalIds, $vis['params']);
        $stmt = $this->db->query(
            "UPDATE products p SET p.product_category_id = ?
             WHERE p.deleted_at IS NULL AND p.product_category_id IN ({$placeholders})" . $vis['sql'],
            $params
        );

        return $stmt->rowCount();
    }

    /**
     * @param list<int> $noncanonicalIds
     */
    public function relinkActiveProductBrandIdsToCanonical(array $noncanonicalIds, int $canonicalId): int
    {
        $noncanonicalIds = array_values(array_unique(array_filter(array_map('intval', $noncanonicalIds), static fn (int $i): bool => $i > 0)));
        $canonicalId = (int) $canonicalId;
        if ($noncanonicalIds === [] || $canonicalId <= 0) {
            return 0;
        }
        $noncanonicalIds = array_values(array_filter($noncanonicalIds, static fn (int $i): bool => $i !== $canonicalId));
        if ($noncanonicalIds === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($noncanonicalIds), '?'));
        $vis = $this->resolvedTenantCatalogProductVisibilityClause('p');
        $params = array_merge([$canonicalId], $noncanonicalIds, $vis['params']);
        $stmt = $this->db->query(
            "UPDATE products p SET p.product_brand_id = ?
             WHERE p.deleted_at IS NULL AND p.product_brand_id IN ({$placeholders})" . $vis['sql'],
            $params
        );

        return $stmt->rowCount();
    }

    /**
     * Generic list search for **unscoped** {@see list} / {@see count} only. Taxonomy EXISTS uses local branch/null pairing — **not**
     * {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()} (no operation branch).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function genericSearchCondition(string $q): array
    {
        $sql = ' AND (
            products.name LIKE ? OR products.sku LIKE ? OR products.barcode LIKE ? OR products.category LIKE ? OR products.brand LIKE ?
            OR EXISTS (
                SELECT 1 FROM product_categories pc
                WHERE pc.id = products.product_category_id
                AND pc.deleted_at IS NULL
                AND pc.name LIKE ?
                AND (
                    (products.branch_id IS NULL AND pc.branch_id IS NULL)
                    OR (products.branch_id IS NOT NULL AND (pc.branch_id IS NULL OR pc.branch_id = products.branch_id))
                )
            )
            OR EXISTS (
                SELECT 1 FROM product_brands pb
                WHERE pb.id = products.product_brand_id
                AND pb.deleted_at IS NULL
                AND pb.name LIKE ?
                AND (
                    (products.branch_id IS NULL AND pb.branch_id IS NULL)
                    OR (products.branch_id IS NOT NULL AND (pb.branch_id IS NULL OR pb.branch_id = products.branch_id))
                )
            )
        )';

        return [$sql, [$q, $q, $q, $q, $q, $q, $q]];
    }

    /**
     * Tenant {@see listInTenantScope} / {@see countInTenantScope} search: taxonomy EXISTS rows must satisfy
     * {@see OrganizationRepositoryScope::taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause()} for {@code $operationBranchId}.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function genericSearchConditionForAlias(string $alias, string $q, int $operationBranchId): array
    {
        if ($operationBranchId <= 0) {
            throw new \DomainException('Operation branch id must be positive for tenant-scoped product search.');
        }
        $a = $alias;
        $uPc = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pc', $operationBranchId);
        $uPb = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('pb', $operationBranchId);
        $sql = " AND (
            {$a}.name LIKE ? OR {$a}.sku LIKE ? OR {$a}.barcode LIKE ? OR {$a}.category LIKE ? OR {$a}.brand LIKE ?
            OR EXISTS (
                SELECT 1 FROM product_categories pc
                WHERE pc.id = {$a}.product_category_id
                AND pc.deleted_at IS NULL
                AND pc.name LIKE ?
                AND ({$uPc['sql']})
            )
            OR EXISTS (
                SELECT 1 FROM product_brands pb
                WHERE pb.id = {$a}.product_brand_id
                AND pb.deleted_at IS NULL
                AND pb.name LIKE ?
                AND ({$uPb['sql']})
            )
        )";
        $params = array_merge(
            [$q, $q, $q, $q, $q],
            [$q],
            $uPc['params'],
            [$q],
            $uPb['params']
        );

        return [$sql, $params];
    }

    /**
     * INSERT-only: allows initial {@code stock_quantity} (e.g. 0 on create). On-hand changes after insert must use
     * {@see updateStockQuantityForStockMutationInResolvedOrg}.
     *
     * @return array<string, mixed>
     */
    private function normalizeForCreate(array $data): array
    {
        $allowed = [
            'name', 'sku', 'barcode', 'category', 'brand', 'product_category_id', 'product_brand_id', 'product_type',
            'cost_price', 'sell_price', 'vat_rate', 'stock_quantity', 'reorder_level', 'is_active', 'branch_id',
            'created_by', 'updated_by',
        ];

        return $this->applyNormalizedProductScalars($data, $allowed);
    }

    /**
     * Tenant-scoped and id-only {@see update} payloads: **excludes** {@code stock_quantity} so generic product edits
     * cannot mutate on-hand; use {@see updateStockQuantityForStockMutationInResolvedOrg} for ledger-aligned stock.
     *
     * @return array<string, mixed>
     */
    private function normalizeForTenantScopedProductUpdate(array $data): array
    {
        $allowed = [
            'name', 'sku', 'barcode', 'category', 'brand', 'product_category_id', 'product_brand_id', 'product_type',
            'cost_price', 'sell_price', 'vat_rate', 'reorder_level', 'is_active', 'branch_id',
            'created_by', 'updated_by',
        ];

        return $this->applyNormalizedProductScalars($data, $allowed);
    }

    /**
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function applyNormalizedProductScalars(array $data, array $allowed): array
    {
        $out = array_intersect_key($data, array_flip($allowed));
        if (isset($out['is_active'])) {
            $out['is_active'] = $out['is_active'] ? 1 : 0;
        }

        return $out;
    }
}
