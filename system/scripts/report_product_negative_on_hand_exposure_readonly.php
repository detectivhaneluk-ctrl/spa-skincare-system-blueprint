<?php

declare(strict_types=1);

/**
 * INVENTORY-OPERATIONAL-DEPTH-WAVE-01 — read-only negative on-hand exposure classification.
 *
 * Ops: system/docs/INVENTORY-OPERATIONAL-DEPTH-READONLY-OPS.md
 *
 * Usage (from system/):
 *   php scripts/report_product_negative_on_hand_exposure_readonly.php
 *   php scripts/report_product_negative_on_hand_exposure_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: bootstrap/runtime failure. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductNegativeOnHandExposureReportService;

$json = in_array('--json', $argv, true);

try {
    $payload = app(ProductNegativeOnHandExposureReportService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Negative on-hand exposure (read-only)\n";
echo 'exposure_schema_version: ' . $payload['exposure_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'recent_window_days: ' . $payload['recent_window_days'] . "\n";
echo 'products_scanned (non-deleted): ' . $payload['products_scanned'] . "\n";
echo 'negative_on_hand_products_count: ' . $payload['negative_on_hand_products_count'] . "\n";
echo 'critical_exposure_count: ' . $payload['critical_exposure_count'] . "\n";
echo 'exposure_class_counts: ' . json_encode($payload['exposure_class_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "\nExamples (capped, deterministic order by product id):\n";
foreach ($payload['examples'] as $ex) {
    echo sprintf(
        "  id=%d sku=%s class=%s stock=%s latest=%s\n",
        (int) $ex['product_id'],
        (string) $ex['sku'],
        (string) $ex['exposure_class'],
        (string) json_encode($ex['stock_quantity'], JSON_UNESCAPED_UNICODE),
        (string) ($ex['latest_movement_type'] ?? '')
    );
}
if (($payload['examples'] ?? []) === []) {
    echo "  (none)\n";
}

exit(0);
