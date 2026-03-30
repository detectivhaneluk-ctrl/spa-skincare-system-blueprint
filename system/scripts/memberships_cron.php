<?php

declare(strict_types=1);

/**
 * Memberships scheduled processing — single production entrypoint (cron-safe).
 *
 * Non-distributed flock (same pattern as waitlist_expire_offers.php) prevents overlapping runs on this host.
 * FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01: cross-host exclusive slot + stale recovery via `runtime_execution_registry`
 * ({@see \Core\Runtime\Jobs\RuntimeExecutionRegistry::beginExclusiveRun}).
 *
 * Execution order (domain truth unchanged; delegates to existing services only):
 *   1. Expiry / lifecycle terminal sync — {@see \Modules\Memberships\Services\MembershipService::markExpired}
 *      (→ {@see \Modules\Memberships\Services\MembershipLifecycleService::runExpiryPass})
 *   2. Renewal reminders — {@see \Modules\Memberships\Services\MembershipService::dispatchRenewalReminders} (outbound queue + optional in-app; queue ≠ delivery)
 *   3. Renewal billing pass — {@see \Modules\Memberships\Services\MembershipBillingService::runScheduledBillingPass}
 *      (overdue cycle flags → due renewal invoices → apply paid terms). Renewal invoice creation is idempotent per
 *      billing period (DB unique on membership + period + {@see ClientMembershipRepository::lockWithDefinitionForBilling}).
 *
 * Standalone scripts (same behavior; kept for ad-hoc / partial runs):
 *   - memberships_mark_expired.php
 *   - memberships_send_renewal_reminders.php
 *   - memberships_process_billing.php
 *
 * Repair / backfill (NOT part of normal cron — primary path is PaymentService / InvoiceService hooks):
 *   - memberships_reconcile_billing_cycles.php
 *   - memberships_reconcile_membership_sales.php
 *
 * Usage:
 *   php system/scripts/memberships_cron.php           # all branches for expiry + billing; reminders global
 *   php system/scripts/memberships_cron.php all
 *   php system/scripts/memberships_cron.php BRANCH_ID # expiry + billing for one branch; reminders still global
 *
 * Exit codes: 0 success, 11 lock held or exclusive registry conflict, 1 fatal / invalid args.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\Runtime\Jobs\RuntimeExecutionConflictException;
use Core\Runtime\Jobs\RuntimeExecutionKeys;
use Core\Runtime\Jobs\RuntimeExecutionRegistry;

$branchArg = $argv[1] ?? null;
if ($branchArg === '--help' || $branchArg === '-h') {
    fwrite(STDOUT, "Usage: php system/scripts/memberships_cron.php [all|BRANCH_ID]\n");
    fwrite(STDOUT, "Steps: mark_expired → renewal_reminders → process_billing (see script docblock).\n");
    exit(0);
}

$branchId = null;
if ($branchArg !== null && $branchArg !== '' && strtolower((string) $branchArg) !== 'all') {
    $branchId = (int) $branchArg;
    if ($branchId <= 0) {
        fwrite(STDERR, "memberships-cron: invalid branch_id.\n");
        exit(1);
    }
}

$lockDir = $systemPath . '/storage/locks';
$lockPath = $lockDir . '/memberships_cron.lock';

if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "memberships-cron: cannot create lock directory: {$lockDir}\n");
    exit(1);
}

$lockFh = @fopen($lockPath, 'c+');
if ($lockFh === false) {
    fwrite(STDERR, "memberships-cron: cannot open lock file: {$lockPath}\n");
    exit(1);
}

if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fclose($lockFh);
    fwrite(STDOUT, "memberships-cron: lock-held (another instance running), exiting.\n");
    exit(11);
}

/** @var RuntimeExecutionRegistry $registry */
$registry = app(RuntimeExecutionRegistry::class);
$execKey = RuntimeExecutionKeys::PHP_MEMBERSHIPS_CRON;

try {
    $registry->beginExclusiveRun($execKey, 'php_pid=' . getmypid());
} catch (RuntimeExecutionConflictException $e) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    fwrite(STDERR, 'memberships-cron: exclusive-run-conflict: ' . $e->getMessage() . PHP_EOL);
    exit(11);
} catch (\Throwable $e) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    fwrite(STDERR, 'memberships-cron: registry_begin_failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$startedAt = microtime(true);
$exitCode = 0;

$log = static function (string $k, string $v) use ($startedAt): void {
    $ms = (int) round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, 'memberships-cron: ' . $k . '=' . $v . ' elapsed_ms=' . $ms . PHP_EOL);
};

try {
    $log('branch_scope', $branchId === null ? 'all' : (string) $branchId);

    $log('step', 'mark_expired:start');
    $marked = app(\Modules\Memberships\Services\MembershipService::class)->markExpired($branchId);
    fwrite(STDOUT, 'marked-expired=' . (int) $marked . PHP_EOL);
    $log('step', 'mark_expired:done');
    $registry->heartbeatExclusive($execKey);

    $log('step', 'renewal_reminders:start');
    $remStats = app(\Modules\Memberships\Services\MembershipService::class)->dispatchRenewalReminders();
    fwrite(STDOUT, 'reminders-scanned=' . (int) ($remStats['scanned'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-eligible=' . (int) ($remStats['eligible'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-created=' . (int) ($remStats['created'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-skipped-duplicate=' . (int) ($remStats['skipped_duplicate'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-outreach-pending-enqueued=' . (int) ($remStats['outreach_pending_enqueued'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-outreach-duplicate=' . (int) ($remStats['outreach_duplicate_ignored'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-outreach-skipped=' . (int) ($remStats['outreach_skipped'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-outreach-failed=' . (int) ($remStats['outreach_failed'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-skipped-disabled-or-zero-reminder=' . (int) ($remStats['skipped_disabled_or_zero_reminder'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'reminders-skipped-notifications-disabled=' . (int) ($remStats['skipped_notifications_disabled'] ?? 0) . PHP_EOL);
    $log('step', 'renewal_reminders:done');
    $registry->heartbeatExclusive($execKey);

    $log('step', 'process_billing:start');
    $bill = app(\Modules\Memberships\Services\MembershipBillingService::class)->runScheduledBillingPass($branchId);
    fwrite(STDOUT, 'billing-overdue-marked=' . (int) ($bill['overdue']['marked'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'billing-renewals-examined=' . (int) ($bill['renewals']['examined'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'billing-renewals-invoiced=' . (int) ($bill['renewals']['invoiced'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'billing-renewals-skipped=' . (int) ($bill['renewals']['skipped'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'billing-terms-applied=' . (int) ($bill['applied']['applied'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, 'billing-terms-skipped=' . (int) ($bill['applied']['skipped'] ?? 0) . PHP_EOL);
    if (!empty($bill['renewals']['errors'])) {
        foreach ($bill['renewals']['errors'] as $err) {
            fwrite(STDOUT, 'billing-error=' . str_replace(["\r", "\n"], ' ', (string) $err) . PHP_EOL);
        }
    }
    $log('step', 'process_billing:done');

    $registry->completeExclusiveSuccess($execKey);
} catch (\Throwable $e) {
    $exitCode = 1;
    try {
        $registry->completeExclusiveFailure($execKey, $e->getMessage());
    } catch (\Throwable) {
    }
    fwrite(STDERR, 'memberships-cron: fatal: ' . $e->getMessage() . PHP_EOL);
} finally {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

$log('exit', (string) $exitCode);
exit($exitCode);
