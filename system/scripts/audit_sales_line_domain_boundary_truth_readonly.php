<?php

declare(strict_types=1);

/**
 * MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-01 — read-only sales line domain boundary truth audit.
 *
 * Ops: system/docs/SALES-LINE-DOMAIN-BOUNDARY-TRUTH-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_sales_line_domain_boundary_truth_readonly.php
 *   php scripts/audit_sales_line_domain_boundary_truth_readonly.php --invoice-id=123
 *   php scripts/audit_sales_line_domain_boundary_truth_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Sales\Services\SalesLineDomainBoundaryTruthAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(SalesLineDomainBoundaryTruthAuditService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$cap = SalesLineDomainBoundaryTruthAuditService::EXAMPLE_CAP;
echo "Sales line domain boundary truth audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'lines_scanned: ' . $payload['lines_scanned'] . "\n";
echo 'invoices_scanned: ' . $payload['invoices_scanned'] . "\n";
echo 'affected_lines_count: ' . $payload['affected_lines_count'] . "\n";
echo 'affected_invoice_ids_sample: ' . json_encode($payload['affected_invoice_ids_sample'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "reference_shape_counts:\n";
foreach (SalesLineDomainBoundaryTruthAuditService::REFERENCE_SHAPES as $k) {
    echo sprintf("  %-40s %d\n", $k, (int) ($payload['reference_shape_counts'][$k] ?? 0));
}

echo "\nline_domain_class_counts:\n";
foreach (SalesLineDomainBoundaryTruthAuditService::LINE_DOMAIN_CLASSES as $k) {
    echo sprintf("  %-40s %d\n", $k, (int) ($payload['line_domain_class_counts'][$k] ?? 0));
}

echo "\nExamples (cap={$cap} per class, ordered by invoice_item_id):\n";
foreach (SalesLineDomainBoundaryTruthAuditService::LINE_DOMAIN_CLASSES as $class) {
    $rows = $payload['examples_by_line_domain_class'][$class] ?? [];
    if ($rows === []) {
        continue;
    }
    echo "  [{$class}]\n";
    foreach ($rows as $ln) {
        $bid = $ln['invoice_branch_id'] !== null ? (string) $ln['invoice_branch_id'] : 'null';
        echo sprintf(
            "    item_id=%d inv=%d status=%s branch=%s type=%s shape=%s prod=%s svc=%s staff=%s reasons=%s\n",
            (int) $ln['invoice_item_id'],
            (int) $ln['invoice_id'],
            (string) $ln['invoice_status'],
            $bid,
            (string) $ln['item_type'],
            (string) $ln['reference_shape'],
            $ln['product_id'] !== null ? (string) $ln['product_id'] : 'null',
            $ln['service_id'] !== null ? (string) $ln['service_id'] : 'null',
            $ln['staff_id'] !== null ? (string) $ln['staff_id'] : 'null',
            json_encode($ln['reason_codes'], JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "\nNo database changes were made.\n";
exit(0);
