<?php

declare(strict_types=1);

/**
 * MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-07 — read-only invoice financial rollup truth audit.
 *
 * Ops: system/docs/INVOICE-FINANCIAL-ROLLUP-TRUTH-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_invoice_financial_rollup_truth_readonly.php
 *   php scripts/audit_invoice_financial_rollup_truth_readonly.php --invoice-id=123
 *   php scripts/audit_invoice_financial_rollup_truth_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. Business contradictions do not change exit code.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Sales\Services\InvoiceFinancialRollupTruthAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(InvoiceFinancialRollupTruthAuditService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$summary = [
    'generated_at_utc' => $payload['generated_at_utc'],
    'audit_schema_version' => $payload['audit_schema_version'],
    'invoice_id_filter' => $payload['invoice_id_filter'],
    'thresholds' => $payload['thresholds'],
    'invoices_scanned' => $payload['invoices_scanned'],
    'coherent_invoice_count' => $payload['coherent_invoice_count'],
    'contradicted_invoice_count' => $payload['contradicted_invoice_count'],
    'missing_line_financial_evidence_count' => $payload['missing_line_financial_evidence_count'],
    'subtotal_mismatch_count' => $payload['subtotal_mismatch_count'],
    'discount_mismatch_count' => $payload['discount_mismatch_count'],
    'tax_mismatch_count' => $payload['tax_mismatch_count'],
    'total_mismatch_count' => $payload['total_mismatch_count'],
    'mixed_domain_invoice_count' => $payload['mixed_domain_invoice_count'],
    'sample_contradictions' => $payload['sample_contradictions'],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Invoice financial rollup truth audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'thresholds: ' . json_encode($payload['thresholds'], JSON_UNESCAPED_UNICODE) . "\n\n";

foreach ([
    'invoices_scanned',
    'coherent_invoice_count',
    'contradicted_invoice_count',
    'missing_line_financial_evidence_count',
    'subtotal_mismatch_count',
    'discount_mismatch_count',
    'tax_mismatch_count',
    'total_mismatch_count',
    'mixed_domain_invoice_count',
] as $k) {
    echo $k . ': ' . $payload[$k] . "\n";
}

echo "\nJSON summary (machine-readable):\n";
echo json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

echo "\nNo database changes were made.\n";
exit(0);
