<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;

/**
 * Final pass: same safety rules as {@see ProductTaxonomyDuplicateNoncanonicalRetireService}, intended to run only
 * after duplicate-parent canonical relink, full-tree safe repair, and cycle-cluster safe break so noncanonical
 * category rows that were blocked by live children can become retireable. Dry-run by default; apply uses per-row
 * transactions with in-transaction rechecks.
 */
final class ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService
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
     *     category_duplicate_groups_scanned: int,
     *     brand_duplicate_groups_scanned: int,
     *     newly_safe_category_retire_candidates: int,
     *     newly_safe_brand_retire_candidates: int,
     *     category_rows_retired: int,
     *     brand_rows_retired: int,
     *     skipped_due_to_active_product_refs: int,
     *     skipped_due_to_live_child_categories: int,
     *     skipped_due_to_drift_or_missing_group: int,
     *     category_duplicate_groups_remaining_after_finalization: int,
     *     brand_duplicate_groups_remaining_after_finalization: int,
     *     retirement_examples: list<array<string, mixed>>
     * }
     */
    public function run(bool $apply): array
    {
        $out = [
            'category_duplicate_groups_scanned' => 0,
            'brand_duplicate_groups_scanned' => 0,
            'newly_safe_category_retire_candidates' => 0,
            'newly_safe_brand_retire_candidates' => 0,
            'category_rows_retired' => 0,
            'brand_rows_retired' => 0,
            'skipped_due_to_active_product_refs' => 0,
            'skipped_due_to_live_child_categories' => 0,
            'skipped_due_to_drift_or_missing_group' => 0,
            'category_duplicate_groups_remaining_after_finalization' => 0,
            'brand_duplicate_groups_remaining_after_finalization' => 0,
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
            $out['category_duplicate_groups_scanned']++;
            $canonicalId = $orderedIds[0];
            $noncanonical = array_values(array_slice($orderedIds, 1));
            foreach ($noncanonical as $nid) {
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
                    $out['skipped_due_to_live_child_categories']++;
                } else {
                    $out['newly_safe_category_retire_candidates']++;
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
            $out['brand_duplicate_groups_scanned']++;
            $canonicalId = $orderedIds[0];
            $noncanonical = array_values(array_slice($orderedIds, 1));
            foreach ($noncanonical as $nid) {
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
                    $out['newly_safe_brand_retire_candidates']++;
                    $brandCandidateIds[] = ['id' => $nid, 'scope_branch_id' => $branchId];
                }
            }
        }

        $catCandSet = array_fill_keys(array_map(static fn (array $c): int => (int) $c['id'], $catCandidateIds), true);
        $brandCandSet = array_fill_keys(array_map(static fn (array $c): int => (int) $c['id'], $brandCandidateIds), true);

        if ($apply) {
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
                        $out['skipped_due_to_live_child_categories']++;
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

            $out['category_duplicate_groups_remaining_after_finalization'] = count(
                $this->categories->listDuplicateLiveCategoryGroupsByScopeAndTrimmedName()
            );
            $out['brand_duplicate_groups_remaining_after_finalization'] = count(
                $this->brands->listDuplicateLiveBrandGroupsByScopeAndTrimmedName()
            );
        } else {
            $out['category_duplicate_groups_remaining_after_finalization'] = $this->countRemainingDuplicateGroupsAfterSimulatedRetire(
                $catSqlGroups,
                true,
                $catCandSet
            );
            $out['brand_duplicate_groups_remaining_after_finalization'] = $this->countRemainingDuplicateGroupsAfterSimulatedRetire(
                $brandSqlGroups,
                false,
                $brandCandSet
            );
        }

        return $out;
    }

    /**
     * @param list<array{branch_id: int|string|null, trimmed_name: string, cnt: int|string}> $groups
     * @param array<int, true> $retireIdSet
     */
    private function countRemainingDuplicateGroupsAfterSimulatedRetire(array $groups, bool $isCategory, array $retireIdSet): int
    {
        $remaining = 0;
        foreach ($groups as $g) {
            $branchId = $this->nullableBranchId($g['branch_id'] ?? null);
            $trimmedName = (string) ($g['trimmed_name'] ?? '');
            $orderedIds = $isCategory
                ? $this->categories->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName)
                : $this->brands->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
            if (count($orderedIds) < 2) {
                continue;
            }
            $after = [];
            foreach ($orderedIds as $id) {
                if (!isset($retireIdSet[(int) $id])) {
                    $after[] = (int) $id;
                }
            }
            if (count($after) >= 2) {
                $remaining++;
            }
        }

        return $remaining;
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
