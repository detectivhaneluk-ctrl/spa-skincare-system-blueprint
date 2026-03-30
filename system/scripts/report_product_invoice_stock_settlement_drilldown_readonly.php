<?php

declare(strict_types=1);

/**
 * INVENTORY-OPERATIONAL-DEPTH-WAVE-01 — read-only invoice ↔ stock settlement drilldown.
 *
 * Ops: system/docs/INVENTORY-OPERATIONAL-DEPTH-READONLY-OPS.md
 *
 * Usage (from system/):
 *   php scripts/report_product_invoice_stock_settlement_drilldown_readonly.php
 *   php scripts/report_product_invoice_stock_settlement_drilldown_readonly.php --invoice-id=123
 *   php scripts/report_product_invoice_stock_settlement_drilldown_readonly.php --json
 *
 * Exit 0: completed successfully. Exit 1: bootstrap/runtime failure. No DB or file writes.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductInvoiceStockSettlementDrilldownService;

$json = in_array('--json', $argv, true);
$invoiceId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--invoice-id=(\d+)$/', (string) $arg, $m)) {
        $invoiceId = (int) $m[1];
    }
}

try {
    $payload = app(ProductInvoiceStockSettlementDrilldownService::class)->run($invoiceId);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "Invoice ↔ stock settlement drilldown (read-only)\n";
echo 'drilldown_schema_version: ' . $payload['drilldown_schema_version'] . "\n";
echo 'generated_at_utc: ' . $payload['generated_at_utc'] . "\n";
echo 'invoice_id_filter: ' . ($payload['invoice_id_filter'] === null ? '(none — all invoices)' : (string) $payload['invoice_id_filter']) . "\n";
echo 'lines_scanned: ' . $payload['lines_scanned'] . "\n";
echo 'invoices_scanned: ' . $payload['invoices_scanned'] . "\n";
echo 'affected_lines_count: ' . $payload['affected_lines_count'] . "\n";
echo 'settlement_status_counts: ' . json_encode($payload['settlement_status_counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo 'affected_invoice_ids_sample: ' . (($payload['affected_invoice_ids_sample'] ?? []) === [] ? '(none)' : implode(', ', $payload['affected_invoice_ids_sample'])) . "\n";
echo "\nLine examples (non-aligned only, capped):\n";
foreach ($payload['line_examples'] as $ex) {
    echo sprintf(
        "  inv=%d item=%d status=%s product=%d settlement=%s delta=%s reasons=%s\n",
        (int) $ex['invoice_id'],
        (int) $ex['invoice_item_id'],
        (string) $ex['invoice_status'],
        (int) $ex['product_id'],
        (string) $ex['settlement_status'],
        (string) json_encode($ex['settlement_delta'], JSON_UNESCAPED_UNICODE),
        (string) json_encode($ex['reason_codes'], JSON_UNESCAPED_UNICODE)
    );
}
if (($payload['line_examples'] ?? []) === []) {
    echo "  (none)\n";
}

exit(0);
