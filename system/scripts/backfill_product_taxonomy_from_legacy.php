<?php

declare(strict_types=1);

/**
 * Idempotent legacy → normalized product taxonomy backfill (PRODUCT-TAXONOMY-BACKFILL-AND-REPORT-ALIGNMENT-01).
 * Summary includes canonical link reuse counts when multiple live rows share the same trimmed name in scope (report-only).
 *
 * Default: dry-run (no writes). Mutations require explicit --apply.
 *
 * Usage (from system/):
 *   php scripts/backfill_product_taxonomy_from_legacy.php
 *   php scripts/backfill_product_taxonomy_from_legacy.php --apply
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductTaxonomyLegacyBackfillService;

$apply = in_array('--apply', $argv, true);

$service = app(ProductTaxonomyLegacyBackfillService::class);
$summary = $service->run($apply);

echo ($apply ? 'APPLY' : 'DRY-RUN') . " complete.\n";
foreach ($summary as $k => $v) {
    if ($k === 'anomaly_examples' || is_array($v)) {
        continue;
    }
    echo sprintf("%s: %s\n", $k, (string) $v);
}

if (!empty($summary['anomaly_examples'])) {
    echo "\nAnomaly examples (FK points to missing or soft-deleted taxonomy row):\n";
    foreach ($summary['anomaly_examples'] as $ex) {
        echo sprintf(
            "  product_id=%d field=%s fk_id=%d\n",
            (int) $ex['product_id'],
            (string) $ex['field'],
            (int) $ex['fk_id']
        );
    }
}

if (!$apply) {
    echo "\nNo database changes were made. Re-run with --apply to write.\n";
}
