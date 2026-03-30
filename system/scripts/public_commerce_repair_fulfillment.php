<?php

declare(strict_types=1);

/**
 * Repair/backfill public-commerce fulfillment by re-running the authoritative reconciler for stuck non-terminal rows.
 * Safe to run repeatedly; idempotent. Does not create payments. Mirrors {@see memberships_reconcile_membership_sales.php}.
 *
 * Usage:
 *   php system/scripts/public_commerce_repair_fulfillment.php --dry-run
 *   php system/scripts/public_commerce_repair_fulfillment.php
 *   php system/scripts/public_commerce_repair_fulfillment.php --invoice=123
 *   php system/scripts/public_commerce_repair_fulfillment.php --branch=1
 *   php system/scripts/public_commerce_repair_fulfillment.php --limit=50
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$dryRun = false;
$invoiceId = null;
$branchId = null;
$limit = 100;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--invoice=(\d+)$/', $arg, $m)) {
        $invoiceId = (int) $m[1];
    } elseif (preg_match('/^--branch=(\d+)$/', $arg, $m)) {
        $branchId = (int) $m[1];
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, min(500, (int) $m[1]));
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Options: --dry-run  --invoice=ID  --branch=ID  --limit=N\n");
        exit(0);
    }
}

$repair = app(\Modules\PublicCommerce\Services\PublicCommerceFulfillmentRepairService::class);

if ($dryRun) {
    $rows = $repair->listRepairCandidates($branchId, $invoiceId, $limit);
    fwrite(STDOUT, 'dry_run candidates=' . count($rows) . PHP_EOL);
    foreach ($rows as $r) {
        fwrite(STDOUT, sprintf(
            "purchase_id=%d invoice_id=%d status=%s ref=%s inv_status=%s\n",
            (int) ($r['id'] ?? 0),
            (int) ($r['invoice_id'] ?? 0),
            (string) ($r['status'] ?? ''),
            (string) ($r['repair_reference_state'] ?? ''),
            $r['invoice_status'] !== null && $r['invoice_status'] !== '' ? (string) $r['invoice_status'] : '-'
        ));
    }
    exit(0);
}

$stats = $repair->repairBatch($branchId, $invoiceId, $limit);
foreach ($stats as $k => $v) {
    fwrite(STDOUT, $k . '=' . (int) $v . PHP_EOL);
}
exit(0);
