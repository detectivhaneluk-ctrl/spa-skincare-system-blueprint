<?php

declare(strict_types=1);

/**
 * Dry-run / guarded apply for multi-node category cycle clusters
 * (PRODUCT-CATEGORY-TREE-CYCLE-CLUSTER-SAFE-BREAK-APPLY-01).
 *
 * Default: dry-run (shows deterministic parent_id NULL breaks). --apply executes conditional clears only.
 * Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/apply_product_category_tree_cycle_cluster_safe_break.php
 *   php scripts/apply_product_category_tree_cycle_cluster_safe_break.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductCategoryTreeCycleClusterSafeBreakService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductCategoryTreeCycleClusterSafeBreakService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
    foreach ($summary as $k => $v) {
        if ($k === 'break_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    if (!empty($summary['break_examples'])) {
        echo "\nBreak examples (capped):\n";
        foreach ($summary['break_examples'] as $ex) {
            $pid = $ex['suggested_break_current_parent_id'];
            echo sprintf(
                "  cluster=%s members=%s break_id=%d break_parent=%s would_apply=%s\n",
                (string) $ex['cluster_id'],
                implode(',', array_map('strval', $ex['member_ids'])),
                (int) $ex['suggested_break_category_id'],
                $pid === null ? 'null' : (string) $pid,
                !empty($ex['would_apply']) ? 'yes' : 'no'
            );
        }
    }

    if (!$apply) {
        echo "\nNo rows were updated. Re-run with --apply to execute conditional parent_id clears.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
