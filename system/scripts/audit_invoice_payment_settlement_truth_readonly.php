<?php

declare(strict_types=1);

/**
 * MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-06 — read-only invoice/payment settlement truth audit.
 *
 * Ops: system/docs/INVOICE-PAYMENT-SETTLEMENT-TRUTH-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_invoice_payment_settlement_truth_readonly.php
 *   php scripts/audit_invoice_payment_settlement_truth_readonly.php --invoice-id=123
 *   php scripts/audit_invoice_payment_settlement_truth_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. Business contradictions do not change exit code.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Sales\Services\InvoicePaymentSettlementTruthAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(InvoicePaymentSettlementTruthAuditService::class)->run($invoiceId);
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
    'same_currency_overpaid_count' => $payload['same_currency_overpaid_count'],
    'same_currency_underpaid_count' => $payload['same_currency_underpaid_count'],
    'paid_but_status_unpaid_count' => $payload['paid_but_status_unpaid_count'],
    'unpaid_but_status_paid_count' => $payload['unpaid_but_status_paid_count'],
    'negative_balance_due_count' => $payload['negative_balance_due_count'],
    'cross_currency_payment_presence_count' => $payload['cross_currency_payment_presence_count'],
    'invoice_without_payments_but_paid_amount_positive_count' => $payload['invoice_without_payments_but_paid_amount_positive_count'],
    'sample_contradictions' => $payload['sample_contradictions'],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Invoice / payment settlement truth audit (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'thresholds: ' . json_encode($payload['thresholds'], JSON_UNESCAPED_UNICODE) . "\n\n";

foreach ([
    'invoices_scanned',
    'coherent_invoice_count',
    'contradicted_invoice_count',
    'same_currency_overpaid_count',
    'same_currency_underpaid_count',
    'paid_but_status_unpaid_count',
    'unpaid_but_status_paid_count',
    'negative_balance_due_count',
    'cross_currency_payment_presence_count',
    'invoice_without_payments_but_paid_amount_positive_count',
] as $k) {
    echo $k . ': ' . $payload[$k] . "\n";
}

echo "\nJSON summary (machine-readable):\n";
echo json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

echo "\nNo database changes were made.\n";
exit(0);
