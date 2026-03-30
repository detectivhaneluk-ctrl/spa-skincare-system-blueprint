<?php

declare(strict_types=1);

/**
 * MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-03 — read-only sales line lifecycle consistency truth audit.
 *
 * Ops: system/docs/SALES-LINE-LIFECYCLE-CONSISTENCY-TRUTH-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_sales_line_lifecycle_consistency_truth_readonly.php
 *   php scripts/audit_sales_line_lifecycle_consistency_truth_readonly.php --invoice-id=123
 *   php scripts/audit_sales_line_lifecycle_consistency_truth_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Sales\Services\SalesLineLifecycleConsistencyTruthAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(SalesLineLifecycleConsistencyTruthAuditService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = SalesLineLifecycleConsistencyTruthAuditService::EXAMPLE_CAP;
echo "Sales line lifecycle consistency truth audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'composed_inventory_audit_schema_version: ' . ($payload['composed_inventory_audit_schema_version'] === null ? '(null)' : (string) $payload['composed_inventory_audit_schema_version']) . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'lines_scanned: ' . $payload['lines_scanned'] . "\n";
echo 'invoices_scanned: ' . $payload['invoices_scanned'] . "\n";
echo 'affected_lines_count: ' . $payload['affected_lines_count'] . "\n";
echo 'affected_invoice_ids_sample: ' . json_encode($payload['affected_invoice_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "lifecycle_consistency_class_counts:\n";
foreach (SalesLineLifecycleConsistencyTruthAuditService::LIFECYCLE_CONSISTENCY_CLASSES as $k) {
    echo sprintf("  %-55s %d\n", $k, (int) ($payload['lifecycle_consistency_class_counts'][$k] ?? 0));
}

echo "\nExamples (cap={$cap} per class, scan order by invoice_item_id):\n";
foreach (SalesLineLifecycleConsistencyTruthAuditService::LIFECYCLE_CONSISTENCY_CLASSES as $class) {
    $rows = $payload['examples_by_lifecycle_consistency_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $ln) {
        $bid = $ln['invoice_branch_id'] !== null ? (string) $ln['invoice_branch_id'] : 'null';
        echo sprintf(
            "    item_id=%d inv=%d status=%s branch=%s domain=%s inv_impact=%s net=%s lifecycle_reasons=%s\n",
            (int) $ln['invoice_item_id'],
            (int) $ln['invoice_id'],
            (string) $ln['invoice_status'],
            $bid,
            (string) $ln['line_domain_class'],
            (string) $ln['inventory_impact_class'],
            (string) $ln['linked_stock_movement_net_quantity'],
            json_encode($ln['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);
