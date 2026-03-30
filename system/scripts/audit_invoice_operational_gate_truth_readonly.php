<?php

declare(strict_types=1);

/**
 * MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-05 — read-only invoice operational gate truth audit.
 *
 * Ops: system/docs/INVOICE-OPERATIONAL-GATE-TRUTH-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_invoice_operational_gate_truth_readonly.php
 *   php scripts/audit_invoice_operational_gate_truth_readonly.php --invoice-id=123
 *   php scripts/audit_invoice_operational_gate_truth_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Sales\Services\InvoiceOperationalGateTruthAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(InvoiceOperationalGateTruthAuditService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = InvoiceOperationalGateTruthAuditService::EXAMPLE_CAP;
echo "Invoice operational gate truth audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'composed_sales_line_inventory_audit_schema_version: ' . ($payload['composed_sales_line_inventory_audit_schema_version'] === null ? '(null)' : (string) $payload['composed_sales_line_inventory_audit_schema_version']) . "\n";
echo 'composed_sales_line_lifecycle_audit_schema_version: ' . ($payload['composed_sales_line_lifecycle_audit_schema_version'] === null ? '(null)' : (string) $payload['composed_sales_line_lifecycle_audit_schema_version']) . "\n";
echo 'composed_invoice_domain_composition_audit_schema_version: ' . ($payload['composed_invoice_domain_composition_audit_schema_version'] === null ? '(null)' : (string) $payload['composed_invoice_domain_composition_audit_schema_version']) . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'invoices_scanned: ' . $payload['invoices_scanned'] . "\n";
echo 'blocked_invoices_count: ' . $payload['blocked_invoices_count'] . "\n";
echo 'affected_invoices_count: ' . $payload['affected_invoices_count'] . "\n";
echo 'affected_invoice_ids_sample: ' . json_encode($payload['affected_invoice_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "operational_gate_class_counts:\n";
foreach (InvoiceOperationalGateTruthAuditService::OPERATIONAL_GATE_CLASSES as $k) {
    echo sprintf("  %-45s %d\n", $k, (int) ($payload['operational_gate_class_counts'][$k] ?? 0));
}

echo "\nExamples (cap={$cap} per class, ascending invoice_id):\n";
foreach (InvoiceOperationalGateTruthAuditService::OPERATIONAL_GATE_CLASSES as $class) {
    $rows = $payload['examples_by_operational_gate_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $inv) {
        $bid = $inv['invoice_branch_id'] !== null ? (string) $inv['invoice_branch_id'] : 'null';
        echo sprintf(
            "    invoice_id=%d status=%s branch=%s lines=%d idc=%s la=%d ic=%d ou=%d invAff=%d gate=%s reasons=%s\n",
            (int) $inv['invoice_id'],
            (string) $inv['invoice_status'],
            $bid,
            (int) $inv['line_count'],
            (string) $inv['invoice_domain_composition_class'],
            (int) $inv['lifecycle_anomaly_line_count'],
            (int) $inv['inventory_contradiction_line_count'],
            (int) $inv['orphaned_or_unsupported_line_count'],
            (int) $inv['inventory_affecting_line_count'],
            (string) $inv['operational_gate_class'],
            json_encode($inv['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);
