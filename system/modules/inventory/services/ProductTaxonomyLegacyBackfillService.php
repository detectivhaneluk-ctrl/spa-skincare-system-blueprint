<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;

/**
 * Idempotent backfill: legacy {@see products.category} / {@see products.brand} → scoped {@see product_categories} /
 * {@see product_brands} rows + {@see products.product_category_id} / {@see products.product_brand_id}.
 *
 * Does not modify legacy VARCHAR columns. Does not link branch products to global taxonomy rows.
 */
final class ProductTaxonomyLegacyBackfillService
{
    private const ANOMALY_EXAMPLE_CAP = 40;

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
     *     categories_created: int,
     *     brands_created: int,
     *     product_category_links_filled: int,
     *     product_brand_links_filled: int,
     *     skipped_already_linked: int,
     *     skipped_empty_legacy_values: int,
     *     anomalies_fk_to_deleted_category: int,
     *     anomalies_fk_to_deleted_brand: int,
     *     anomaly_examples: list<array{product_id: int, field: string, fk_id: int}>,
     *     canonical_category_links_in_duplicate_trim_groups: int,
     *     canonical_brand_links_in_duplicate_trim_groups: int
     * }
     */
    public function run(bool $apply): array
    {
        $out = [
            'products_scanned' => 0,
            'categories_created' => 0,
            'brands_created' => 0,
            'product_category_links_filled' => 0,
            'product_brand_links_filled' => 0,
            'skipped_already_linked' => 0,
            'skipped_empty_legacy_values' => 0,
            'anomalies_fk_to_deleted_category' => 0,
            'anomalies_fk_to_deleted_brand' => 0,
            'anomaly_examples' => [],
            'canonical_category_links_in_duplicate_trim_groups' => 0,
            'canonical_brand_links_in_duplicate_trim_groups' => 0,
        ];

        $rows = $this->products->listNonDeletedForTaxonomyBackfillInResolvedTenantCatalog();
        $pdo = $this->db->connection();
        /** @var array<string, true> $dryVirtCat */
        $dryVirtCat = [];
        /** @var array<string, true> $dryVirtBrand */
        $dryVirtBrand = [];

        foreach ($rows as $row) {
            $out['products_scanned']++;
            $productId = (int) $row['id'];
            $productBranchId = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null;

            $legacyCat = trim((string) ($row['category'] ?? ''));
            $legacyBrand = trim((string) ($row['brand'] ?? ''));

            $pcid = $this->nullableFk($row['product_category_id'] ?? null);
            $pbid = $this->nullableFk($row['product_brand_id'] ?? null);

            $fillCat = false;
            $fillBrand = false;
            $catTargetId = null;
            $brandTargetId = null;
            $wouldCreateCat = false;
            $wouldCreateBrand = false;

            if ($pcid !== null) {
                $meta = $this->categories->rowByIdIncludingDeleted($pcid);
                if ($meta === null || $this->isDeletedAt($meta['deleted_at'] ?? null)) {
                    $out['anomalies_fk_to_deleted_category']++;
                    $this->pushAnomaly($out, $productId, 'product_category_id', $pcid);
                } else {
                    $out['skipped_already_linked']++;
                }
            } elseif ($legacyCat === '') {
                $out['skipped_empty_legacy_values']++;
            } else {
                $fillCat = true;
                $existing = $this->categories->findCanonicalLiveByScopeAndTrimmedName($productBranchId, $legacyCat);
                $virtKeyCat = ($productBranchId === null ? 'g' : (string) $productBranchId) . "\0" . $legacyCat;
                if ($existing !== null) {
                    $catTargetId = (int) $existing['id'];
                    if ($this->categories->countLiveByScopeAndTrimmedName($productBranchId, $legacyCat) > 1) {
                        $out['canonical_category_links_in_duplicate_trim_groups']++;
                    }
                } elseif (!$apply && isset($dryVirtCat[$virtKeyCat])) {
                    $wouldCreateCat = false;
                } else {
                    if (!$apply) {
                        $dryVirtCat[$virtKeyCat] = true;
                    }
                    $wouldCreateCat = true;
                    $out['categories_created']++;
                }
                $out['product_category_links_filled']++;
            }

            if ($pbid !== null) {
                $meta = $this->brands->rowByIdIncludingDeleted($pbid);
                if ($meta === null || $this->isDeletedAt($meta['deleted_at'] ?? null)) {
                    $out['anomalies_fk_to_deleted_brand']++;
                    $this->pushAnomaly($out, $productId, 'product_brand_id', $pbid);
                } else {
                    $out['skipped_already_linked']++;
                }
            } elseif ($legacyBrand === '') {
                $out['skipped_empty_legacy_values']++;
            } else {
                $fillBrand = true;
                $existing = $this->brands->findCanonicalLiveByScopeAndTrimmedName($productBranchId, $legacyBrand);
                $virtKeyBrand = ($productBranchId === null ? 'g' : (string) $productBranchId) . "\0" . $legacyBrand;
                if ($existing !== null) {
                    $brandTargetId = (int) $existing['id'];
                    if ($this->brands->countLiveByScopeAndTrimmedName($productBranchId, $legacyBrand) > 1) {
                        $out['canonical_brand_links_in_duplicate_trim_groups']++;
                    }
                } elseif (!$apply && isset($dryVirtBrand[$virtKeyBrand])) {
                    $wouldCreateBrand = false;
                } else {
                    if (!$apply) {
                        $dryVirtBrand[$virtKeyBrand] = true;
                    }
                    $wouldCreateBrand = true;
                    $out['brands_created']++;
                }
                $out['product_brand_links_filled']++;
            }

            if (!$apply || (!$fillCat && !$fillBrand)) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                if ($fillCat) {
                    if ($wouldCreateCat) {
                        $again = $this->categories->findCanonicalLiveByScopeAndTrimmedName($productBranchId, $legacyCat);
                        if ($again !== null) {
                            $catTargetId = (int) $again['id'];
                        } else {
                            $catTargetId = $this->categories->create([
                                'name' => $legacyCat,
                                'sort_order' => 0,
                                'branch_id' => $productBranchId,
                                'parent_id' => null,
                            ]);
                        }
                    }
                }
                if ($fillBrand) {
                    if ($wouldCreateBrand) {
                        $brandTargetId = $this->createBrandOrReuse($productBranchId, $legacyBrand);
                    }
                }

                $patch = [];
                if ($fillCat && $catTargetId !== null) {
                    $patch['product_category_id'] = $catTargetId;
                }
                if ($fillBrand && $brandTargetId !== null) {
                    $patch['product_brand_id'] = $brandTargetId;
                }
                if ($patch !== []) {
                    $this->products->updateTaxonomyFkPatchInResolvedTenantCatalog($productId, $patch);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return $out;
    }

    private function nullableFk(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    private function isDeletedAt(mixed $deletedAt): bool
    {
        return $deletedAt !== null && $deletedAt !== '';
    }

    /**
     * @param array<string, mixed> $out
     */
    private function pushAnomaly(array &$out, int $productId, string $field, int $fkId): void
    {
        if (count($out['anomaly_examples']) >= self::ANOMALY_EXAMPLE_CAP) {
            return;
        }
        $out['anomaly_examples'][] = [
            'product_id' => $productId,
            'field' => $field,
            'fk_id' => $fkId,
        ];
    }

    private function createBrandOrReuse(?int $productBranchId, string $name): int
    {
        $again = $this->brands->findCanonicalLiveByScopeAndTrimmedName($productBranchId, $name);
        if ($again !== null) {
            return (int) $again['id'];
        }
        try {
            return $this->brands->create([
                'name' => $name,
                'sort_order' => 0,
                'branch_id' => $productBranchId,
            ]);
        } catch (\PDOException $e) {
            $existing = $this->brands->findCanonicalLiveByScopeAndTrimmedName($productBranchId, $name);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            throw $e;
        }
    }
}
