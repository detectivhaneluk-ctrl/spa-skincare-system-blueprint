<?php

declare(strict_types=1);

/**
 * Audit + optional soft-delete of safe noncanonical duplicate taxonomy rows
 * (PRODUCT-TAXONOMY-DUPLICATE-NONCANONICAL-RETIRE-01).
 *
 * Default: dry-run. --apply soft-deletes only when no active product refs (and no live child categories for categories).
 * Category rows with live children are skipped; after duplicate-parent relink + tree repair + cycle safe-break, run
 * scripts/finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php for the last retire pass.
 * Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/retire_product_taxonomy_duplicate_noncanonical.php
 *   php scripts/retire_product_taxonomy_duplicate_noncanonical.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductTaxonomyDuplicateNoncanonicalRetireService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductTaxonomyDuplicateNoncanonicalRetireService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
    foreach ($summary as $k => $v) {
        if ($k === 'retirement_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

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
        echo "\nNo taxonomy rows were soft-deleted. Re-run with --apply after product FK canonical relink if desired;\n";
        echo "for duplicate categories that were parents, finish category tree cleanup then run finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
