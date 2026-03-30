<?php

declare(strict_types=1);

/**
 * MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-04 — read-only invoice domain composition truth audit.
 *
 * Ops: system/docs/INVOICE-DOMAIN-COMPOSITION-TRUTH-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_invoice_domain_composition_truth_readonly.php
 *   php scripts/audit_invoice_domain_composition_truth_readonly.php --invoice-id=123
 *   php scripts/audit_invoice_domain_composition_truth_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Sales\Services\InvoiceDomainCompositionTruthAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(InvoiceDomainCompositionTruthAuditService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = InvoiceDomainCompositionTruthAuditService::EXAMPLE_CAP;
echo "Invoice domain composition truth audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'composed_lifecycle_audit_schema_version: ' . ($payload['composed_lifecycle_audit_schema_version'] === null ? '(null)' : (string) $payload['composed_lifecycle_audit_schema_version']) . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'invoices_scanned: ' . $payload['invoices_scanned'] . "\n";
echo 'affected_invoices_count: ' . $payload['affected_invoices_count'] . "\n";
echo 'affected_invoice_ids_sample: ' . json_encode($payload['affected_invoice_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "invoice_domain_shape_counts:\n";
foreach (InvoiceDomainCompositionTruthAuditService::INVOICE_DOMAIN_SHAPES as $k) {
    echo sprintf("  %-40s %d\n", $k, (int) ($payload['invoice_domain_shape_counts'][$k] ?? 0));
}

echo "\ninvoice_domain_composition_class_counts:\n";
foreach (InvoiceDomainCompositionTruthAuditService::INVOICE_DOMAIN_COMPOSITION_CLASSES as $k) {
    echo sprintf("  %-50s %d\n", $k, (int) ($payload['invoice_domain_composition_class_counts'][$k] ?? 0));
}

echo "\nExamples (cap={$cap} per class, ascending invoice_id):\n";
foreach (InvoiceDomainCompositionTruthAuditService::INVOICE_DOMAIN_COMPOSITION_CLASSES as $class) {
    $rows = $payload['examples_by_invoice_domain_composition_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $inv) {
        $bid = $inv['invoice_branch_id'] !== null ? (string) $inv['invoice_branch_id'] : 'null';
        echo sprintf(
            "    invoice_id=%d status=%s branch=%s lines=%d shape=%s svc=%d retail=%d mixed=%d invAff=%d lifeAnom=%d reasons=%s\n",
            (int) $inv['invoice_id'],
            (string) $inv['invoice_status'],
            $bid,
            (int) $inv['line_count'],
            (string) $inv['invoice_domain_shape'],
            (int) $inv['clear_service_line_count'],
            (int) $inv['clear_retail_product_line_count'],
            (int) $inv['mixed_or_ambiguous_line_count'],
            (int) $inv['inventory_affecting_line_count'],
            (int) $inv['lifecycle_anomaly_line_count'],
            json_encode($inv['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);
