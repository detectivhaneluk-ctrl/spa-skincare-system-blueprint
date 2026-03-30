<?php

declare(strict_types=1);

/**
 * Audit-only: multi-node cycle clusters in live product_categories parent graph + deterministic fix plan
 * (PRODUCT-CATEGORY-TREE-CYCLE-CLUSTER-AUDIT-AND-FIXPLAN-01).
 *
 * No writes; no --apply. Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/audit_product_category_tree_cycle_clusters.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductCategoryTreeCycleClusterAuditService;

try {
    $service = app(ProductCategoryTreeCycleClusterAuditService::class);
    $summary = $service->run();

    echo "Cycle-cluster audit complete (read-only).\n";
    foreach ($summary as $k => $v) {
        if ($k === 'cycle_cluster_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    if (!empty($summary['cycle_cluster_examples'])) {
        echo "\nCycle cluster examples (capped):\n";
        foreach ($summary['cycle_cluster_examples'] as $ex) {
            echo sprintf(
                "  cluster_id=%s scope=%s members=%s break_id=%s break_parent=%s fix=%s\n",
                (string) $ex['cluster_id'],
                (string) $ex['branch_scope_summary'],
                implode(',', array_map('strval', $ex['member_ids'])),
                (string) $ex['suggested_break_category_id'],
                isset($ex['suggested_break_current_parent_id']) && $ex['suggested_break_current_parent_id'] !== null
                    ? (string) $ex['suggested_break_current_parent_id']
                    : 'null',
                (string) $ex['suggested_fix']
            );
            echo '    parent_map: ';
            $first = true;
            foreach ($ex['current_parent_map'] as $cid => $pid) {
                if (!$first) {
                    echo '; ';
                }
                $first = false;
                echo $cid . '->' . ($pid === null ? 'null' : (string) $pid);
            }
            echo "\n    reason: " . (string) $ex['reason_for_choice'] . "\n";
        }
    }

    echo "\nNo database changes are performed by this script.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
