<?php

declare(strict_types=1);

/**
 * FND-TNT-11 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-05 ClientMembershipRepository
 * id-read/lock closure + billing renewal branch pin.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$cmRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/ClientMembershipRepository.php');
if (!str_contains($cmRepo, 'function lockWithDefinitionInTenantScope(')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository::lockWithDefinitionInTenantScope missing.\n");
    $failed = true;
}
if (!str_contains($cmRepo, 'function lockWithDefinitionForBillingInTenantScope(')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository::lockWithDefinitionForBillingInTenantScope missing.\n");
    $failed = true;
}
if (!str_contains($cmRepo, 'getAnyLiveBranchIdForResolvedTenantOrganization()')
    || preg_match('/function\s+find\s*\([\s\S]*?getAnyLiveBranchIdForResolvedTenantOrganization/', $cmRepo) !== 1) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository::find must use getAnyLiveBranchIdForResolvedTenantOrganization when org resolves.\n");
    $failed = true;
}
if (preg_match('/function\s+findForUpdate\s*\([\s\S]*?getAnyLiveBranchIdForResolvedTenantOrganization/', $cmRepo) !== 1) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository::findForUpdate must use getAnyLiveBranchIdForResolvedTenantOrganization when org resolves.\n");
    $failed = true;
}
if (!preg_match('/function\s+lockWithDefinitionForBilling\s*\(\s*int\s+\$id\s*,\s*\?int\s+\$membershipBranchId/', $cmRepo)) {
    fwrite(STDERR, "FAIL: lockWithDefinitionForBilling must accept optional membershipBranchId.\n");
    $failed = true;
}

$cycleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipBillingCycleRepository.php');
if (!str_contains($cycleRepo, 'SELECT cm.id, cm.branch_id')
    || !str_contains($cycleRepo, "'branch_id' =>")) {
    fwrite(STDERR, "FAIL: listDueClientMembershipIds must return id + branch_id rows.\n");
    $failed = true;
}

$billSvc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipBillingService.php');
if (!str_contains($billSvc, 'function initializeAfterAssign(int $clientMembershipId, int $branchContextId)')) {
    fwrite(STDERR, "FAIL: MembershipBillingService::initializeAfterAssign must require branchContextId.\n");
    $failed = true;
}
if (!str_contains($billSvc, 'private BranchContext $branchContext')) {
    fwrite(STDERR, "FAIL: MembershipBillingService must inject BranchContext.\n");
    $failed = true;
}
if (!str_contains($billSvc, 'function clientMembershipReadForSettlement(')) {
    fwrite(STDERR, "FAIL: expected clientMembershipReadForSettlement helper.\n");
    $failed = true;
}

$boot = (string) file_get_contents($root . '/system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php');
if (!str_contains($boot, 'new \Modules\Memberships\Services\MembershipBillingService')
    || !str_contains($boot, '\Core\Branch\BranchContext::class')) {
    fwrite(STDERR, "FAIL: bootstrap must pass BranchContext into MembershipBillingService.\n");
    $failed = true;
}

$memSvc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipService.php');
if (!str_contains($memSvc, 'lockWithDefinitionInTenantScope(')) {
    fwrite(STDERR, "FAIL: MembershipService benefit path must use lockWithDefinitionInTenantScope.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_tenant_closure_wave_fnd_tnt_11_readonly_01: OK\n";
exit(0);
