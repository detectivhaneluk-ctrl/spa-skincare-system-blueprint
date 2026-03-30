<?php

declare(strict_types=1);

/**
 * Consolidated read-only category tree recheck after repair slices
 * (PRODUCT-CATEGORY-TREE-POST-REPAIR-CONSOLIDATED-RECHECK-01).
 *
 * Composes integrity + cycle-cluster audits. No writes, no --apply.
 * Optional --json for machine-readable output.
 * Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/audit_product_category_tree_post_repair_consolidated.php
 *   php scripts/audit_product_category_tree_post_repair_consolidated.php --json
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductCategoryTreePostRepairConsolidatedRecheckService;

try {
    $json = in_array('--json', $argv, true);
    $service = app(ProductCategoryTreePostRepairConsolidatedRecheckService::class);
    $summary = $service->run();

    if ($json) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "Consolidated category tree recheck (read-only).\n";
        foreach ($summary as $k => $v) {
            if ($k === 'anomaly_examples' || $k === 'cycle_cluster_examples' || is_array($v)) {
                continue;
            }
            echo sprintf("%s: %s\n", $k, (string) $v);
        }

        if (!empty($summary['anomaly_examples'])) {
            echo "\nAnomaly examples (capped, tree integrity semantics):\n";
            foreach ($summary['anomaly_examples'] as $ex) {
                echo sprintf(
                    "  id=%d name=%s scope=%s parent_id=%d type=%s safe_auto=%s\n",
                    (int) $ex['category_id'],
                    (string) $ex['category_name'],
                    (string) $ex['branch_scope'],
                    (int) $ex['parent_id'],
                    (string) $ex['problem_type'],
                    !empty($ex['safe_to_auto_repair']) ? 'yes' : 'no'
                );
            }
        }

        if (!empty($summary['cycle_cluster_examples'])) {
            echo "\nCycle cluster examples (capped, multi-node SCC semantics):\n";
            foreach ($summary['cycle_cluster_examples'] as $ex) {
                echo sprintf(
                    "  cluster_id=%s scope=%s members=%s break_id=%d fix=%s\n",
                    (string) $ex['cluster_id'],
                    (string) $ex['branch_scope_summary'],
                    implode(',', array_map('strval', $ex['member_ids'])),
                    (int) $ex['suggested_break_category_id'],
                    (string) $ex['suggested_fix']
                );
            }
        }

        echo "\nNo database changes are performed by this script.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
