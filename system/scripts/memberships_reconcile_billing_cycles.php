<?php

declare(strict_types=1);

/**
 * Resync membership billing cycles from canonical invoice/payment truth (repair/backfill).
 * Safe to run repeatedly. Not the primary runtime path — hooks live on PaymentService / InvoiceService.
 * Invoice id discovery uses {@see MembershipBillingCycleRepository::listDistinctInvoiceIdsForReconcile} (invoice-plane tenant strict or OrUnscoped when org unset).
 * Not included in {@see memberships_cron.php}; run manually or from a separate repair job when needed.
 *
 * Usage:
 *   php system/scripts/memberships_reconcile_billing_cycles.php
 *   php system/scripts/memberships_reconcile_billing_cycles.php --invoice=123
 *   php system/scripts/memberships_reconcile_billing_cycles.php --client_membership=456
 *   php system/scripts/memberships_reconcile_billing_cycles.php --branch=1
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$invoiceId = null;
$clientMembershipId = null;
$branchId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--invoice=(\d+)$/', $arg, $m)) {
        $invoiceId = (int) $m[1];
    } elseif (preg_match('/^--client_membership=(\d+)$/', $arg, $m)) {
        $clientMembershipId = (int) $m[1];
    } elseif (preg_match('/^--branch=(\d+)$/', $arg, $m)) {
        $branchId = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Resync membership billing cycles from invoice/payment truth.\n");
        fwrite(STDOUT, "Options: --invoice=ID --client_membership=ID --branch=ID\n");
        exit(0);
    }
}

$r = app(\Modules\Memberships\Services\MembershipBillingService::class)->reconcileBillingCyclesFromCanonicalInvoices(
    $invoiceId,
    $clientMembershipId,
    $branchId
);

fwrite(STDOUT, 'invoices_synced=' . (int) ($r['invoices_synced'] ?? 0) . PHP_EOL);
exit(0);
