<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;
use Modules\Inventory\Repositories\ProductCategoryRepository;

/**
 * Dry-run / guarded apply: for each live multi-node cycle cluster (same semantics as
 * {@see ProductCategoryTreeCycleClusterAuditService}), **NULL** {@see parent_id} on the deterministic
 * break row only (**max** member id). Recomputes clusters at apply time; conditional updates only.
 */
final class ProductCategoryTreeCycleClusterSafeBreakService
{
    private const BREAK_EXAMPLE_CAP = 24;

    public function __construct(
        private Database $db,
        private ProductCategoryRepository $categories,
        private ProductCategoryTreeCycleClusterAuditService $cycleAudit,
    ) {
    }

    /**
     * @return array{
     *     categories_scanned: int,
     *     cycle_cluster_count: int,
     *     candidate_break_count: int,
     *     break_candidates_applied: int,
     *     skipped_due_to_drift_or_missing_cluster: int,
     *     skipped_due_to_row_state_change: int,
     *     clusters_remaining_after_apply: int,
     *     break_examples: list<array<string, mixed>>
     * }
     */
    public function run(bool $apply): array
    {
        $out = [
            'categories_scanned' => 0,
            'cycle_cluster_count' => 0,
            'candidate_break_count' => 0,
            'break_candidates_applied' => 0,
            'skipped_due_to_drift_or_missing_cluster' => 0,
            'skipped_due_to_row_state_change' => 0,
            'clusters_remaining_after_apply' => 0,
            'break_examples' => [],
        ];

        $discovery = $this->cycleAudit->discoverLiveMultiNodeCycleClusters();
        $out['categories_scanned'] = $discovery['categories_scanned'];
        $out['cycle_cluster_count'] = $discovery['cycle_cluster_count'];
        $out['candidate_break_count'] = $discovery['cycle_cluster_count'];
        $out['clusters_remaining_after_apply'] = $discovery['cycle_cluster_count'];

        $rawParentId = $discovery['rawParentId'];
        $pdo = $this->db->connection();

        foreach ($discovery['clusters'] as $comp) {
            $clusterId = 'scc_min_' . min($comp);
            $breakId = max($comp);
            $expectedParent = $rawParentId[$breakId] ?? null;
            $clusterSet = array_fill_keys($comp, true);

            $wouldApply = false;
            if (
                $expectedParent === null
                || $expectedParent <= 0
                || $expectedParent === $breakId
                || empty($clusterSet[$expectedParent])
            ) {
                $out['skipped_due_to_drift_or_missing_cluster']++;
                $this->pushBreakExample($out, $clusterId, $comp, $breakId, $expectedParent, false);
                continue;
            }

            $row = $this->categories->findLiveInResolvedTenantCatalogScope($breakId);
            if ($row === null) {
                $out['skipped_due_to_drift_or_missing_cluster']++;
                $this->pushBreakExample($out, $clusterId, $comp, $breakId, $expectedParent, false);
                continue;
            }

            $dbParent = $this->normalizeParentId($row['parent_id'] ?? null);
            if ($dbParent !== $expectedParent) {
                $out['skipped_due_to_row_state_change']++;
                $this->pushBreakExample($out, $clusterId, $comp, $breakId, $expectedParent, false);
                continue;
            }

            $wouldApply = true;
            $this->pushBreakExample($out, $clusterId, $comp, $breakId, $expectedParent, $wouldApply);

            if (!$apply) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                $affected = $this->categories->clearParentIdForLiveCategoryIfParentMatches($breakId, $expectedParent);
                if ($affected === 1) {
                    $out['break_candidates_applied']++;
                } else {
                    $out['skipped_due_to_drift_or_missing_cluster']++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        if ($apply) {
            $after = $this->cycleAudit->discoverLiveMultiNodeCycleClusters();
            $out['clusters_remaining_after_apply'] = $after['cycle_cluster_count'];
        }

        return $out;
    }

    /**
     * @param list<int> $memberIds
     * @param array<string, mixed> $out
     */
    private function pushBreakExample(
        array &$out,
        string $clusterId,
        array $memberIds,
        int $suggestedBreakCategoryId,
        ?int $suggestedBreakCurrentParentId,
        bool $wouldApply
    ): void {
        if (count($out['break_examples']) >= self::BREAK_EXAMPLE_CAP) {
            return;
        }
        $out['break_examples'][] = [
            'cluster_id' => $clusterId,
            'member_ids' => $memberIds,
            'suggested_break_category_id' => $suggestedBreakCategoryId,
            'suggested_break_current_parent_id' => $suggestedBreakCurrentParentId,
            'would_apply' => $wouldApply,
        ];
    }

    private function normalizeParentId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
