<?php

declare(strict_types=1);

/**
 * OUT-OF-BAND-INTEGRITY-AND-WORKER-LIFECYCLE-CLOSURE-01
 * CI guardrail — ban regressions on the audited closure paths.
 */

$systemPath = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

$t = static function (bool $cond, string $id, string $message) use (&$pass, &$fail): void {
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

$guard = $read('core/Organization/OutOfBandLifecycleGuard.php');
$marketing = $read('scripts/marketing_automations_execute.php');
$merge = $read('modules/clients/services/ClientMergeJobService.php');
$intakeSvc = $read('modules/intake/services/IntakeFormService.php');
$intakeRepo = $read('modules/intake/repositories/IntakeFormAssignmentRepository.php');
$payrollController = $read('modules/payroll/controllers/PayrollRuleController.php');
$payrollRuleSvc = $read('modules/payroll/services/PayrollRuleService.php');
$payrollLineRepo = $read('modules/payroll/repositories/PayrollCommissionLineRepository.php');

$t(str_contains($guard, 'isOrganizationActive'), 'G1', 'OutOfBandLifecycleGuard must keep organization active check');
$t(str_contains($guard, 'isTenantUserInactiveStaffAtBranch'), 'G2', 'OutOfBandLifecycleGuard must keep inactive actor check');
$t(str_contains($guard, 'isExecutionAllowedForBranch'), 'G3_new', 'OutOfBandLifecycleGuard must keep non-throwing isExecutionAllowedForBranch');
$t(str_contains($marketing, 'assertExecutionAllowedForBranch'), 'G3', 'marketing cron must keep lifecycle gate');
$t(str_contains($merge, 'LIFECYCLE_BLOCKED'), 'G4', 'client merge worker must keep lifecycle-blocked failure path');
$t(str_contains($intakeSvc, 'template_active') && str_contains($intakeSvc, 'submitPublic'), 'G5', 'public intake submit must keep template_active gate');
$t(str_contains($intakeSvc, 'isBranchLinkedToSuspendedOrganization'), 'G6', 'public intake policy must keep suspended-org gate');
$t(str_contains($intakeRepo, 'COUNT(DISTINCT hist.organization_id)'), 'G7', 'public intake fallback must keep single-org anchor proof');
$t(str_contains($payrollController, 'ruleService->createRule') && !str_contains($payrollController, '$this->rules->create('), 'G8', 'payroll rule create must stay behind PayrollRuleService');
$t(str_contains($payrollLineRepo, 'SELECT branch_id FROM payroll_runs') && str_contains($payrollLineRepo, 'branch_id\'] = $runBranchId') || str_contains($payrollLineRepo, '$norm[\'branch_id\'] = $runBranchId'), 'G9', 'payroll commission line insert must derive branch from parent run');

$lifeSvc     = $read('modules/memberships/Services/MembershipLifecycleService.php');
$billingSvc  = $read('modules/memberships/Services/MembershipBillingService.php');
$memberSvc   = $read('modules/memberships/Services/MembershipService.php');
$waitlistSvc = $read('modules/appointments/services/WaitlistService.php');
$dispatchSvc = $read('modules/notifications/services/OutboundNotificationDispatchService.php');

$t(str_contains($lifeSvc, 'OutOfBandLifecycleGuard') && str_contains($lifeSvc, 'isExecutionAllowedForBranch'), 'N1', 'MembershipLifecycleService::runExpiryPass must keep lifecycle gate');
$t(str_contains($billingSvc, 'OutOfBandLifecycleGuard') && str_contains($billingSvc, 'isExecutionAllowedForBranch'), 'N2', 'MembershipBillingService::processDueRenewalInvoices must keep lifecycle gate');
$t(str_contains($memberSvc, 'OutOfBandLifecycleGuard') && str_contains($memberSvc, 'skipped_lifecycle_suspended'), 'N3', 'MembershipService::dispatchRenewalReminders must keep lifecycle gate with suspended tracking');
$t(str_contains($waitlistSvc, 'OutOfBandLifecycleGuard') && str_contains($waitlistSvc, 'lifecycle_skipped'), 'N4', 'WaitlistService::doExecuteExpirySweepBody must keep lifecycle gate with lifecycle_skipped tracking');
$t(str_contains($dispatchSvc, 'OutOfBandLifecycleGuard') && str_contains($dispatchSvc, 'org_lifecycle_suspended'), 'N5', 'OutboundNotificationDispatchService::runBatch must keep suspended-org terminal skip');

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
