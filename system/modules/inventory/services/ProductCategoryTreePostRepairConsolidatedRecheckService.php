<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Read-only post-repair checkpoint: one summary from {@see ProductCategoryTreeIntegrityAuditService}
 * (per-row parent anomalies + {@see safe_repair_candidates_count}) and {@see ProductCategoryTreeCycleClusterAuditService}
 * (multi-node SCC clusters + over-cap ancestor walks). No writes.
 *
 * Semantics:
 * - {@see safe_repair_candidates_remaining} == rows still matching safe NULL-{@see parent_id} rules in the integrity audit.
 * - {@see cycle_risk_count} == integrity “cycle_risk” (ancestor-chain / not auto-cleared here).
 * - Cluster counts == cycle-cluster audit (self-parent excluded from SCC graph).
 * - Examples are capped inside each composed service (deterministic order preserved).
 */
final class ProductCategoryTreePostRepairConsolidatedRecheckService
{
    public function __construct(
        private ProductCategoryTreeIntegrityAuditService $treeIntegrity,
        private ProductCategoryTreeCycleClusterAuditService $cycleClusterAudit,
    ) {
    }

    /**
     * @return array{
     *     categories_scanned: int,
     *     live_categories_with_parent_count: int,
     *     missing_or_deleted_parent_count: int,
     *     self_parent_count: int,
     *     scope_invalid_parent_count: int,
     *     cycle_risk_count: int,
     *     cycle_cluster_count: int,
     *     categories_in_cycle_clusters_count: int,
     *     largest_cycle_cluster_size: int,
     *     over_cap_ancestor_walk_count: int,
     *     safe_repair_candidates_remaining: int,
     *     anomaly_examples: list<array<string, mixed>>,
     *     cycle_cluster_examples: list<array<string, mixed>>
     * }
     */
    public function run(): array
    {
        $integrity = $this->treeIntegrity->run(false);
        $cycles = $this->cycleClusterAudit->run();

        return [
            'categories_scanned' => $integrity['categories_scanned'],
            'live_categories_with_parent_count' => $cycles['live_categories_with_parent_count'],
            'missing_or_deleted_parent_count' => $integrity['missing_or_deleted_parent_count'],
            'self_parent_count' => $integrity['self_parent_count'],
            'scope_invalid_parent_count' => $integrity['scope_invalid_parent_count'],
            'cycle_risk_count' => $integrity['cycle_risk_count'],
            'cycle_cluster_count' => $cycles['cycle_cluster_count'],
            'categories_in_cycle_clusters_count' => $cycles['categories_in_cycle_clusters_count'],
            'largest_cycle_cluster_size' => $cycles['largest_cycle_cluster_size'],
            'over_cap_ancestor_walk_count' => $cycles['over_cap_ancestor_walk_count'],
            'safe_repair_candidates_remaining' => $integrity['safe_repair_candidates_count'],
            'anomaly_examples' => $integrity['anomaly_examples'],
            'cycle_cluster_examples' => $cycles['cycle_cluster_examples'],
        ];
    }
}
