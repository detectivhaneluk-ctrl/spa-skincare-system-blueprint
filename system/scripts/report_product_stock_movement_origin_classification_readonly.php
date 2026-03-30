<?php

declare(strict_types=1);

/**
 * PRODUCTS-MOVEMENT-ORIGIN-CLASSIFICATION-REPORT-01 — read-only classification report for {@code stock_movements}.
 *
 * Rules (code-aligned): {@code system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md}
 *
 * No INSERT/UPDATE/DELETE.
 *
 * Usage (from {@code system/}):
 *   php scripts/report_product_stock_movement_origin_classification_readonly.php
 *   php scripts/report_product_stock_movement_origin_classification_readonly.php --json
 *
 * Exit {@code 0} unless bootstrap/query failure ({@code 1}).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockMovementOriginClassificationReportService;

$json = in_array('--json', $argv, true);

try {
    $report = app(ProductStockMovementOriginClassificationReportService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Stock movement origin classification (read-only)\n";
echo "total_movements: {$report['total_movements']}\n";
echo "movements_on_deleted_or_missing_product: {$report['movements_on_deleted_or_missing_product']}\n\n";

echo "Counts by origin (first matching rule wins; see ops doc):\n";
foreach ($report['counts_by_origin'] as $origin => $c) {
    echo sprintf("  %-28s %d\n", $origin, $c);
}

$cap = ProductStockMovementOriginClassificationReportService::EXAMPLE_PER_ORIGIN_CAP;
echo "\nExamples per origin (cap={$cap} each):\n";
foreach ($report['examples_by_origin'] as $origin => $rows) {
    if ($rows === []) {
        continue;
    }
    echo "  [{$origin}]\n";
    foreach ($rows as $m) {
        $ref = ($m['reference_type'] ?? 'null') . '/' . ($m['reference_id'] !== null ? (string) $m['reference_id'] : 'null');
        $bid = $m['branch_id'] !== null ? (string) $m['branch_id'] : 'null';
        echo sprintf(
            "    id=%d product_id=%d type=%s qty=%s branch_id=%s ref=%s\n",
            $m['id'],
            $m['product_id'],
            $m['movement_type'],
            (string) $m['quantity'],
            $bid,
            $ref
        );
    }
}

echo "\nNo database changes were made.\n";
