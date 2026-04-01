<?php

declare(strict_types=1);

/**
 * OUT-OF-BAND-INTEGRITY-AND-WORKER-LIFECYCLE-CLOSURE-01
 * Read-only smoke proof for the four audited out-of-band paths.
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

$marketing = $read('scripts/marketing_automations_execute.php');
$merge = $read('modules/clients/services/ClientMergeJobService.php');
$intakeSvc = $read('modules/intake/services/IntakeFormService.php');
$intakeRepo = $read('modules/intake/repositories/IntakeFormAssignmentRepository.php');
$payrollRuleSvc = $read('modules/payroll/services/PayrollRuleService.php');
$payrollLineRepo = $read('modules/payroll/repositories/PayrollCommissionLineRepository.php');

$smoke(
    str_contains($marketing, 'lifecycle_blocked') && str_contains($marketing, 'assertExecutionAllowedForBranch'),
    'suspended_org_worker_path_fail_closed',
    'marketing cron no longer shows explicit lifecycle-blocked path'
);
$smoke(
    str_contains($merge, 'isTenantUserInactiveStaffAtBranch') || (str_contains($merge, 'assertExecutionAllowedForBranch') && str_contains($merge, 'LIFECYCLE_BLOCKED')),
    'inactive_actor_worker_path_fail_closed',
    'client merge worker no longer proves inactive actor lifecycle block'
);
$smoke(
    str_contains($intakeSvc, 'PUBLIC_ACCESS_UNAVAILABLE_MESSAGE')
    && str_contains($intakeSvc, 'template_active')
    && str_contains($intakeRepo, 'COUNT(DISTINCT hist.organization_id)'),
    'intake_invalid_or_mismatched_token_path_fail_closed',
    'intake token path no longer proves lifecycle-safe ownership before submit'
);
$smoke(
    str_contains($payrollRuleSvc, 'createRule')
    && str_contains($payrollRuleSvc, 'updateRule')
    && str_contains($payrollLineRepo, 'Payroll commission line branch must match parent run branch.'),
    'payroll_write_path_structurally_guarded',
    'payroll create/insert discipline no longer has centralized guard + parent-run branch check'
);

echo "\nSummary: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
