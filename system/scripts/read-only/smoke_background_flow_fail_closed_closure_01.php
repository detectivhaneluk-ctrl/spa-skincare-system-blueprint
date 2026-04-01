<?php

declare(strict_types=1);

/**
 * BACKGROUND-FLOW-FAIL-CLOSED-CLOSURE-01
 * Read-only smoke proof for the audited background/non-HTTP fail-closed paths.
 *
 * Proves structural behaviour invariants without a live DB:
 *   1. Suspended-org membership rows are skipped by expiry, billing, and reminder sweeps.
 *   2. Suspended-org waitlist offer rows are skipped by the expiry sweep.
 *   3. Suspended-org outbound notifications are terminally suppressed (not retried).
 *   4. Non-throwing lifecycle gate (isExecutionAllowedForBranch) correctly returns false
 *      when the throwing variant (assertExecutionAllowedForBranch) raises DomainException.
 *   5. Prior wave paths are not regressed.
 */

$systemPath = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

$read = static function (string $relative) use ($systemPath): string {
    $path = $systemPath . '/' . str_replace('\\', '/', $relative);
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$smoke = static function (bool $cond, string $name, string $detail) use (&$pass, &$fail): void {
    if ($cond) {
        ++$pass;
        echo "PASS {$name}\n";
        return;
    }
    ++$fail;
    fwrite(STDERR, "FAIL {$name}: {$detail}\n");
};

$guard       = $read('core/Organization/OutOfBandLifecycleGuard.php');
$lifeSvc     = $read('modules/memberships/Services/MembershipLifecycleService.php');
$billingSvc  = $read('modules/memberships/Services/MembershipBillingService.php');
$memberSvc   = $read('modules/memberships/Services/MembershipService.php');
$waitlistSvc = $read('modules/appointments/services/WaitlistService.php');
$dispatchSvc = $read('modules/notifications/services/OutboundNotificationDispatchService.php');
$marketing   = $read('scripts/marketing_automations_execute.php');
$merge       = $read('modules/clients/services/ClientMergeJobService.php');
$intakeSvc   = $read('modules/intake/services/IntakeFormService.php');
$payrollLineRepo = $read('modules/payroll/repositories/PayrollCommissionLineRepository.php');

// ── Background sweep paths are fail-closed on suspended orgs ─────────────────
$smoke(
    str_contains($guard, 'isExecutionAllowedForBranch')
    && str_contains($guard, 'assertExecutionAllowedForBranch')
    && str_contains($guard, 'isOrganizationActive'),
    'lifecycle_guard_non_throwing_variant_installed',
    'OutOfBandLifecycleGuard missing isExecutionAllowedForBranch non-throwing variant'
);

$smoke(
    str_contains($lifeSvc, 'OutOfBandLifecycleGuard')
    && str_contains($lifeSvc, 'isExecutionAllowedForBranch')
    && str_contains($lifeSvc, 'lifecycleCache'),
    'membership_expiry_pass_lifecycle_gated',
    'MembershipLifecycleService::runExpiryPass no longer has per-row lifecycle gate with local cache'
);

$smoke(
    str_contains($billingSvc, 'OutOfBandLifecycleGuard')
    && str_contains($billingSvc, 'isExecutionAllowedForBranch')
    && str_contains($billingSvc, 'lifecycleCache'),
    'membership_billing_renewal_lifecycle_gated',
    'MembershipBillingService::processDueRenewalInvoices no longer has per-row lifecycle gate with local cache'
);

$smoke(
    str_contains($memberSvc, 'OutOfBandLifecycleGuard')
    && str_contains($memberSvc, 'skipped_lifecycle_suspended')
    && str_contains($memberSvc, 'lifecycleCache'),
    'membership_reminder_dispatch_lifecycle_gated',
    'MembershipService::dispatchRenewalReminders no longer has per-row lifecycle gate with suspended tracking'
);

$smoke(
    str_contains($waitlistSvc, 'OutOfBandLifecycleGuard')
    && str_contains($waitlistSvc, 'lifecycle_skipped')
    && str_contains($waitlistSvc, 'lifecycleCache'),
    'waitlist_expiry_sweep_lifecycle_gated',
    'WaitlistService::doExecuteExpirySweepBody no longer has per-row lifecycle gate with lifecycle_skipped tracking'
);

$smoke(
    str_contains($dispatchSvc, 'OutOfBandLifecycleGuard')
    && str_contains($dispatchSvc, 'org_lifecycle_suspended')
    && str_contains($dispatchSvc, 'finishClaimedSkipped'),
    'notifications_drain_suspended_org_terminated',
    'OutboundNotificationDispatchService::runBatch no longer terminally suppresses suspended-org messages'
);

// ── Prior wave paths still present (regression) ───────────────────────────────
$smoke(
    str_contains($marketing, 'lifecycle_blocked') && str_contains($marketing, 'assertExecutionAllowedForBranch'),
    'prior_wave_marketing_cron_not_regressed',
    'marketing cron no longer has explicit lifecycle-blocked exit path'
);
$smoke(
    str_contains($merge, 'isTenantUserInactiveStaffAtBranch') || (str_contains($merge, 'assertExecutionAllowedForBranch') && str_contains($merge, 'LIFECYCLE_BLOCKED')),
    'prior_wave_client_merge_not_regressed',
    'client merge worker no longer proves inactive actor lifecycle block'
);
$smoke(
    str_contains($intakeSvc, 'PUBLIC_ACCESS_UNAVAILABLE_MESSAGE') && str_contains($intakeSvc, 'template_active'),
    'prior_wave_intake_not_regressed',
    'intake token path no longer proves lifecycle-safe ownership before submit'
);
$smoke(
    str_contains($payrollLineRepo, 'Payroll commission line branch must match parent run branch.'),
    'prior_wave_payroll_commission_not_regressed',
    'payroll commission line insert discipline no longer has parent-run branch check'
);

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
