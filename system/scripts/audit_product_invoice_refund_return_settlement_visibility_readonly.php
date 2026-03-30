<?php

declare(strict_types=1);

/**
 * INVENTORY-OPERATIONAL-DEPTH-WAVE-02 — read-only refund / return settlement visibility audit.
 *
 * Ops: system/docs/INVENTORY-REFUND-RETURN-SETTLEMENT-VISIBILITY-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_product_invoice_refund_return_settlement_visibility_readonly.php
 *   php scripts/audit_product_invoice_refund_return_settlement_visibility_readonly.php --invoice-id=123
 *   php scripts/audit_product_invoice_refund_return_settlement_visibility_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: uncaught exception. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductInvoiceRefundReturnSettlementVisibilityAuditService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(ProductInvoiceRefundReturnSettlementVisibilityAuditService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Invoice refund / return settlement visibility (read-only)\n";
echo 'audit_schema_version: ' . $payload['audit_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'lines_scanned: ' . $payload['lines_scanned'] . "\n";
echo 'invoices_scanned: ' . $payload['invoices_scanned'] . "\n";
echo 'affected_lines_count: ' . $payload['affected_lines_count'] . "\n";
echo 'visibility_class_counts: ' . json_encode($payload['visibility_class_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo 'affected_invoice_ids_sample: ' . (($payload['affected_invoice_ids_sample'] ?? []) === [] ? '(none)' : implode(', ', $payload['affected_invoice_ids_sample'])) . "\n";
echo "\nNotes:\n";
foreach ($payload['notes'] as $n) {
    echo '  - ' . $n . "\n";
}
echo "\nLine examples (excluding expected restore + reversal-aligned, capped):\n";
foreach ($payload['line_examples'] as $ex) {
    echo sprintf(
        "  inv=%d item=%d status=%s class=%s sale_rev=%d/%d\n",
        (int) $ex['invoice_id'],
        (int) $ex['invoice_item_id'],
        (string) $ex['invoice_status'],
        (string) $ex['visibility_class'],
        (int) $ex['sale_movement_count'],
        (int) $ex['sale_reversal_movement_count']
    );
}
if (($payload['line_examples'] ?? []) === []) {
    echo "  (none)\n";
}

exit(0);
