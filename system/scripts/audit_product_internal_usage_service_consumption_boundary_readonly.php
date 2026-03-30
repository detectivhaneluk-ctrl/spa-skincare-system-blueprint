<?php

declare(strict_types=1);

/**
 * INVENTORY-OPERATIONAL-DEPTH-WAVE-03 — read-only internal_usage reference boundary audit.
 *
 * Ops: system/docs/INVENTORY-INTERNAL-USAGE-SERVICE-CONSUMPTION-BOUNDARY-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php
 *   php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php --product-id=123
 *   php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductInternalUsageServiceConsumptionBoundaryAuditService;

$json = in_array('--json', $argv, true);
$productId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--product-id=(\d+)$/', (string) $arg, $m)) {
        $productId = (int) $m[1];
    }
}

try {
    $payload = app(ProductInternalUsageServiceConsumptionBoundaryAuditService::class)->run($productId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ProductInternalUsageServiceConsumptionBoundaryAuditService::EXAMPLE_CAP;
echo "Internal usage — service consumption boundary audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'product_id_filter: ' . ($payload['product_id_filter'] === null ? '(none)' : (string) $payload['product_id_filter']) . "\n";
echo 'total_internal_usage_movements: ' . $payload['total_internal_usage_movements'] . "\n";
echo 'unlinked_manual_internal_usage_count: ' . $payload['unlinked_manual_internal_usage_count'] . "\n";
echo 'anomalous_internal_usage_count: ' . $payload['anomalous_internal_usage_count'] . "\n";
echo 'distinct_reference_types_seen: ' . json_encode($payload['distinct_reference_types_seen'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Counts by boundary_class:\n";
foreach (ProductInternalUsageServiceConsumptionBoundaryAuditService::BOUNDARY_CLASSES as $class) {
    $n = (int) ($payload['counts_by_boundary_class'][$class] ?? 0);
    echo sprintf("  %-55s %d\n", $class, $n);
}

echo "\nExamples (cap={$cap} per class, ordered by movement_id):\n";
foreach (ProductInternalUsageServiceConsumptionBoundaryAuditService::BOUNDARY_CLASSES as $class) {
    $rows = $payload['examples_by_boundary_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $m) {
        $ref = ($m['reference_type'] === null ? 'null' : (string) $m['reference_type'])
            . '/' . ($m['reference_id'] !== null ? (string) $m['reference_id'] : 'null');
        $bid = $m['branch_id'] !== null ? (string) $m['branch_id'] : 'null';
        $tgt = $m['reference_target_exists'] === null ? 'n/a' : ($m['reference_target_exists'] ? 'true' : 'false');
        echo sprintf(
            "    movement_id=%d product_id=%d qty=%s branch_id=%s ref=%s target=%s reasons=%s\n",
            (int) $m['movement_id'],
            (int) $m['product_id'],
            (string) $m['quantity'],
            $bid,
            $ref,
            $tgt,
            json_encode($m['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);
