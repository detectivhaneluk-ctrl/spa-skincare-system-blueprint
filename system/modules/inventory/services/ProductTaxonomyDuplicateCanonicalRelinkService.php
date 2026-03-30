<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;

/**
 * Audit + optional apply: relink active {@see products} normalized FKs from noncanonical duplicate
 * live taxonomy rows to the canonical row (lowest id per scope + TRIM(name)). Does not delete taxonomy rows.
 */
final class ProductTaxonomyDuplicateCanonicalRelinkService
{
    private const DUPLICATE_EXAMPLE_CAP = 24;

    public function __construct(
        private Database $db,
        private ProductRepository $products,
        private ProductCategoryRepository $categories,
        private ProductBrandRepository $brands,
    ) {
    }

    /**
     * @return array{
     *     category_duplicate_group_count: int,
     *     brand_duplicate_group_count: int,
     *     category_noncanonical_rows_seen: int,
     *     brand_noncanonical_rows_seen: int,
     *     active_products_with_noncanonical_category_fk: int,
     *     active_products_with_noncanonical_brand_fk: int,
     *     category_product_fks_relinked: int,
     *     brand_product_fks_relinked: int,
     *     duplicate_examples: list<array{
     *         kind: string,
     *         scope: string,
     *         trimmed_name: string,
     *         canonical_id: int,
     *         noncanonical_ids: list<int>,
     *         active_product_reference_count: int
     *     }>
     * }
     */
    public function run(bool $apply): array
    {
        $catGroups = $this->categories->listDuplicateLiveCategoryGroupsByScopeAndTrimmedName();
        $brandGroups = $this->brands->listDuplicateLiveBrandGroupsByScopeAndTrimmedName();

        $catDetailRows = $this->buildCategoryDuplicateDetails($catGroups);
        $brandDetailRows = $this->buildBrandDuplicateDetails($brandGroups);

        $allNoncanonicalCategoryIds = [];
        $allNoncanonicalBrandIds = [];
        $categoryNoncanonicalRowTotal = 0;
        $brandNoncanonicalRowTotal = 0;

        foreach ($catDetailRows as $row) {
            $categoryNoncanonicalRowTotal += count($row['noncanonical_ids']);
            foreach ($row['noncanonical_ids'] as $nid) {
                $allNoncanonicalCategoryIds[$nid] = true;
            }
        }
        foreach ($brandDetailRows as $row) {
            $brandNoncanonicalRowTotal += count($row['noncanonical_ids']);
            foreach ($row['noncanonical_ids'] as $nid) {
                $allNoncanonicalBrandIds[$nid] = true;
            }
        }

        $examples = $this->interleaveDuplicateExamples($catDetailRows, $brandDetailRows);

        $allCatNc = array_map('intval', array_keys($allNoncanonicalCategoryIds));
        $allBrandNc = array_map('intval', array_keys($allNoncanonicalBrandIds));

        $out = [
            'category_duplicate_group_count' => count($catDetailRows),
            'brand_duplicate_group_count' => count($brandDetailRows),
            'category_noncanonical_rows_seen' => $categoryNoncanonicalRowTotal,
            'brand_noncanonical_rows_seen' => $brandNoncanonicalRowTotal,
            'active_products_with_noncanonical_category_fk' => $this->products->countActiveProductsReferencingCategoryIds($allCatNc),
            'active_products_with_noncanonical_brand_fk' => $this->products->countActiveProductsReferencingBrandIds($allBrandNc),
            'category_product_fks_relinked' => 0,
            'brand_product_fks_relinked' => 0,
            'duplicate_examples' => $examples,
        ];

        if (!$apply) {
            return $out;
        }

        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            foreach ($catDetailRows as $row) {
                $out['category_product_fks_relinked'] += $this->products->relinkActiveProductCategoryIdsToCanonical(
                    $row['noncanonical_ids'],
                    $row['canonical_id']
                );
            }
            foreach ($brandDetailRows as $row) {
                $out['brand_product_fks_relinked'] += $this->products->relinkActiveProductBrandIdsToCanonical(
                    $row['noncanonical_ids'],
                    $row['canonical_id']
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $out;
    }

    /**
     * @param list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}> $groups
     *
     * @return list<array{branch_id: ?int, trimmed_name: string, canonical_id: int, noncanonical_ids: list<int>}>
     */
    private function buildCategoryDuplicateDetails(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $branchId = $this->nullableBranchId($g['branch_id'] ?? null);
            $trimmedName = (string) ($g['trimmed_name'] ?? '');
            $orderedIds = $this->categories->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
            if (count($orderedIds) < 2) {
                continue;
            }
            $out[] = [
                'branch_id' => $branchId,
                'trimmed_name' => $trimmedName,
                'canonical_id' => $orderedIds[0],
                'noncanonical_ids' => array_values(array_slice($orderedIds, 1)),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}> $groups
     *
     * @return list<array{branch_id: ?int, trimmed_name: string, canonical_id: int, noncanonical_ids: list<int>}>
     */
    private function buildBrandDuplicateDetails(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $branchId = $this->nullableBranchId($g['branch_id'] ?? null);
            $trimmedName = (string) ($g['trimmed_name'] ?? '');
            $orderedIds = $this->brands->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
            if (count($orderedIds) < 2) {
                continue;
            }
            $out[] = [
                'branch_id' => $branchId,
                'trimmed_name' => $trimmedName,
                'canonical_id' => $orderedIds[0],
                'noncanonical_ids' => array_values(array_slice($orderedIds, 1)),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{branch_id: ?int, trimmed_name: string, canonical_id: int, noncanonical_ids: list<int>}> $catRows
     * @param list<array{branch_id: ?int, trimmed_name: string, canonical_id: int, noncanonical_ids: list<int>}> $brandRows
     *
     * @return list<array{kind: string, scope: string, trimmed_name: string, canonical_id: int, noncanonical_ids: list<int>, active_product_reference_count: int}>
     */
    private function interleaveDuplicateExamples(array $catRows, array $brandRows): array
    {
        $examples = [];
        $i = 0;
        $j = 0;
        while (count($examples) < self::DUPLICATE_EXAMPLE_CAP) {
            if ($i < count($catRows)) {
                $row = $catRows[$i++];
                $examples[] = [
                    'kind' => 'category',
                    'scope' => $this->scopeLabel($row['branch_id']),
                    'trimmed_name' => $row['trimmed_name'],
                    'canonical_id' => $row['canonical_id'],
                    'noncanonical_ids' => $row['noncanonical_ids'],
                    'active_product_reference_count' => $this->products->countActiveProductsReferencingCategoryIds($row['noncanonical_ids']),
                ];
                if (count($examples) >= self::DUPLICATE_EXAMPLE_CAP) {
                    break;
                }
            }
            if ($j < count($brandRows)) {
                $row = $brandRows[$j++];
                $examples[] = [
                    'kind' => 'brand',
                    'scope' => $this->scopeLabel($row['branch_id']),
                    'trimmed_name' => $row['trimmed_name'],
                    'canonical_id' => $row['canonical_id'],
                    'noncanonical_ids' => $row['noncanonical_ids'],
                    'active_product_reference_count' => $this->products->countActiveProductsReferencingBrandIds($row['noncanonical_ids']),
                ];
                if (count($examples) >= self::DUPLICATE_EXAMPLE_CAP) {
                    break;
                }
            }
            if ($i >= count($catRows) && $j >= count($brandRows)) {
                break;
            }
        }

        return $examples;
    }

    private function nullableBranchId(mixed $branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        return (int) $branchId;
    }

    private function scopeLabel(?int $branchId): string
    {
        if ($branchId === null) {
            return 'global';
        }

        return 'branch:' . $branchId;
    }
}
