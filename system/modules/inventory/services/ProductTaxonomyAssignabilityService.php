<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;

/**
 * Rules for attaching normalized {@see product_categories} / {@see product_brands} to a {@see products} row.
 *
 * - Branch product ({@code branch_id} = N): may use global taxonomy ({@code branch_id} IS NULL) or taxonomy scoped to N.
 * - Global product ({@code branch_id} IS NULL): may use **only** global taxonomy rows.
 */
final class ProductTaxonomyAssignabilityService
{
    public function __construct(
        private ProductCategoryRepository $categories,
        private ProductBrandRepository $brands,
    ) {
    }

    /**
     * @param ?int $categoryId resolved FK or null = no normalized category
     * @param ?int $brandId resolved FK or null = no normalized brand
     * @param int $operationBranchId session (or equivalent) branch for org-scoped taxonomy row loads
     */
    public function assertFinalProductTaxonomy(?int $productBranchId, ?int $categoryId, ?int $brandId, int $operationBranchId): void
    {
        if (($categoryId !== null || $brandId !== null) && $operationBranchId <= 0) {
            throw new \DomainException('Branch context is required for product taxonomy assignment checks.');
        }
        $this->assertCategoryAssignableToProductBranch($productBranchId, $categoryId, $operationBranchId);
        $this->assertBrandAssignableToProductBranch($productBranchId, $brandId, $operationBranchId);
    }

    public function assertCategoryAssignableToProductBranch(?int $productBranchId, ?int $categoryId, int $operationBranchId): void
    {
        if ($categoryId === null) {
            return;
        }
        $row = $this->categories->findInTenantScope($categoryId, $operationBranchId);
        if ($row === null) {
            throw new \InvalidArgumentException('Product category not found or is deleted.');
        }
        $this->assertTaxonomyBranchMatchesProduct($productBranchId, $row['branch_id'] ?? null, 'category');
    }

    public function assertBrandAssignableToProductBranch(?int $productBranchId, ?int $brandId, int $operationBranchId): void
    {
        if ($brandId === null) {
            return;
        }
        $row = $this->brands->findInTenantScope($brandId, $operationBranchId);
        if ($row === null) {
            throw new \InvalidArgumentException('Product brand not found or is deleted.');
        }
        $this->assertTaxonomyBranchMatchesProduct($productBranchId, $row['branch_id'] ?? null, 'brand');
    }

    private function assertTaxonomyBranchMatchesProduct(?int $productBranchId, mixed $taxonomyBranchRaw, string $label): void
    {
        $taxBranch = ($taxonomyBranchRaw !== null && $taxonomyBranchRaw !== '') ? (int) $taxonomyBranchRaw : null;
        if ($productBranchId === null) {
            if ($taxBranch !== null) {
                throw new \InvalidArgumentException("A global product may only use a global {$label} (not branch-scoped).");
            }

            return;
        }
        if ($taxBranch === null) {
            return;
        }
        if ($taxBranch !== $productBranchId) {
            throw new \InvalidArgumentException(ucfirst($label) . ' belongs to a different branch than the product.');
        }
    }
}
