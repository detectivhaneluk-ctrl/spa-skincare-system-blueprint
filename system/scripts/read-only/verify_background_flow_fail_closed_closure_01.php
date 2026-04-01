<?php

declare(strict_types=1);

/**
 * BACKGROUND-FLOW-FAIL-CLOSED-CLOSURE-01
 * Read-only verifier — static structure only, no DB required.
 *
 * Proves that every audited background/non-HTTP entrypoint now routes through the canonical
 * OutOfBandLifecycleGuard before mutating or dispatching tenant data.
 *
 * Surfaces audited:
 *   A — memberships_cron.php → MembershipLifecycleService::runExpiryPass
 *   B — memberships_cron.php → MembershipBillingService::processDueRenewalInvoices
 *   C — memberships_cron.php → MembershipService::dispatchRenewalReminders
 *   D — waitlist_expire_offers.php → WaitlistService::doExecuteExpirySweepBody
 *   E — NotificationsOutboundDrainHandler → OutboundNotificationDispatchService::runBatch
 *   F — OutOfBandLifecycleGuard: non-throwing isExecutionAllowedForBranch present
 */

$systemPath = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

$ok = static function (bool $cond, string $id, string $message) use (&$pass, &$fail): void {
    if ($cond) {
        ++$pass;
        echo "PASS {$id} {$message}\n";
        return;
    }
    ++$fail;
    fwrite(STDERR, "FAIL {$id} {$message}\n");
};

