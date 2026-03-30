<?php

declare(strict_types=1);

/**
 * PRODUCTS-STOCK-MOVEMENT-REFERENCE-INTEGRITY-AUDIT-01 — read-only reference integrity on {@code stock_movements}.
 *
 * Validates reference semantics against current writer contracts (invoice lines, inventory counts, product
 * opening stock) and flags malformed {@code reference_type} / {@code reference_id} pairs. **No** writes.
 *
 * Operational contract: {@code system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md}
 *
 * Usage (from {@code system/}):
 *   php scripts/audit_product_stock_movement_reference_integrity_readonly.php
 *   php scripts/audit_product_stock_movement_reference_integrity_readonly.php --json
 *
 * Exit {@code 0} unless bootstrap/query failure ({@code 1}).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockMovementReferenceIntegrityAuditService;

$json = in_array('--json', $argv, true);

try {
    $report = app(ProductStockMovementReferenceIntegrityAuditService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ProductStockMovementReferenceIntegrityAuditService::EXAMPLE_CAP;
echo "Stock movement reference integrity audit (read-only)\n";
echo "total_movements: {$report['total_movements']}\n\n";

echo "Counts by anomaly (a row may match multiple checks; see ops doc):\n";
foreach (ProductStockMovementReferenceIntegrityAuditService::ANOMALY_KEYS as $key) {
    $c = (int) ($report['counts_by_anomaly'][$key] ?? 0);
    echo sprintf("  %-45s %d\n", $key, $c);
}

echo "\nExamples (cap={$cap} per anomaly):\n";
foreach (ProductStockMovementReferenceIntegrityAuditService::ANOMALY_KEYS as $key) {
    $rows = $report['examples_by_anomaly'][$key] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$key}]\n";
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
