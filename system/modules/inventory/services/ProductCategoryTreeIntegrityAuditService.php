<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Modules\Inventory\Repositories\ProductCategoryRepository;

/**
 * Audit + optional safe repair for live {@see product_categories} parent_id integrity.
 * Matches {@see ProductCategoryService} parent branch rules; cycle detection uses
 * {@see ProductCategoryRepository::ancestorChainContainsIdInTenantScope} when a branch anchor resolves.
 * Broader cycles are reported only — cleared only via the same NULL rules as missing/self/scope (never rewired).
 */
final class ProductCategoryTreeIntegrityAuditService
{
    private const ANOMALY_EXAMPLE_CAP = 24;

    public function __construct(
        private Database $db,
        private ProductCategoryRepository $categories,
        private BranchContext $branchContext,
    ) {
    }

    /**
     * @return array{
     *     categories_scanned: int,
     *     missing_or_deleted_parent_count: int,
     *     self_parent_count: int,
     *     scope_invalid_parent_count: int,
     *     cycle_risk_count: int,
     *     safe_repair_candidates_count: int,
     *     skipped_manual_cycle_fix_count: int,
     *     parent_refs_cleared: int,
     *     skipped_due_to_drift_count: int,
     *     anomaly_examples: list<array<string, mixed>>
     * }
     */
    public function run(bool $apply): array
    {
        $out = [
            'categories_scanned' => 0,
            'missing_or_deleted_parent_count' => 0,
            'self_parent_count' => 0,
            'scope_invalid_parent_count' => 0,
            'cycle_risk_count' => 0,
            'safe_repair_candidates_count' => 0,
            'skipped_manual_cycle_fix_count' => 0,
            'parent_refs_cleared' => 0,
            'skipped_due_to_drift_count' => 0,
            'anomaly_examples' => [],
        ];

        $liveRows = $this->categories->listAllLiveInResolvedTenantCatalogScope();
        $out['categories_scanned'] = count($liveRows);

        $anchorBranchId = $this->resolveTreeAuditAnchorBranchId($liveRows);

        /** @var list<array{category_id: int, expected_parent_id: int}> */
        $repairQueue = [];

        foreach ($liveRows as $row) {
            $categoryId = (int) $row['id'];
            $pidRaw = $row['parent_id'] ?? null;
            $parentId = $this->normalizeParentIdValue($pidRaw);

            if ($parentId === null) {
                continue;
            }

            $analysis = $this->classifyParentAnomaly($categoryId, $parentId, $row, $anchorBranchId);
            if ($analysis === null) {
                continue;
            }

            $problemType = $analysis['problem_type'];
            $safe = $analysis['safe_repair'];
            $expectedPid = $analysis['expected_parent_id'];

            match ($problemType) {
                'missing_parent', 'deleted_parent' => $out['missing_or_deleted_parent_count']++,
                'self_parent' => $out['self_parent_count']++,
                'scope_invalid_parent' => $out['scope_invalid_parent_count']++,
                'cycle_risk' => $out['cycle_risk_count']++,
                default => null,
            };

            if ($problemType === 'cycle_risk') {
                $out['skipped_manual_cycle_fix_count']++;
            }

            if ($safe) {
                $out['safe_repair_candidates_count']++;
                $repairQueue[] = ['category_id' => $categoryId, 'expected_parent_id' => $expectedPid];
            }

            $this->pushExample($out, $this->buildExampleRow(
                $categoryId,
                (string) ($row['name'] ?? ''),
                $this->categoryBranchId($row),
                $parentId,
                $problemType,
                $safe
            ));
        }

        if (!$apply) {
            return $out;
        }

        $pdo = $this->db->connection();
        foreach ($repairQueue as $item) {
            $pdo->beginTransaction();
            try {
                $affected = $this->categories->clearParentIdForLiveCategoryIfParentMatches(
                    $item['category_id'],
                    $item['expected_parent_id']
                );
                if ($affected === 1) {
                    $out['parent_refs_cleared']++;
                } else {
                    $out['skipped_due_to_drift_count']++;
                }
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
     * @return array{problem_type: string, safe_repair: bool, expected_parent_id: int}|null
     */
    private function classifyParentAnomaly(int $categoryId, int $parentId, array $childRow, int $anchorBranchId): ?array
    {
        if ($parentId <= 0) {
            return [
                'problem_type' => 'missing_parent',
                'safe_repair' => true,
                'expected_parent_id' => $parentId,
            ];
        }

        if ($parentId === $categoryId) {
            return [
                'problem_type' => 'self_parent',
                'safe_repair' => true,
                'expected_parent_id' => $parentId,
            ];
        }

        $parentAny = $this->categories->rowByIdIncludingDeleted($parentId);
        if ($parentAny === null) {
            return [
                'problem_type' => 'missing_parent',
                'safe_repair' => true,
                'expected_parent_id' => $parentId,
            ];
        }

        $del = $parentAny['deleted_at'] ?? null;
        if ($del !== null && $del !== '') {
            return [
                'problem_type' => 'deleted_parent',
                'safe_repair' => true,
                'expected_parent_id' => $parentId,
            ];
        }

        $parentLive = $this->categories->findLiveInResolvedTenantCatalogScope($parentId);
        if ($parentLive === null) {
            return [
                'problem_type' => 'deleted_parent',
                'safe_repair' => true,
                'expected_parent_id' => $parentId,
            ];
        }

        if ($this->parentBranchScopeInvalidForChild($this->categoryBranchId($childRow), $parentLive)) {
            return [
                'problem_type' => 'scope_invalid_parent',
                'safe_repair' => true,
                'expected_parent_id' => $parentId,
            ];
        }

        if ($this->categories->ancestorChainContainsIdInTenantScope($parentId, $categoryId, $anchorBranchId)) {
            return [
                'problem_type' => 'cycle_risk',
                'safe_repair' => false,
                'expected_parent_id' => $parentId,
            ];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $liveRows
     */
    private function resolveTreeAuditAnchorBranchId(array $liveRows): int
    {
        foreach ($liveRows as $r) {
            $b = $this->categoryBranchId($r);
            if ($b !== null && $b > 0) {
                return $b;
            }
        }
        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx !== null && $ctx > 0) {
            return $ctx;
        }
        $any = $this->categories->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $any;
        }

        throw new \DomainException(
            'Cannot resolve a branch anchor for category tree audit (no branch-scoped categories, no session branch, and no live branches in the resolved organization).'
        );
    }

    /**
     * Same semantics as {@see ProductCategoryService::assertParentBranchScope} (invalid when true).
     *
     * @param array<string, mixed> $liveParentRow
     */
    private function parentBranchScopeInvalidForChild(?int $childBranchId, array $liveParentRow): bool
    {
        $pBranch = $liveParentRow['branch_id'] ?? null;
        $pBranch = ($pBranch !== null && $pBranch !== '') ? (int) $pBranch : null;
        if ($pBranch === null) {
            return false;
        }
        if ($childBranchId === null) {
            return true;
        }

        return $childBranchId !== $pBranch;
    }

    /**
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private function buildExampleRow(
        int $categoryId,
        string $categoryName,
        ?int $childBranchId,
        int $parentId,
        string $problemType,
        bool $safeToAutoRepair
    ): array {
        $parentName = '';
        $parentRow = $this->categories->rowByIdIncludingDeleted($parentId);
        if ($parentRow !== null) {
            $parentName = (string) ($parentRow['name'] ?? '');
        }

        return [
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'branch_scope' => $this->scopeLabel($childBranchId),
            'parent_id' => $parentId,
            'parent_name' => $parentName,
            'problem_type' => $problemType,
            'safe_to_auto_repair' => $safeToAutoRepair,
        ];
    }

    /**
     * @param array<string, mixed> $out
     * @param array<string, mixed> $example
     */
    private function pushExample(array &$out, array $example): void
    {
        if (count($out['anomaly_examples']) >= self::ANOMALY_EXAMPLE_CAP) {
            return;
        }
        $out['anomaly_examples'][] = $example;
    }

    private function normalizeParentIdValue(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function categoryBranchId(array $row): ?int
    {
        $b = $row['branch_id'] ?? null;

        return ($b !== null && $b !== '') ? (int) $b : null;
    }

    private function scopeLabel(?int $branchId): string
    {
        if ($branchId === null) {
            return 'global';
        }

        return 'branch:' . $branchId;
    }
}
