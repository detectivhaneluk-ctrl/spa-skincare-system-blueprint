<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductCategoryRepository;

/**
 * Audit + optional apply: relink live child {@see product_categories}.parent_id from noncanonical
 * duplicate rows to the canonical row (lowest id per scope + TRIM(name)). No soft-delete; no product FK changes.
 */
final class ProductCategoryDuplicateParentCanonicalRelinkService
{
    private const RELINK_EXAMPLE_CAP = 24;

    public function __construct(
        private Database $db,
        private ProductCategoryRepository $categories,
        private BranchContext $branchContext,
    ) {
    }

    /**
     * @return array{
     *     duplicate_category_group_count: int,
     *     noncanonical_category_rows_seen: int,
     *     live_child_parent_refs_to_noncanonical_count: int,
     *     relink_candidates_count: int,
     *     parent_refs_relinked: int,
     *     skipped_due_to_cycle_risk: int,
     *     skipped_due_to_drift_or_missing_group: int,
     *     relink_examples: list<array<string, mixed>>
     * }
     */
    public function run(bool $apply): array
    {
        $out = [
            'duplicate_category_group_count' => 0,
            'noncanonical_category_rows_seen' => 0,
            'live_child_parent_refs_to_noncanonical_count' => 0,
            'relink_candidates_count' => 0,
            'parent_refs_relinked' => 0,
            'skipped_due_to_cycle_risk' => 0,
            'skipped_due_to_drift_or_missing_group' => 0,
            'relink_examples' => [],
        ];

        /** @var list<array{child_id: int, noncanonical_id: int, group_scope_branch_id: ?int}> */
        $candidates = [];

        $groups = $this->categories->listDuplicateLiveCategoryGroupsByScopeAndTrimmedName();
        foreach ($groups as $g) {
            $branchId = $this->nullableBranchId($g['branch_id'] ?? null);
            $trimmedName = trim((string) ($g['trimmed_name'] ?? ''));
            $orderedIds = $this->categories->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
            if (count($orderedIds) < 2) {
                $out['skipped_due_to_drift_or_missing_group']++;
                continue;
            }
            $out['duplicate_category_group_count']++;
            $canonicalId = $orderedIds[0];
            $noncanonical = array_values(array_slice($orderedIds, 1));
            foreach ($noncanonical as $ncId) {
                $out['noncanonical_category_rows_seen']++;
                $children = $this->categories->listLiveChildrenByParentId($ncId);
                foreach ($children as $child) {
                    $childId = (int) $child['id'];
                    $childName = (string) ($child['name'] ?? '');
                    $out['live_child_parent_refs_to_noncanonical_count']++;
                    $wouldCycle = $this->assigningParentWouldCreateCycle($childId, $canonicalId, $branchId);
                    $this->pushExample($out, [
                        'scope' => $this->scopeLabel($branchId),
                        'trimmed_name' => $trimmedName,
                        'canonical_id' => $canonicalId,
                        'noncanonical_id' => $ncId,
                        'child_category_id' => $childId,
                        'child_category_name' => $childName,
                        'would_create_cycle' => $wouldCycle,
                    ]);
                    if ($wouldCycle) {
                        $out['skipped_due_to_cycle_risk']++;
                    } else {
                        $out['relink_candidates_count']++;
                        $candidates[] = [
                            'child_id' => $childId,
                            'noncanonical_id' => $ncId,
                            'group_scope_branch_id' => $branchId,
                        ];
                    }
                }
            }
        }

        if (!$apply) {
            return $out;
        }

        $pdo = $this->db->connection();
        foreach ($candidates as $c) {
            $pdo->beginTransaction();
            try {
                $resolved = $this->resolveNoncanonicalDuplicateContext(
                    $c['noncanonical_id'],
                    $c['group_scope_branch_id'] ?? null
                );
                if ($resolved === null) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                $canonicalId = $resolved['canonical_id'];
                $ncId = $c['noncanonical_id'];

                $childRow = $this->loadCategoryRowForRelink($c['child_id'], $c['group_scope_branch_id'] ?? null);
                if ($childRow === null) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                $currentParent = $childRow['parent_id'] ?? null;
                $currentParentId = ($currentParent !== null && $currentParent !== '') ? (int) $currentParent : null;
                if ($currentParentId !== $ncId) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }

                if ($this->assigningParentWouldCreateCycle(
                    $c['child_id'],
                    $canonicalId,
                    $c['group_scope_branch_id'] ?? null
                )) {
                    $out['skipped_due_to_cycle_risk']++;
                    $pdo->rollBack();
                    continue;
                }

                $affected = $this->categories->reassignParentIdForLiveCategoryIfMatches(
                    $c['child_id'],
                    $ncId,
                    $canonicalId
                );
                if ($affected !== 1) {
                    $out['skipped_due_to_drift_or_missing_group']++;
                    $pdo->rollBack();
                    continue;
                }
                $out['parent_refs_relinked']++;
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
     * True if setting {@param $childCategoryId}'s parent to {@param $newParentId} would be cyclic or self-parent.
     * Reuses the same rule as {@see ProductCategoryRepository::assertValidParentAssignment} (ancestor walk).
     */
    private function assigningParentWouldCreateCycle(int $childCategoryId, int $newParentId, ?int $groupScopeBranchId): bool
    {
        if ($newParentId === $childCategoryId) {
            return true;
        }
        $op = ($groupScopeBranchId !== null && $groupScopeBranchId > 0)
            ? $groupScopeBranchId
            : $this->branchContext->getCurrentBranchId();
        if ($op !== null && $op > 0) {
            return $this->categories->ancestorChainContainsIdInTenantScope($newParentId, $childCategoryId, $op);
        }

        return $this->categories->ancestorChainContainsIdInResolvedTenantCatalogScope($newParentId, $childCategoryId);
    }

    /**
     * @return array{canonical_id: int}|null
     */
    private function resolveNoncanonicalDuplicateContext(int $noncanonicalId, ?int $groupScopeBranchId): ?array
    {
        $row = $this->loadCategoryRowForRelink($noncanonicalId, $groupScopeBranchId);
        if ($row === null) {
            return null;
        }
        $branchId = $this->nullableBranchId($row['branch_id'] ?? null);
        $trimmedName = trim((string) ($row['name'] ?? ''));
        $orderedIds = $this->categories->listLiveIdsByScopeAndTrimmedName($branchId, $trimmedName);
        if (count($orderedIds) < 2) {
            return null;
        }
        $canonicalId = $orderedIds[0];
        if ($noncanonicalId === $canonicalId) {
            return null;
        }
        $rest = array_slice($orderedIds, 1);

        return in_array($noncanonicalId, $rest, true) ? ['canonical_id' => $canonicalId] : null;
    }

    private function loadCategoryRowForRelink(int $id, ?int $groupScopeBranchId): ?array
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

    /**
     * @param array<string, mixed> $out
     * @param array<string, mixed> $example
     */
    private function pushExample(array &$out, array $example): void
    {
        if (count($out['relink_examples']) >= self::RELINK_EXAMPLE_CAP) {
            return;
        }
        $out['relink_examples'][] = $example;
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
