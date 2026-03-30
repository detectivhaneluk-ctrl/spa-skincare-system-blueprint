<?php

declare(strict_types=1);

/**
 * Post–parent/tree cleanup: soft-delete noncanonical duplicate taxonomy rows that are safe to retire
 * (PRODUCT-TAXONOMY-DUPLICATE-NONCANONICAL-POST-TREE-FINALIZATION-01).
 *
 * Run only after:
 *   - relink_product_category_duplicate_parent_canonical.php
 *   - audit_product_category_tree_integrity.php (--apply safe repairs as needed)
 *   - apply_product_category_tree_cycle_cluster_safe_break.php (as needed)
 *
 * Default: dry-run. --apply uses per-row transactions + in-transaction rechecks (same rules as early retire CLI).
 *
 * Usage (from system/):
 *   php scripts/finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php
 *   php scripts/finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
    foreach ($summary as $k => $v) {
        if ($k === 'retirement_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    $catRem = (int) ($summary['category_duplicate_groups_remaining_after_finalization'] ?? 0);
    $brandRem = (int) ($summary['brand_duplicate_groups_remaining_after_finalization'] ?? 0);
    echo sprintf(
        "duplicate_groups_still_remaining_total (category + brand): %d\n",
        $catRem + $brandRem
    );

    if (!empty($summary['retirement_examples'])) {
        echo "\nRetirement examples (capped):\n";
        foreach ($summary['retirement_examples'] as $ex) {
            echo sprintf(
                "  [%s] scope=%s name=%s canonical=%d noncanonical=%d product_refs=%d has_live_children=%s\n",
                (string) $ex['kind'],
                (string) $ex['scope'],
                (string) $ex['trimmed_name'],
                (int) $ex['canonical_id'],
                (int) $ex['noncanonical_id'],
                (int) $ex['active_product_reference_count'],
                !empty($ex['has_live_children']) ? 'yes' : 'no'
            );
        }
    }

    if (!$apply) {
        echo "\nNo taxonomy rows were soft-deleted. Re-run with --apply after consolidated tree recheck.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
