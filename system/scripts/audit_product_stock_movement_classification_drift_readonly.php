<?php

declare(strict_types=1);

/**
 * PRODUCTS-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-AUDIT-01 — read-only drill-down for {@code other_uncategorized}
 * and manual-bucket movement-type shape.
 *
 * Companion to {@see ProductStockMovementOriginClassificationReportService}. **No** writes.
 *
 * Operational contract: {@code system/docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md}
 *
 * Usage (from {@code system/}):
 *   php scripts/audit_product_stock_movement_classification_drift_readonly.php
 *   php scripts/audit_product_stock_movement_classification_drift_readonly.php --json
 *
 * Exit {@code 0} unless bootstrap/query failure ({@code 1}).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockMovementClassificationDriftAuditService;

$json = in_array('--json', $argv, true);

try {
    $report = app(ProductStockMovementClassificationDriftAuditService::class)->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ProductStockMovementClassificationDriftAuditService::EXAMPLE_CAP;
echo "Stock movement classification drift audit (read-only)\n";
echo "other_uncategorized_total: {$report['other_uncategorized_total']}\n";
echo "(Partition below is mutually exclusive within other_uncategorized; first matching reason wins.)\n\n";

foreach (ProductStockMovementClassificationDriftAuditService::OTHER_UNCATEGORIZED_DRIFT_KEYS as $key) {
    $c = (int) ($report['counts_by_drift_reason'][$key] ?? 0);
    echo sprintf("  %-42s %d\n", $key, $c);
}

echo "\nExamples (cap={$cap} per drift reason):\n";
foreach (ProductStockMovementClassificationDriftAuditService::OTHER_UNCATEGORIZED_DRIFT_KEYS as $key) {
    $rows = $report['examples_by_drift_reason'][$key] ?? [];
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

echo "\nManual operator bucket shape (null reference pair per origin rules):\n";
echo "  manual_operator_entry_total: {$report['manual_operator_entry_total']}\n";
echo "  manual_operator_unexpected_movement_type_count: {$report['manual_operator_unexpected_movement_type_count']}\n";
echo "  (Expected movement types: purchase_in, manual_adjustment, internal_usage, damaged, count_adjustment.)\n";

if ($report['manual_operator_unexpected_movement_type_examples'] !== []) {
    echo "\n  Examples (cap={$cap}):\n";
    foreach ($report['manual_operator_unexpected_movement_type_examples'] as $m) {
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
