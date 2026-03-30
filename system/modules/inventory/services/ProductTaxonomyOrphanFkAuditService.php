<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;

/**
 * Read-only audit of orphan {@see products.product_category_id} / {@see products.product_brand_id};
 * optional {@see run} with apply clears only those FKs (missing or soft-deleted taxonomy row).
 */
final class ProductTaxonomyOrphanFkAuditService
{
    private const EXAMPLE_CAP = 40;

    private const EXAMPLES_PER_FIELD = 20;

    public function __construct(
        private Database $db,
        private ProductRepository $products,
        private ProductCategoryRepository $categories,
        private ProductBrandRepository $brands,
    ) {
    }

    /**
     * @return array{
     *     products_scanned: int,
     *     orphan_category_fk_count: int,
     *     orphan_brand_fk_count: int,
     *     orphan_category_fk_cleared: int,
     *     orphan_brand_fk_cleared: int,
     *     duplicate_category_scope_name_groups: int,
     *     duplicate_brand_scope_name_groups: int,
     *     anomaly_examples: list<array{product_id: int, field: string, fk_id: int}>,
     *     orphan_category_fk_by_scope: list<array{scope: string, count: int}>,
     *     orphan_brand_fk_by_scope: list<array{scope: string, count: int}>
     * }
     */
    public function run(bool $apply): array
    {
        $dupCatGroups = $this->categories->listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope();
        $dupBrandGroups = $this->brands->listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope();

        $summary = [
            'products_scanned' => $this->products->countNonDeletedProductsInResolvedTenantCatalog(),
            'orphan_category_fk_count' => $this->products->countActiveWithOrphanProductCategoryFkInResolvedTenantCatalog(),
            'orphan_brand_fk_count' => $this->products->countActiveWithOrphanProductBrandFkInResolvedTenantCatalog(),
            'orphan_category_fk_cleared' => 0,
            'orphan_brand_fk_cleared' => 0,
            'duplicate_category_scope_name_groups' => count($dupCatGroups),
            'duplicate_brand_scope_name_groups' => count($dupBrandGroups),
            'anomaly_examples' => $this->buildAnomalyExamples(),
            'orphan_category_fk_by_scope' => $this->mapBranchCountRows($this->products->listOrphanProductCategoryFkCountsByProductBranchInResolvedTenantCatalog()),
            'orphan_brand_fk_by_scope' => $this->mapBranchCountRows($this->products->listOrphanProductBrandFkCountsByProductBranchInResolvedTenantCatalog()),
        ];

        if ($apply) {
            $pdo = $this->db->connection();
            $pdo->beginTransaction();
            try {
                $summary['orphan_category_fk_cleared'] = $this->products->clearOrphanProductCategoryFkOnActiveProductsInResolvedTenantCatalog();
                $summary['orphan_brand_fk_cleared'] = $this->products->clearOrphanProductBrandFkOnActiveProductsInResolvedTenantCatalog();
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return $summary;
    }

    /**
     * @return list<array{product_id: int, field: string, fk_id: int}>
     */
    private function buildAnomalyExamples(): array
    {
        $cat = $this->products->listOrphanProductCategoryFkExamplesInResolvedTenantCatalog(self::EXAMPLES_PER_FIELD);
        $brand = $this->products->listOrphanProductBrandFkExamplesInResolvedTenantCatalog(self::EXAMPLES_PER_FIELD);
        $out = [];
        foreach ($cat as $r) {
            if (count($out) >= self::EXAMPLE_CAP) {
                break;
            }
            $out[] = [
                'product_id' => (int) $r['product_id'],
                'field' => 'product_category_id',
                'fk_id' => (int) $r['fk_id'],
            ];
        }
        foreach ($brand as $r) {
            if (count($out) >= self::EXAMPLE_CAP) {
                break;
            }
            $out[] = [
                'product_id' => (int) $r['product_id'],
                'field' => 'product_brand_id',
                'fk_id' => (int) $r['fk_id'],
            ];
        }

        return $out;
    }

    /**
     * @param list<array{branch_id: int|string|null, c: int|string}> $rows
     *
     * @return list<array{scope: string, count: int}>
     */
    private function mapBranchCountRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'scope' => $this->productBranchScopeLabel($r['branch_id'] ?? null),
                'count' => (int) ($r['c'] ?? 0),
            ];
        }

        return $out;
    }

    private function productBranchScopeLabel(mixed $branchId): string
    {
        if ($branchId === null || $branchId === '') {
            return 'global';
        }

        return 'branch:' . (int) $branchId;
    }
}
