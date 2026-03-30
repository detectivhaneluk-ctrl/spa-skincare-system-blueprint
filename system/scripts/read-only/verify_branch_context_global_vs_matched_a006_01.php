<?php

declare(strict_types=1);

/**
 * A-006 read-only: BranchContext exposes explicit strict vs global-allowed APIs; legacy assertBranchMatch removed.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_branch_context_global_vs_matched_a006_01.php
 */
$systemRoot = dirname(__DIR__, 2);
$bc = (string) file_get_contents($systemRoot . '/core/Branch/BranchContext.php');
$pkg = (string) file_get_contents($systemRoot . '/modules/packages/services/PackageService.php');
$reg = (string) file_get_contents($systemRoot . '/modules/sales/services/RegisterSessionService.php');
$pay = (string) file_get_contents($systemRoot . '/modules/payroll/services/PayrollService.php');

$checks = [
    'BranchContext: assertBranchMatchStrict exists' => str_contains($bc, 'function assertBranchMatchStrict'),
    'BranchContext: assertBranchMatchOrGlobalEntity exists' => str_contains($bc, 'function assertBranchMatchOrGlobalEntity'),
    'BranchContext: global rows under scoped context are explicit policy' => str_contains($bc, 'intentionally allowed')
        && str_contains($bc, 'global row'),
    'BranchContext: legacy assertBranchMatch removed' => !preg_match('/function\s+assertBranchMatch\s*\(/', $bc),
    'PackageService: no collision with BranchContext name (private helper renamed)' => !str_contains($pkg, 'function assertBranchMatch(')
        && str_contains($pkg, 'function assertPackageRowBranchMatches'),
    'RegisterSessionService: register ops use strict branch identity' => str_contains($reg, 'assertBranchMatchStrict($branchId)')
        && substr_count($reg, 'assertBranchMatchStrict') >= 3,
    'PayrollService: payroll runs use strict branch identity' => str_contains($pay, 'assertBranchMatchStrict')
        && !str_contains($pay, 'assertBranchMatchOrGlobalEntity'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
