<?php

declare(strict_types=1);

/**
 * Read-only reconciliation: products.stock_quantity vs SUM(stock_movements.quantity) per non-deleted product.
 *
 * Does not modify data. Exit 0 unless the script fails at runtime (mismatches alone do not change exit code).
 *
 * Operational contract (purpose, formula, sign semantics, limits, how to read mismatches):
 *   system/docs/PRODUCT-STOCK-LEDGER-RECONCILIATION-OPS.md
 *
 * Usage (from system/):
 *   php scripts/audit_product_stock_ledger_reconciliation.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Modules\Inventory\Services\ProductStockLedgerReconciliationService;

try {
    $summary = app(ProductStockLedgerReconciliationService::class)->run();

    echo "Product stock ledger reconciliation (read-only)\n";
    echo sprintf("Sign semantics: %s\n\n", $summary['sign_semantics_note']);

    echo sprintf("Products scanned (non-deleted): %d\n", $summary['products_scanned']);
    echo sprintf("Matched (within epsilon %.1e): %d\n", $summary['qty_epsilon'], $summary['matched_count']);
    echo sprintf("Mismatched: %d\n", $summary['mismatched_count']);

    if ($summary['mismatch_examples'] !== []) {
        echo sprintf("\nMismatch examples (capped at %d):\n", ProductStockLedgerReconciliationService::MISMATCH_EXAMPLE_CAP);
        foreach ($summary['mismatch_examples'] as $ex) {
            echo sprintf(
                "  product_id=%d sku=%s on_hand=%.6f implied_from_movements=%.6f delta=%.6f\n",
                $ex['product_id'],
                $ex['sku'],
                $ex['on_hand'],
                $ex['implied_net_from_movements'],
                $ex['delta']
            );
        }
        if ($summary['mismatched_count'] > count($summary['mismatch_examples'])) {
            echo sprintf(
                "  … %d more mismatches not shown (cap=%d).\n",
                $summary['mismatched_count'] - count($summary['mismatch_examples']),
                ProductStockLedgerReconciliationService::MISMATCH_EXAMPLE_CAP
            );
        }
    } elseif ((int) $summary['mismatched_count'] === 0) {
        echo "\nAll scanned products matched within epsilon.\n";
    }

    echo "\nNo database changes were made.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