$read = static function (string $relative) use ($systemPath): string {
    $path = $systemPath . '/' . str_replace('\\', '/', $relative);
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$methodBody = static function (string $src, string $method): string {
    if ($src === '' || preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $start = (int) $m[0][1];
    if (preg_match('/\n\s*(public|private|protected)\s+function\s+\w+\s*\(/', $src, $m2, PREG_OFFSET_CAPTURE, $start + 1) !== 1) {
        return substr($src, $start);
    }
    $end = (int) $m2[0][1];
    return substr($src, $start, $end - $start);
};

$guard          = $read('core/Organization/OutOfBandLifecycleGuard.php');
$membershipSvc  = $read('modules/memberships/Services/MembershipService.php');
$lifeSvc        = $read('modules/memberships/Services/MembershipLifecycleService.php');
$billingSvc     = $read('modules/memberships/Services/MembershipBillingService.php');
$waitlistSvc    = $read('modules/appointments/services/WaitlistService.php');
$dispatchSvc    = $read('modules/notifications/services/OutboundNotificationDispatchService.php');
$drainHandler   = $read('modules/notifications/Queue/NotificationsOutboundDrainHandler.php');
$cronScript     = $read('scripts/memberships_cron.php');
$waitlistScript = $read('scripts/waitlist_expire_offers.php');
$registerMemberships = $read('modules/bootstrap/register_sales_public_commerce_memberships_settings.php');
$registerAppts  = $read('modules/bootstrap/register_appointments_online_contracts.php');
$registerNotif  = $read('modules/bootstrap/register_appointments_documents_notifications.php');

// ── F: OutOfBandLifecycleGuard ──────────────────────────────────────────────
$ok(str_contains($guard, 'isExecutionAllowedForBranch'), 'F1', 'OutOfBandLifecycleGuard exposes isExecutionAllowedForBranch (non-throwing)');
$ok(str_contains($guard, 'assertExecutionAllowedForBranch'), 'F2', 'OutOfBandLifecycleGuard keeps assertExecutionAllowedForBranch (throwing)');
$ok(str_contains($guard, 'isOrganizationActive'), 'F3', 'OutOfBandLifecycleGuard checks active organization');
$ok(str_contains($guard, 'isBranchLinkedToSuspendedOrganization'), 'F4', 'OutOfBandLifecycleGuard checks suspended org via branch');
$ok(
    ($p1 = strpos($guard, 'function isExecutionAllowedForBranch')) !== false
    && str_contains(substr($guard, $p1, 300), 'assertExecutionAllowedForBranch'),
    'F5',
    'isExecutionAllowedForBranch delegates to assertExecutionAllowedForBranch'
);
$ok(
    ($p1 = strpos($guard, 'function isExecutionAllowedForBranch')) !== false
    && str_contains(substr($guard, $p1, 450), 'DomainException'),
    'F6',
    'isExecutionAllowedForBranch catches DomainException and returns false'
);

// ── A: MembershipLifecycleService::runExpiryPass ────────────────────────────
$expiryPass = $methodBody($lifeSvc, 'runExpiryPass');
$ok(str_contains($lifeSvc, 'OutOfBandLifecycleGuard'), 'A1', 'MembershipLifecycleService depends on OutOfBandLifecycleGuard');
$ok(str_contains($expiryPass, 'isExecutionAllowedForBranch'), 'A2', 'runExpiryPass calls isExecutionAllowedForBranch');
$ok(str_contains($expiryPass, 'lifecycleCache'), 'A3', 'runExpiryPass caches lifecycle results per branch (avoids N+1)');
$ok(
    ($p1 = strpos($expiryPass, 'isExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($expiryPass, 'graceDays')) !== false
    && $p1 < $p2,
    'A4',
    'runExpiryPass checks lifecycle before grace-days mutation logic'
);

// ── B: MembershipBillingService::processDueRenewalInvoices ──────────────────
$dueRenewal = $methodBody($billingSvc, 'processDueRenewalInvoices');
$ok(str_contains($billingSvc, 'OutOfBandLifecycleGuard'), 'B1', 'MembershipBillingService depends on OutOfBandLifecycleGuard');
$ok(str_contains($dueRenewal, 'isExecutionAllowedForBranch'), 'B2', 'processDueRenewalInvoices calls isExecutionAllowedForBranch');
$ok(str_contains($dueRenewal, 'lifecycleCache'), 'B3', 'processDueRenewalInvoices caches lifecycle results per branch');
$ok(
    ($p1 = strpos($dueRenewal, 'isExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($dueRenewal, 'processDueRenewalSingle')) !== false
    && $p1 < $p2,
    'B4',
    'processDueRenewalInvoices checks lifecycle before inner renewal processing'
);

// ── C: MembershipService::dispatchRenewalReminders ──────────────────────────
$reminders = $methodBody($membershipSvc, 'dispatchRenewalReminders');
$ok(str_contains($membershipSvc, 'OutOfBandLifecycleGuard'), 'C1', 'MembershipService depends on OutOfBandLifecycleGuard');
$ok(str_contains($reminders, 'isExecutionAllowedForBranch'), 'C2', 'dispatchRenewalReminders calls isExecutionAllowedForBranch');
$ok(str_contains($reminders, 'skipped_lifecycle_suspended'), 'C3', 'dispatchRenewalReminders tracks skipped_lifecycle_suspended count');
$ok(str_contains($reminders, 'lifecycleCache'), 'C4', 'dispatchRenewalReminders caches lifecycle results per branch');
$ok(
    ($p1 = strpos($reminders, 'isExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($reminders, 'reminderDays')) !== false
    && $p1 < $p2,
    'C5',
    'dispatchRenewalReminders checks lifecycle before reminder eligibility logic'
);

// ── D: WaitlistService::doExecuteExpirySweepBody ────────────────────────────
$sweepBody = $methodBody($waitlistSvc, 'doExecuteExpirySweepBody');
$ok(str_contains($waitlistSvc, 'OutOfBandLifecycleGuard'), 'D1', 'WaitlistService depends on OutOfBandLifecycleGuard');
$ok(str_contains($sweepBody, 'isExecutionAllowedForBranch'), 'D2', 'doExecuteExpirySweepBody calls isExecutionAllowedForBranch');
$ok(str_contains($sweepBody, 'lifecycleSkipped'), 'D3', 'doExecuteExpirySweepBody tracks lifecycle_skipped count');
$ok(str_contains($sweepBody, 'lifecycle_skipped'), 'D4', 'doExecuteExpirySweepBody returns lifecycle_skipped in stats');
$ok(str_contains($sweepBody, 'lifecycleCache'), 'D5', 'doExecuteExpirySweepBody caches lifecycle results per branch');
$ok(
    ($p1 = strpos($sweepBody, 'isExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($sweepBody, 'suppressStaleWaitlistOfferOutbound')) !== false
    && $p1 < $p2,
    'D6',
    'doExecuteExpirySweepBody checks lifecycle before stale-outbound suppression and state mutation'
);

// ── E: OutboundNotificationDispatchService::runBatch ────────────────────────
$runBatch = $methodBody($dispatchSvc, 'runBatch');
$ok(str_contains($dispatchSvc, 'OutOfBandLifecycleGuard'), 'E1', 'OutboundNotificationDispatchService depends on OutOfBandLifecycleGuard');
$ok(str_contains($runBatch, 'isExecutionAllowedForBranch'), 'E2', 'runBatch calls isExecutionAllowedForBranch');
$ok(str_contains($runBatch, 'lifecycle_skipped'), 'E3', 'runBatch tracks lifecycle_skipped in stats');
$ok(str_contains($runBatch, 'lifecycleCache'), 'E4', 'runBatch caches lifecycle results per branch');
$ok(
    str_contains($runBatch, 'org_lifecycle_suspended')
    && str_contains($runBatch, 'finishClaimedSkipped'),
    'E5',
    'runBatch terminally skips suspended-org messages (not silent, not retry)'
);
$ok(
    ($p1 = strpos($runBatch, 'isExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($runBatch, 'OutboundChannelPolicy')) !== false
    && $p1 < $p2,
    'E6',
    'runBatch checks lifecycle before channel policy and transport dispatch'
);
$ok(str_contains($drainHandler, 'OutboundNotificationDispatchService'), 'E7', 'NotificationsOutboundDrainHandler delegates to OutboundNotificationDispatchService');

// ── DI: bootstrap registrations ─────────────────────────────────────────────
$ok(
    str_contains($registerMemberships, 'MembershipBillingService') && str_contains($registerMemberships, 'OutOfBandLifecycleGuard'),
    'G1',
    'MembershipBillingService DI wires OutOfBandLifecycleGuard'
);
$ok(
    str_contains($registerMemberships, 'MembershipLifecycleService') && str_contains($registerMemberships, 'OutOfBandLifecycleGuard'),
    'G2',
    'MembershipLifecycleService DI wires OutOfBandLifecycleGuard'
);
$ok(
    str_contains($registerMemberships, 'MembershipService::class') && str_contains($registerMemberships, 'OutOfBandLifecycleGuard'),
    'G3',
    'MembershipService DI wires OutOfBandLifecycleGuard'
);
$ok(
    str_contains($registerAppts, 'WaitlistService') && str_contains($registerAppts, 'OutOfBandLifecycleGuard'),
    'G4',
    'WaitlistService DI wires OutOfBandLifecycleGuard'
);
$ok(
    str_contains($registerNotif, 'OutboundNotificationDispatchService') && str_contains($registerNotif, 'OutOfBandLifecycleGuard'),
    'G5',
    'OutboundNotificationDispatchService DI wires OutOfBandLifecycleGuard'
);

// ── Entrypoint scripts still delegate correctly ──────────────────────────────
$ok(str_contains($cronScript, 'MembershipService') && str_contains($cronScript, 'markExpired'), 'H1', 'memberships_cron calls MembershipService::markExpired');
$ok(str_contains($cronScript, 'MembershipBillingService') && str_contains($cronScript, 'runScheduledBillingPass'), 'H2', 'memberships_cron calls MembershipBillingService::runScheduledBillingPass');
$ok(str_contains($waitlistScript, 'WaitlistService') && str_contains($waitlistScript, 'runWaitlistExpirySweep'), 'H3', 'waitlist_expire_offers calls WaitlistService::runWaitlistExpirySweep');

// ── Prior wave invariants not regressed ─────────────────────────────────────
$marketing = $read('scripts/marketing_automations_execute.php');
$ok(str_contains($marketing, 'assertExecutionAllowedForBranch'), 'R1', 'marketing cron prior lifecycle gate still present (regression guard)');
$merge = $read('modules/clients/services/ClientMergeJobService.php');
$ok(str_contains($merge, 'OutOfBandLifecycleGuard'), 'R2', 'ClientMergeJobService prior lifecycle gate still present (regression guard)');

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
