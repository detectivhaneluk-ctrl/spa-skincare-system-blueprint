<?php

declare(strict_types=1);

/**
 * OUT-OF-BAND-INTEGRITY-AND-WORKER-LIFECYCLE-CLOSURE-01
 * Read-only verifier — static structure only, no DB required.
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

$guard = $read('core/Organization/OutOfBandLifecycleGuard.php');
$marketing = $read('scripts/marketing_automations_execute.php');
$merge = $read('modules/clients/services/ClientMergeJobService.php');
$intakeSvc = $read('modules/intake/services/IntakeFormService.php');
$intakeRepo = $read('modules/intake/repositories/IntakeFormAssignmentRepository.php');
$payrollRuleSvc = $read('modules/payroll/services/PayrollRuleService.php');
$payrollRegister = $read('modules/bootstrap/register_payroll.php');
$payrollController = $read('modules/payroll/controllers/PayrollRuleController.php');
$payrollLineRepo = $read('modules/payroll/repositories/PayrollCommissionLineRepository.php');

$ok(str_contains($guard, 'class OutOfBandLifecycleGuard'), 'A1', 'OutOfBandLifecycleGuard exists');
$ok(str_contains($guard, 'assertExecutionAllowedForBranch'), 'A2', 'OutOfBandLifecycleGuard exposes assertExecutionAllowedForBranch');
$ok(str_contains($guard, 'isOrganizationActive'), 'A3', 'OutOfBandLifecycleGuard checks active organization');
$ok(str_contains($guard, 'isBranchLinkedToSuspendedOrganization'), 'A4', 'OutOfBandLifecycleGuard checks suspended organization by branch');
$ok(str_contains($guard, 'isTenantUserBoundToSuspendedOrganization'), 'A5', 'OutOfBandLifecycleGuard checks suspended actor binding');
$ok(str_contains($guard, 'isTenantUserInactiveStaffAtBranch'), 'A6', 'OutOfBandLifecycleGuard checks inactive actor-at-branch');

$ok(
    ($p1 = strpos($marketing, 'assertExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($marketing, 'setCurrentBranchId')) !== false
    && $p1 < $p2,
    'A7',
    'marketing cron asserts lifecycle before binding branch context'
);

$mergeExec = $methodBody($merge, 'executeClaimedJob');
$ok(str_contains($merge, 'OutOfBandLifecycleGuard'), 'A8', 'ClientMergeJobService depends on OutOfBandLifecycleGuard');
$ok(
    ($p1 = strpos($mergeExec, 'assertExecutionAllowedForBranch')) !== false
    && ($p2 = strpos($mergeExec, 'mergeClientsAsActor')) !== false
    && $p1 < $p2,
    'A9',
    'client merge worker asserts lifecycle before merge execution'
);
$ok(str_contains($mergeExec, 'LIFECYCLE_BLOCKED'), 'A10', 'client merge worker marks lifecycle-blocked failures explicitly');

$submitPublic = $methodBody($intakeSvc, 'submitPublic');
$intakePolicy = $methodBody($intakeSvc, 'isPublicIntakePolicyAllowingForAssignment');
$ok(str_contains($submitPublic, "template_active"), 'B1', 'submitPublic re-checks template_active');
$ok(str_contains($intakePolicy, 'isBranchLinkedToSuspendedOrganization'), 'B2', 'public intake policy checks suspended organization lifecycle');
$ok(str_contains($intakeRepo, 'org.suspended_at IS NULL'), 'B3', 'public intake graph cohesion requires active orgs on direct anchors');
$ok(str_contains($intakeRepo, 'COUNT(DISTINCT hist.organization_id)'), 'B4', 'public intake fallback anchor requires a single active organization');
$ok(
    str_contains($submitPublic, 'findByTokenHashWithPublicGraphOrgCohesion')
    && str_contains($submitPublic, 'findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion'),
    'B5',
    'submitPublic still proves token ownership before mutation'
);

$createRule = $methodBody($payrollRuleSvc, 'createRule');
$updateRule = $methodBody($payrollRuleSvc, 'updateRule');
$insertLine = $methodBody($payrollLineRepo, 'insert');
$ok(str_contains($payrollRuleSvc, 'class PayrollRuleService'), 'C1', 'PayrollRuleService exists');
$ok(str_contains($createRule, 'enforceBranchOnCreate'), 'C2', 'PayrollRuleService::createRule enforces branch-on-create');
$ok(
    str_contains($createRule, 'assertCreateScope')
    && str_contains($payrollRuleSvc, 'assertBranchOwnedByResolvedOrganization'),
    'C3',
    'PayrollRuleService::createRule reaches org-owned branch assertion via assertCreateScope'
);
$ok(str_contains($updateRule, '$this->rules->find'), 'C4', 'PayrollRuleService::updateRule reloads current rule');
$ok(str_contains($updateRule, '$this->rules->update'), 'C5', 'PayrollRuleService::updateRule centralizes repository update');
$ok(str_contains($payrollRegister, 'PayrollRuleService::class'), 'C6', 'Payroll bootstrap registers PayrollRuleService');
$ok(str_contains($payrollController, 'ruleService->createRule'), 'C7', 'PayrollRuleController store delegates to PayrollRuleService');
$ok(str_contains($payrollController, 'ruleService->updateRule'), 'C8', 'PayrollRuleController update delegates to PayrollRuleService');
$ok(str_contains($insertLine, 'SELECT branch_id FROM payroll_runs'), 'C9', 'Payroll commission line insert proves parent run branch');
$ok(str_contains($insertLine, 'branch must match parent run branch'), 'C10', 'Payroll commission line insert fails closed on branch mismatch');

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
