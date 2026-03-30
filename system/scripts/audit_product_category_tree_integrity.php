<?php

declare(strict_types=1);

/**
 * Audit + optional safe repair of live product_categories.parent_id integrity
 * (PRODUCT-CATEGORY-TREE-INTEGRITY-AUDIT-AND-SAFE-REPAIR-01).
 *
 * Default: dry-run. --apply NULLs parent_id only for missing/deleted/self/scope-invalid parents (not multi-node cycles).
 * Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/audit_product_category_tree_integrity.php
 *   php scripts/audit_product_category_tree_integrity.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductCategoryTreeIntegrityAuditService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductCategoryTreeIntegrityAuditService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
    foreach ($summary as $k => $v) {
        if ($k === 'anomaly_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    if (!empty($summary['anomaly_examples'])) {
        echo "\nAnomaly examples (capped):\n";
        foreach ($summary['anomaly_examples'] as $ex) {
            echo sprintf(
                "  id=%d name=%s scope=%s parent_id=%d parent_name=%s type=%s safe_auto=%s\n",
                (int) $ex['category_id'],
                (string) $ex['category_name'],
                (string) $ex['branch_scope'],
                (int) $ex['parent_id'],
                (string) $ex['parent_name'],
                (string) $ex['problem_type'],
                !empty($ex['safe_to_auto_repair']) ? 'yes' : 'no'
            );
        }
    }

    if (!$apply) {
        echo "\nNo rows were updated. Re-run with --apply to clear only safe invalid parent_id values.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
