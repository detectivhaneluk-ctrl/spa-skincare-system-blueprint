<?php

declare(strict_types=1);

/**
 * UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-03 — read-only active product operational gate audit.
 *
 * Ops: system/docs/ACTIVE-PRODUCT-OPERATIONAL-GATE-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_active_product_operational_gate_readonly.php
 *   php scripts/audit_active_product_operational_gate_readonly.php --product-id=123
 *   php scripts/audit_active_product_operational_gate_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ActiveProductOperationalGateAuditService;

$json = in_array('--json', $argv, true);
$productId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--product-id=(\d+)$/', (string) $arg, $m)) {
        $productId = (int) $m[1];
    }
}

try {
    $payload = app(ActiveProductOperationalGateAuditService::class)->run($productId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = ActiveProductOperationalGateAuditService::EXAMPLE_CAP;
echo "Active product operational gate audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'product_id_filter: ' . ($productId === null ? '(none)' : (string) $productId) . "\n";
echo 'products_scanned: ' . $payload['products_scanned'] . "\n";
echo 'products_with_stock_health_issues_count: ' . $payload['products_with_stock_health_issues_count'] . "\n";
echo 'blocked_products_count: ' . $payload['blocked_products_count'] . "\n";
echo 'affected_product_ids_sample: ' . json_encode($payload['affected_product_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "operational_gate_class_counts:\n";
foreach (ActiveProductOperationalGateAuditService::OPERATIONAL_GATE_CLASSES as $class) {
    $n = (int) ($payload['operational_gate_class_counts'][$class] ?? 0);
    echo sprintf("  %-36s %d\n", $class, $n);
}

echo "\nExamples (cap={$cap} per class, product_id order within scan):\n";
foreach (ActiveProductOperationalGateAuditService::OPERATIONAL_GATE_CLASSES as $class) {
    $rows = $payload['examples_by_operational_gate_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $p) {
        echo sprintf(
            "    product_id=%d inv=%s issues=%d max_sev=%s preflight_block=%s reasons=%s\n",
            (int) $p['product_id'],
            (string) $p['inventory_readiness_class'],
            (int) $p['stock_health_issue_count'],
            $p['stock_health_max_severity'] === null ? 'null' : (string) $p['stock_health_max_severity'],
            $p['preflight_blocking_signal'] ? 'true' : 'false',
            json_encode($p['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);
