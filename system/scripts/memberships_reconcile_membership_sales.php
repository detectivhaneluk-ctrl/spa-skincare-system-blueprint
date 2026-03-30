<?php

declare(strict_types=1);

/**
 * Resync membership_sales from canonical invoice/payment truth (repair/backfill).
 * Safe to run repeatedly. Primary hooks: PaymentService / InvoiceService via MembershipInvoiceSettlementProvider.
 * Invoice id discovery uses {@see MembershipSaleRepository::listDistinctInvoiceIdsForReconcile} (invoice-plane tenant strict or OrUnscoped when org unset).
 * Not included in {@see memberships_cron.php}; run manually or from a separate repair job when needed.
 *
 * Usage:
 *   php system/scripts/memberships_reconcile_membership_sales.php
 *   php system/scripts/memberships_reconcile_membership_sales.php --invoice=123
 *   php system/scripts/memberships_reconcile_membership_sales.php --client=456
 *   php system/scripts/memberships_reconcile_membership_sales.php --branch=1
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$invoiceId = null;
$clientId = null;
$branchId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--invoice=(\d+)$/', $arg, $m)) {
        $invoiceId = (int) $m[1];
    } elseif (preg_match('/^--client=(\d+)$/', $arg, $m)) {
        $clientId = (int) $m[1];
    } elseif (preg_match('/^--branch=(\d+)$/', $arg, $m)) {
        $branchId = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Options: --invoice=ID --client=ID --branch=ID\n");
        exit(0);
    }
}

$r = app(\Modules\Memberships\Services\MembershipSaleService::class)->reconcileMembershipSalesFromCanonicalInvoices(
    $invoiceId,
    $clientId,
    $branchId
);

fwrite(STDOUT, 'invoices_synced=' . (int) ($r['invoices_synced'] ?? 0) . PHP_EOL);
exit(0);
