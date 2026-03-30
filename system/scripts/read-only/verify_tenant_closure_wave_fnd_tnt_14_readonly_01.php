<?php

declare(strict_types=1);

/**
 * FND-TNT-14 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-08
 * Expiry cron candidate listing: org/branch anchored query + tenant-scoped row lock only (no wide unscoped SELECT / id-only FOR UPDATE in runExpiryPass).
 */

$root = dirname(__DIR__, 3);
$lifecycle = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipLifecycleService.php');
$cmRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/ClientMembershipRepository.php');
$scope = (string) file_get_contents($root . '/system/core/Organization/OrganizationRepositoryScope.php');

$ok = true;
if (!str_contains($lifecycle, 'listExpiryTerminalCandidatesForGlobalCron')) {
    fwrite(STDERR, "FAIL: MembershipLifecycleService::runExpiryPass must use listExpiryTerminalCandidatesForGlobalCron.\n");
    $ok = false;
}
if (preg_match('/function\s+runExpiryPass\b[\s\S]*?FROM\s+client_memberships/is', $lifecycle) === 1) {
    fwrite(STDERR, "FAIL: runExpiryPass must not embed raw FROM client_memberships candidate SQL.\n");
    $ok = false;
}
if (!str_contains($lifecycle, 'findForUpdateInTenantScope($id, $lockBranch)')) {
    fwrite(STDERR, "FAIL: runExpiryPass must lock via findForUpdateInTenantScope with lock branch pin.\n");
    $ok = false;
}
if (preg_match('/function\s+runExpiryPass\b[\s\S]*?findForUpdate\(\$id\)/s', $lifecycle) === 1) {
    fwrite(STDERR, "FAIL: runExpiryPass must not call id-only findForUpdate.\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'function listExpiryTerminalCandidatesForGlobalCron(')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository missing listExpiryTerminalCandidatesForGlobalCron.\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'clientMembershipRowAnchoredToLiveOrganizationSql')) {
    fwrite(STDERR, "FAIL: listExpiryTerminalCandidatesForGlobalCron must use org/branch anchor SQL.\n");
    $ok = false;
}
if (!str_contains($scope, 'function clientMembershipRowAnchoredToLiveOrganizationSql(')) {
    fwrite(STDERR, "FAIL: OrganizationRepositoryScope missing clientMembershipRowAnchoredToLiveOrganizationSql.\n");
    $ok = false;
}
if (!str_contains($scope, 'INNER JOIN organizations o ON o.id = b.organization_id')) {
    fwrite(STDERR, "FAIL: anchor SQL must join organizations for fail-closed tenancy anchor.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
