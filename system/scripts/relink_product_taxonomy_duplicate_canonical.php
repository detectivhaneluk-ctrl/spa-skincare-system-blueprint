<?php

declare(strict_types=1);

/**
 * Audit + optional relink of active product FKs to canonical duplicate taxonomy rows
 * (PRODUCT-TAXONOMY-DUPLICATE-CANONICAL-RELINK-01).
 *
 * Default: dry-run. Use --apply to UPDATE products only (taxonomy rows untouched).
 * Exit 0 unless a runtime error occurs.
 *
 * Usage (from system/):
 *   php scripts/relink_product_taxonomy_duplicate_canonical.php
 *   php scripts/relink_product_taxonomy_duplicate_canonical.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductTaxonomyDuplicateCanonicalRelinkService;

try {
    $apply = in_array('--apply', $argv, true);
    $service = app(ProductTaxonomyDuplicateCanonicalRelinkService::class);
    $summary = $service->run($apply);

    echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
    foreach ($summary as $k => $v) {
        if ($k === 'duplicate_examples' || is_array($v)) {
            continue;
        }
        echo sprintf("%s: %s\n", $k, (string) $v);
    }

    if (!empty($summary['duplicate_examples'])) {
        echo "\nDuplicate examples (capped):\n";
        foreach ($summary['duplicate_examples'] as $ex) {
            echo sprintf(
                "  [%s] scope=%s name=%s canonical_id=%d noncanonical=%s product_refs_to_noncanonical=%d\n",
                (string) $ex['kind'],
                (string) $ex['scope'],
                (string) $ex['trimmed_name'],
                (int) $ex['canonical_id'],
                implode(',', array_map('strval', $ex['noncanonical_ids'])),
                (int) $ex['active_product_reference_count']
            );
        }
    }

    if (!$apply) {
        echo "\nNo database changes were made. Re-run with --apply to relink product FKs to canonical taxonomy ids.\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
