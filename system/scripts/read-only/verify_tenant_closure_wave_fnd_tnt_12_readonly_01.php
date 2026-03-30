<?php

declare(strict_types=1);

/**
 * FND-TNT-12 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-06
 * ClientMembershipRepository: no unscoped list/count; issuance overlap uses InTenantScope.
 * MembershipBillingCycleRepository::findByMembershipAndPeriod joins cm + org predicate when pinned.
 */

$root = dirname(__DIR__, 3);
$cmRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/ClientMembershipRepository.php');
$cycleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipBillingCycleRepository.php');
$memSvc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipService.php');

$ok = true;
if (str_contains($cmRepo, 'public function list(') || str_contains($cmRepo, 'public function count(')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository must not expose unscoped public list()/count(); use listInTenantScope/countInTenantScope.\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'findBlockingIssuanceRowInTenantScope')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository missing findBlockingIssuanceRowInTenantScope.\n");
    $ok = false;
}
$overlapPos = strpos($cmRepo, 'findBlockingIssuanceRowInTenantScope');
if ($overlapPos === false
    || (stripos($cmRepo, 'clientMembershipTenantVisibility', $overlapPos) === false
        && stripos($cmRepo, 'clientMembershipVisibleFromBranchContextClause', $overlapPos) === false)) {
    fwrite(STDERR, "FAIL: findBlockingIssuanceRowInTenantScope must apply tenant visibility via clientMembershipTenantVisibility / OrganizationRepositoryScope::clientMembershipVisibleFromBranchContextClause.\n");
    $ok = false;
}
if (!str_contains($cycleRepo, 'function findByMembershipAndPeriod(') || !str_contains($cycleRepo, 'INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id')) {
    fwrite(STDERR, "FAIL: MembershipBillingCycleRepository::findByMembershipAndPeriod must join client_memberships for scoped path.\n");
    $ok = false;
}
if (!str_contains($memSvc, 'findBlockingIssuanceRowInTenantScope')) {
    fwrite(STDERR, "FAIL: MembershipService must call findBlockingIssuanceRowInTenantScope.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
