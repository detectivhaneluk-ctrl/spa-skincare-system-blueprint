<?php

declare(strict_types=1);

/**
 * FND-TNT-15 — static proof: FOUNDATION-STAFF-GROUP-TENANCY-CANONICAL-CONTRACT-CLOSURE-07
 * StaffGroupRepository: tenant paths use OrganizationRepositoryScope staff-group fragment; no hand-rolled assignable OR tree.
 */

$root = dirname(__DIR__, 3);
$scopePath = $root . '/system/core/Organization/OrganizationRepositoryScope.php';
$repoPath = $root . '/system/modules/staff/repositories/StaffGroupRepository.php';
$scope = (string) file_get_contents($scopePath);
$repo = (string) file_get_contents($repoPath);

$ok = true;

if (!str_contains($scope, 'function staffGroupVisibleFromBranchContextClause(')) {
    fwrite(STDERR, "FAIL: OrganizationRepositoryScope must define staffGroupVisibleFromBranchContextClause.\n");
    $ok = false;
}

if (!str_contains($repo, 'staffGroupVisibleFromBranchContextClause')) {
    fwrite(STDERR, "FAIL: StaffGroupRepository must delegate tenant visibility via staffGroupVisibleFromBranchContextClause.\n");
    $ok = false;
}

if (!str_contains($repo, 'function listInTenantScope(') || !str_contains($repo, 'function activeNameExistsInTenantScope(')) {
    fwrite(STDERR, "FAIL: StaffGroupRepository must expose listInTenantScope and activeNameExistsInTenantScope.\n");
    $ok = false;
}

if (str_contains($repo, 'branch_id IS NULL OR branch_id = ?') || str_contains($repo, 'branch_id IS NULL OR branch_id =')) {
    fwrite(STDERR, "FAIL: StaffGroupRepository must not hand-roll listAssignable (branch_id IS NULL OR branch_id = ?); use scope fragments.\n");
    $ok = false;
}

if (str_contains($repo, 'resolvedOrganizationId()')) {
    fwrite(STDERR, "FAIL: StaffGroupRepository must not call resolvedOrganizationId(); use OrganizationRepositoryScope tenant fragments.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
