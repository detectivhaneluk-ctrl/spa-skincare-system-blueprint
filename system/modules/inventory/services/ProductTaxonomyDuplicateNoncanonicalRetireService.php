<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;

/**
 * Audit + optional apply: soft-delete noncanonical duplicate live taxonomy rows only when
 * unreferenced (no active products; categories also require no live children). Canonical row never touched.
 */
final class ProductTaxonomyDuplicateNoncanonicalRetireService
{
    private const RETIREMENT_EXAMPLE_CAP = 24;

    public function __construct(
        private Database $db,
        private ProductRepository $products,
        private ProductCategoryRepository $categories,
        private ProductBrandRepository $brands,
        private BranchContext $branchContext,
    ) {
    }

    /**
     * @return array{
     *     category_duplicate_group_count: int,
     *     brand_duplicate_group_count: int,
     *     category_noncanonical_rows_seen: int,
     *     brand_noncanonical_rows_seen: int,
     *     category_retirement_candidates_count: int,
     *     brand_retirement_candidates_count: int,
     *     category_rows_retired: int,
     *     brand_rows_retired: int,
     *     skipped_due_to_active_product_refs: int,
     *     skipped_due_to_child_categories: int,
     *     skipped_due_to_drift_or_missing_group: int,
     *     retirement_examples: list<array<string, mixed>>
     * }
     */
    public function run(bool $apply): array
    {
        $out = [
            'category_duplicate_group_count' => 0,
            'brand_duplicate_group_count' => 0,
            'category_noncanonical_rows_seen' => 0,
            'brand_noncanonical_rows_seen' => 0,
            'category_retirement_candidates_count' => 0,
            'brand_retirement_candidates_count' => 0,
            'category_rows_retired' => 0,
            'brand_rows_retired' => 0,
            'skipped_due_to_active_product_refs' => 0,
            'skipped_due_to_child_categories' => 0,
            'skipped_due_to_drift_or_missing_group' => 0,
            'retirement_examples' => [],
        ];

        /** @var list<array{id: int, scope_branch_id: ?int}> $catCandidateIds */
        $catCandidateIds = [];
        /** @var list<array{id: int, scope_branch_id: ?int}> $brandCandidateIds */
        $brandCandidateIds = [];

        $catSqlGroups = $this->categories->listDuplicateLiveCategoryGroupsByScopeAndTrimmedName();
        $brandSqlGroups = $this->brands->listDuplicateLiveBrandGroupsByScopeAndTrimmedName();

        foreach ($catSqlGroups as $g) {
            $branchId = $this->nullableBranchId($g['branch_id'] ?? null);
            $trimmedName = (string) ($g['trimmed_name'] ?? '');
            $orderedIds = $this->categories->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
            if (count($orderedIds) < 2) {
                $out['skipped_due_to_drift_or_missing_group']++;
                continue;
            }
            $out['category_duplicate_group_count']++;
            $canonicalId = $orderedIds[0];
            $noncanonical = array_values(array_slice($orderedIds, 1));
            foreach ($noncanonical as $nid) {
                $out['category_noncanonical_rows_seen']++;
                $pRef = $this->products->countActiveProductsReferencingCategoryId($nid);
                $childCount = $this->categories->countLiveChildCategoriesWithParentId($nid);
                $this->pushExample($out, [
                    'kind' => 'category',
                    'scope' => $this->scopeLabel($branchId),
                    'trimmed_name' => $trimmedName,
                    'canonical_id' => $canonicalId,
                    'noncanonical_id' => $nid,
                    'active_product_reference_count' => $pRef,
                    'has_live_children' => $childCount > 0,
                ]);
                if ($pRef > 0) {
                    $out['skipped_due_to_active_product_refs']++;
                } elseif ($childCount > 0) {
                    $out['skipped_due_to_child_categories']++;
                } else {
                    $out['category_retirement_candidates_count']++;
                    $catCandidateIds[] = ['id' => $nid, 'scope_branch_id' => $branchId];
                }
            }
        }

        foreach ($brandSqlGroups as $g) {
            $branchId = $this->nullableBranchId($g['branch_id'] ?? null);
            $trimmedName = (string) ($g['trimmed_name'] ?? '');
            $orderedIds = $this->brands->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
            if (count($orderedIds) < 2) {
                $out['skipped_due_to_drift_or_missing_group']++;
                continue;
            }
            $out['brand_duplicate_group_count']++;
            $canonicalId = $orderedIds[0];
            $noncanonical = array_values(array_slice($orderedIds, 1));
            foreach ($noncanonical as $nid) {
                $out['brand_noncanonical_rows_seen']++;
                $pRef = $this->products->countActiveProductsReferencingBrandId($nid);
                $this->pushExample($out, [
                    'kind' => 'brand',
                    'scope' => $this->scopeLabel($branchId),
                    'trimmed_name' => $trimmedName,
                    'canonical_id' => $canonicalId,
                    'noncanonical_id' => $nid,
                    'active_product_reference_count' => $pRef,
                    'has_live_children' => false,
                ]);
                if ($pRef > 0) {
                    $out['skipped_due_to_active_product_refs']++;
                } else {
                    $out['brand_retirement_candidates_count']++;
                    $brandCandidateIds[] = ['id' => $nid, 'scope_branch_id' => $branchId];
                }
            }
        }

        if (!$apply) {
            return $out;
        }

        $pdo = $this->db->connection();
        foreach ($catCandidateIds as $cand) {
            $cid = $cand['id'];
            $pdo->beginTransaction();
            try {
                if (!$this->categoryIsLiveNoncanonicalInDuplicateGroup($cid, $cand['scope_branch_id'] ?? null)) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                if ($this->products->countActiveProductsReferencingCategoryId($cid) > 0) {
                    $out['skipped_due_to_active_product_refs']++;
                    $pdo->rollBack();
                    continue;
                }
                if ($this->categories->countLiveChildCategoriesWithParentId($cid) > 0) {
                    $out['skipped_due_to_child_categories']++;
                    $pdo->rollBack();
                    continue;
                }
                if ($this->categories->softDeleteLiveInResolvedTenantCatalogScope($cid) !== 1) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                $out['category_rows_retired']++;
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        foreach ($brandCandidateIds as $cand) {
            $bid = $cand['id'];
            $pdo->beginTransaction();
            try {
                if (!$this->brandIsLiveNoncanonicalInDuplicateGroup($bid, $cand['scope_branch_id'] ?? null)) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                if ($this->products->countActiveProductsReferencingBrandId($bid) > 0) {
                    $out['skipped_due_to_active_product_refs']++;
                    $pdo->rollBack();
                    continue;
                }
                if ($this->brands->softDeleteLiveInResolvedTenantCatalogScope($bid) !== 1) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                $out['brand_rows_retired']++;
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $out
     * @param array<string, mixed> $example
     */
    private function pushExample(array &$out, array $example): void
    {
        if (count($out['retirement_examples']) >= self::RETIREMENT_EXAMPLE_CAP) {
            return;
        }
        $out['retirement_examples'][] = $example;
    }

    private function categoryIsLiveNoncanonicalInDuplicateGroup(int $id, ?int $groupScopeBranchId): bool
    {
        $row = $this->loadCategoryRowForTaxonomyDuplicate($id, $groupScopeBranchId);
        if ($row === null) {
            return false;
        }
        $branchId = $this->nullableBranchId($row['branch_id'] ?? null);
        $trimmedName = trim((string) ($row['name'] ?? ''));
        $orderedIds = $this->categories->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
        if (count($orderedIds) < 2) {
            return false;
        }
        $canonicalId = $orderedIds[0];
        if ($id === $canonicalId) {
            return false;
        }
        $noncanonical = array_slice($orderedIds, 1);

        return in_array($id, $noncanonical, true);
    }

    private function brandIsLiveNoncanonicalInDuplicateGroup(int $id, ?int $groupScopeBranchId): bool
    {
        $row = $this->loadBrandRowForTaxonomyDuplicate($id, $groupScopeBranchId);
        if ($row === null) {
            return false;
        }
        $branchId = $this->nullableBranchId($row['branch_id'] ?? null);
        $trimmedName = trim((string) ($row['name'] ?? ''));
        $orderedIds = $this->brands->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
        if (count($orderedIds) < 2) {
            return false;
        }
        $canonicalId = $orderedIds[0];
        if ($id === $canonicalId) {
            return false;
        }
        $noncanonical = array_slice($orderedIds, 1);

        return in_array($id, $noncanonical, true);
    }

    private function loadCategoryRowForTaxonomyDuplicate(int $id, ?int $groupScopeBranchId): ?array
    {
        if ($groupScopeBranchId !== null && $groupScopeBranchId > 0) {
            return $this->categories->findInTenantScope($id, $groupScopeBranchId);
        }
        $op = $this->branchContext->getCurrentBranchId();
        if ($op !== null && $op > 0) {
            return $this->categories->findInTenantScope($id, $op);
        }

        return $this->categories->findLiveInResolvedTenantCatalogScope($id);
    }

    private function loadBrandRowForTaxonomyDuplicate(int $id, ?int $groupScopeBranchId): ?array
    {
        if ($groupScopeBranchId !== null && $groupScopeBranchId > 0) {
            return $this->brands->findInTenantScope($id, $groupScopeBranchId);
        }
        $op = $this->branchContext->getCurrentBranchId();
        if ($op !== null && $op > 0) {
            return $this->brands->findInTenantScope($id, $op);
        }

        return $this->brands->findLiveInResolvedTenantCatalogScope($id);
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
