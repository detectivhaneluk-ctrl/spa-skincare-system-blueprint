<?php

declare(strict_types=1);

/**
 * Audit + optional relink of live child product_categories.parent_id from noncanonical duplicate
 * category rows to the canonical row (PRODUCT-CATEGORY-DUPLICATE-PARENT-CANONICAL-RELINK-01).
 *
 * Default: dry-run. --apply updates parent_id only (no taxonomy soft-delete, no product FK changes).
 * Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/relink_product_category_duplicate_parent_canonical.php
 *   php scripts/relink_product_category_duplicate_parent_canonical.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductCategoryDuplicateParentCanonicalRelinkService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductCategoryDuplicateParentCanonicalRelinkService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
    foreach ($summary as $k => $v) {
        if ($k === 'relink_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    if (!empty($summary['relink_examples'])) {
        echo "\nRelink examples (capped):\n";
        foreach ($summary['relink_examples'] as $ex) {
            echo sprintf(
                "  scope=%s name=%s canonical=%d noncanonical=%d child=%d child_name=%s would_cycle=%s\n",
                (string) $ex['scope'],
                (string) $ex['trimmed_name'],
                (int) $ex['canonical_id'],
                (int) $ex['noncanonical_id'],
                (int) $ex['child_category_id'],
                (string) $ex['child_category_name'],
                !empty($ex['would_create_cycle']) ? 'yes' : 'no'
            );
        }
    }

    if (!$apply) {
        echo "\nNo category parent_id values were changed. Re-run with --apply after review.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
