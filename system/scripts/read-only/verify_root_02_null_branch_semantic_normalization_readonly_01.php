<?php

declare(strict_types=1);

/**
 * ROOT-02 — static proof: target membership + availability methods no longer treat `branch_id IS NULL`
 * as a hidden multipurpose fallback.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_root_02_null_branch_semantic_normalization_readonly_01.php
 */

$root = dirname(__DIR__, 3);
$membershipRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipSaleRepository.php');
$availabilityService = (string) file_get_contents($root . '/system/modules/appointments/services/AvailabilityService.php');

/**
 * @return string|null
 */
function extractMethodBody(string $source, string $methodName): ?string
{
    $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*[^{\s]+)?\s*\{([\s\S]*?)\n    \}/';
    if (preg_match($pattern, $source, $m) !== 1) {
        return null;
    }

    return $m[1];
}

$failed = [];
$checks = [];

$blockingInitialSale = extractMethodBody($membershipRepo, 'findBlockingOpenInitialSale');
$branchOwnedMembershipGuard = extractMethodBody($membershipRepo, 'isBranchOwnedTenantRuntimeBranchId');
$activeServiceForScope = extractMethodBody($availabilityService, 'getActiveServiceForScope');
$serviceScopeHelper = extractMethodBody($availabilityService, 'serviceIsBranchOwnedOrOrgGlobalForOperationBranch');
$serviceStaffGroupExists = extractMethodBody($availabilityService, 'serviceStaffGroupExistsSql');
$orgGlobalOnlyStaffGroupScope = extractMethodBody($availabilityService, 'orgGlobalOnlyServiceStaffGroupBranchScopeSql');
$branchOwnedOrOrgGlobalStaffGroupScope = extractMethodBody($availabilityService, 'branchOwnedOrOrgGlobalServiceStaffGroupBranchScopeSql');

$checks['Membership blocker is BRANCH_OWNED-only and removes null-branch invoice fallback'] =
    $blockingInitialSale !== null
    && str_contains($blockingInitialSale, 'isBranchOwnedTenantRuntimeBranchId')
    && str_contains($blockingInitialSale, 'ms.branch_id = ?')
    && !str_contains($blockingInitialSale, 'ms.branch_id IS NULL')
    && !str_contains($blockingInitialSale, 'INNER JOIN invoices');

$checks['Membership repo names REPAIR_ONLY / CONTROL_PLANE null branch as out of runtime scope'] =
    $branchOwnedMembershipGuard !== null
    && str_contains($membershipRepo, 'BRANCH_OWNED tenant blocker only')
    && str_contains($membershipRepo, 'REPAIR_ONLY / CONTROL_PLANE')
    && str_contains($branchOwnedMembershipGuard, 'return $branchId !== null && $branchId > 0;');

$checks['Availability service scope uses explicit BRANCH_OWNED / ORG_GLOBAL helper'] =
    $activeServiceForScope !== null
    && str_contains($activeServiceForScope, 'serviceIsBranchOwnedOrOrgGlobalForOperationBranch')
    && !str_contains($activeServiceForScope, 'branch_id IS NULL');

$checks['Availability service helper fails closed for null operation branch and allows explicit ORG_GLOBAL'] =
    $serviceScopeHelper !== null
    && str_contains($serviceScopeHelper, 'if ($operationBranchId === null)')
    && str_contains($serviceScopeHelper, 'return $serviceBranchId === null;')
    && str_contains($serviceScopeHelper, 'return $serviceBranchId === null || $serviceBranchId === $operationBranchId;');

$checks['Staff-group target method no longer hides null semantics inline'] =
    $serviceStaffGroupExists !== null
    && str_contains($serviceStaffGroupExists, 'orgGlobalOnlyServiceStaffGroupBranchScopeSql')
    && str_contains($serviceStaffGroupExists, 'branchOwnedOrOrgGlobalServiceStaffGroupBranchScopeSql')
    && !str_contains($serviceStaffGroupExists, 'sg.branch_id IS NULL')
    && !str_contains($serviceStaffGroupExists, 'sg.branch_id = ?');

$checks['Staff-group helpers name ORG_GLOBAL vs BRANCH_OWNED_ORG_GLOBAL explicitly'] =
    $orgGlobalOnlyStaffGroupScope !== null
    && $branchOwnedOrOrgGlobalStaffGroupScope !== null
    && str_contains($availabilityService, 'ORG_GLOBAL only')
    && str_contains($availabilityService, 'BRANCH_OWNED or ORG_GLOBAL')
    && str_contains($orgGlobalOnlyStaffGroupScope, "return ' AND sg.branch_id IS NULL';")
    && str_contains($branchOwnedOrOrgGlobalStaffGroupScope, "return [' AND (sg.branch_id IS NULL OR sg.branch_id = ?)', [\$branchId]];");

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_root_02_null_branch_semantic_normalization_readonly_01: OK\n";
exit(0);
