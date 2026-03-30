<?php

declare(strict_types=1);

/**
 * Membership renewal billing pass (invoice + manual payment recording; no PSP): overdue cycle flags → renewal invoices → extend terms when paid.
 * Idempotent per (client_membership_id, billing_period_start, billing_period_end).
 *
 * Production: prefer {@see memberships_cron.php} (runs expiry → reminders → this pass, with flock).
 *
 * Usage: php system/scripts/memberships_process_billing.php [branch_id]
 * Omit branch_id to process all branches (renewal selection uses client_memberships.branch_id).
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$branchArg = $argv[1] ?? null;
$branchId = null;
if ($branchArg !== null && $branchArg !== '' && strtolower((string) $branchArg) !== 'all') {
    $branchId = (int) $branchArg;
    if ($branchId <= 0) {
        fwrite(STDERR, "Invalid branch_id.\n");
        exit(1);
    }
}

$r = app(\Modules\Memberships\Services\MembershipBillingService::class)->runScheduledBillingPass($branchId);

echo 'overdue_marked=' . (int) ($r['overdue']['marked'] ?? 0) . PHP_EOL;
echo 'renewals_examined=' . (int) ($r['renewals']['examined'] ?? 0) . PHP_EOL;
echo 'renewals_invoiced=' . (int) ($r['renewals']['invoiced'] ?? 0) . PHP_EOL;
echo 'renewals_skipped=' . (int) ($r['renewals']['skipped'] ?? 0) . PHP_EOL;
echo 'terms_applied=' . (int) ($r['applied']['applied'] ?? 0) . PHP_EOL;
echo 'terms_skipped=' . (int) ($r['applied']['skipped'] ?? 0) . PHP_EOL;
if (!empty($r['renewals']['errors'])) {
    foreach ($r['renewals']['errors'] as $err) {
        echo 'error=' . $err . PHP_EOL;
    }
}
exit(0);
