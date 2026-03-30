<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only audit: non-deleted {@see products} rows vs normalized {@see product_categories} / {@see product_brands}
 * FK coverage and {@see ProductTaxonomyAssignabilityService} branch rules (mirrored as boolean checks; no writes).
 *
 * Task: {@code PRODUCT-BRAND-CATALOG-TAIL-WAVE-01}.
 */
final class ProductCatalogReferenceCoverageAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AFFECTED_ID_SAMPLE_CAP = 100;

    public const AUDIT_SCHEMA_VERSION = 1;

    /** Rollup / example iteration order. */
    public const COVERAGE_CLASSES = [
        'catalog_reference_ok',
        'missing_category_reference',
        'inactive_category_reference',
        'category_branch_contract_risk',
        'missing_brand_reference',
        'inactive_brand_reference',
        'brand_branch_contract_risk',
        'mixed_reference_anomaly',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     generated_at_utc: string,
     *     audit_schema_version: int,
     *     product_id_filter: int|null,
     *     products_scanned: int,
     *     coverage_class_counts: array<string, int>,
     *     affected_products_count: int,
     *     affected_product_ids_sample: list<int>,
     *     examples_by_coverage_class: array<string, list<array<string, mixed>>>,
     *     notes: list<string>,
     *     products: list<array<string, mixed>>
     * }
     */
    public function run(?int $productId = null): array
    {
        $generatedAt = gmdate('c');
        $params = [];
        $filterSql = '';
        if ($productId !== null) {
            $filterSql = ' AND p.id = ?';
            $params[] = $productId;
        }

        $sql = <<<SQL
SELECT p.id AS product_id,
       p.sku,
       p.name,
       p.branch_id AS product_branch_id,
       p.product_category_id,
       p.product_brand_id,
       pc.id AS pc_row_id,
       pc.branch_id AS pc_branch_id,
       pc.deleted_at AS pc_deleted_at,
       pb.id AS pb_row_id,
       pb.branch_id AS pb_branch_id,
       pb.deleted_at AS pb_deleted_at
FROM products p
LEFT JOIN product_categories pc ON pc.id = p.product_category_id
LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
WHERE p.deleted_at IS NULL{$filterSql}
ORDER BY p.id ASC
SQL;

        $rows = $this->db->fetchAll($sql, $params);

        $counts = array_fill_keys(self::COVERAGE_CLASSES, 0);
        $examples = [];
        foreach (self::COVERAGE_CLASSES as $c) {
            $examples[$c] = [];
        }

        $affectedIds = [];
        $products = [];

        foreach ($rows as $r) {
            $eval = $this->evaluateProductRow($r);
            $class = $eval['coverage_class'];
            $payload = $eval['payload'];
            $counts[$class]++;
            $products[] = $payload;

            if ($class !== 'catalog_reference_ok') {
                $affectedIds[] = (int) $payload['product_id'];
            }

            if (count($examples[$class]) < self::EXAMPLE_CAP) {
                $examples[$class][] = $payload;
            }
        }

        $scanned = count($rows);
        $affectedCount = count($affectedIds);
        $sample = array_slice($affectedIds, 0, self::AFFECTED_ID_SAMPLE_CAP);

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'product_id_filter' => $productId,
            'products_scanned' => $scanned,
            'coverage_class_counts' => $counts,
            'affected_products_count' => $affectedCount,
            'affected_product_ids_sample' => $sample,
            'examples_by_coverage_class' => $examples,
            'notes' => [
                'Scans products with deleted_at IS NULL only.',
                'Normalized coverage requires non-null product_category_id and product_brand_id pointing at live (deleted_at IS NULL) rows that satisfy ProductTaxonomyAssignabilityService branch rules.',
                'Legacy string columns products.category / products.brand are not evaluated here.',
                'Product categories and brands have no separate is_active flag in schema; inactive_* means the referenced taxonomy row is soft-deleted (deleted_at set).',
                'This audit does not repair FKs or taxonomy rows; use orphan-FK tooling separately if remediation is required.',
            ],
            'products' => $products,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{coverage_class: string, payload: array<string, mixed>}
     */
    private function evaluateProductRow(array $r): array
    {
        $productId = (int) $r['product_id'];
        $sku = (string) ($r['sku'] ?? '');
        $name = (string) ($r['name'] ?? '');
        $branchRaw = $r['product_branch_id'] ?? null;
        $productBranchId = ($branchRaw !== null && $branchRaw !== '') ? (int) $branchRaw : null;

        $catFk = $this->nullableInt($r['product_category_id'] ?? null);
        $brandFk = $this->nullableInt($r['product_brand_id'] ?? null);

        $pcRowId = $this->nullableInt($r['pc_row_id'] ?? null);
        $pcBranchRaw = $r['pc_branch_id'] ?? null;
        $pcBranchId = ($pcRowId === null) ? null : (($pcBranchRaw !== null && $pcBranchRaw !== '') ? (int) $pcBranchRaw : null);
        $pcDeleted = $this->isDeletedAtSet($r['pc_deleted_at'] ?? null);

        $pbRowId = $this->nullableInt($r['pb_row_id'] ?? null);
        $pbBranchRaw = $r['pb_branch_id'] ?? null;
        $pbBranchId = ($pbRowId === null) ? null : (($pbBranchRaw !== null && $pbBranchRaw !== '') ? (int) $pbBranchRaw : null);
        $pbDeleted = $this->isDeletedAtSet($r['pb_deleted_at'] ?? null);

        $catExists = $pcRowId !== null;
        $brandExists = $pbRowId !== null;

        $catLive = $catExists && !$pcDeleted;
        $brandLive = $brandExists && !$pbDeleted;

        $catAssignable = $catLive && $this->taxonomyAssignableToProductBranch($productBranchId, $pcBranchId);
        $brandAssignable = $brandLive && $this->taxonomyAssignableToProductBranch($productBranchId, $pbBranchId);

        $catIssue = $this->axisIssue('category', $catFk, $catExists, $pcDeleted, $catAssignable);
        $brandIssue = $this->axisIssue('brand', $brandFk, $brandExists, $pbDeleted, $brandAssignable);

        [$coverageClass, $reasonCodes] = $this->mergeIssues($catIssue, $brandIssue);

        $payload = [
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'branch_id' => $productBranchId,
            'category_id' => $catFk,
            'category_exists' => $catExists,
            'category_deleted' => $pcDeleted,
            'category_branch_id' => $pcBranchId,
            'category_assignable' => $catAssignable,
            'brand_id' => $brandFk,
            'brand_exists' => $brandExists,
            'brand_deleted' => $pbDeleted,
            'brand_branch_id' => $pbBranchId,
            'brand_assignable' => $brandAssignable,
            'coverage_class' => $coverageClass,
            'reason_codes' => $reasonCodes,
        ];

        return ['coverage_class' => $coverageClass, 'payload' => $payload];
    }

    /**
     * @return array{kind: string, reasons: list<string>}|null kind: ok|missing|inactive|branch_risk
     */
    private function axisIssue(
        string $axis,
        ?int $fk,
        bool $rowExists,
        bool $rowDeleted,
        bool $assignable
    ): ?array {
        $p = $axis . '_';
        if ($fk === null) {
            return ['kind' => 'missing', 'reasons' => [$p . 'normalized_fk_null']];
        }
        if (!$rowExists) {
            return ['kind' => 'missing', 'reasons' => [$p . 'orphan_fk_no_row']];
        }
        if ($rowDeleted) {
            return ['kind' => 'inactive', 'reasons' => [$p . 'referenced_row_soft_deleted']];
        }
        if (!$assignable) {
            return [
                'kind' => 'branch_risk',
                'reasons' => [$p . 'branch_assignability_failed_per_product_taxonomy_rules'],
            ];
        }

        return null;
    }

    /**
     * @param array{kind: string, reasons: list<string>}|null $cat
     * @param array{kind: string, reasons: list<string>}|null $brand
     * @return array{0: string, 1: list<string>}
     */
    private function mergeIssues(?array $cat, ?array $brand): array
    {
        if ($cat === null && $brand === null) {
            return ['catalog_reference_ok', ['both_normalized_references_live_and_assignable']];
        }
        if ($cat !== null && $brand !== null) {
            return ['mixed_reference_anomaly', array_merge($cat['reasons'], $brand['reasons'])];
        }
        if ($cat !== null) {
            return [$this->singleClassForAxis('category', $cat['kind']), $cat['reasons']];
        }

        return [$this->singleClassForAxis('brand', $brand['kind']), $brand['reasons']];
    }

    private function singleClassForAxis(string $axis, string $kind): string
    {
        return match ([$axis, $kind]) {
            ['category', 'missing'] => 'missing_category_reference',
            ['category', 'inactive'] => 'inactive_category_reference',
            ['category', 'branch_risk'] => 'category_branch_contract_risk',
            ['brand', 'missing'] => 'missing_brand_reference',
            ['brand', 'inactive'] => 'inactive_brand_reference',
            ['brand', 'branch_risk'] => 'brand_branch_contract_risk',
            default => 'mixed_reference_anomaly',
        };
    }

    /**
     * Mirrors {@see ProductTaxonomyAssignabilityService::assertTaxonomyBranchMatchesProduct} without throwing.
     */
    private function taxonomyAssignableToProductBranch(?int $productBranchId, ?int $taxonomyBranchId): bool
    {
        $taxBranch = $taxonomyBranchId;
        if ($productBranchId === null) {
            return $taxBranch === null;
        }
        if ($taxBranch === null) {
            return true;
        }

        return $taxBranch === $productBranchId;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    private function isDeletedAtSet(mixed $v): bool
    {
        return $v !== null && (string) $v !== '';
    }
}
