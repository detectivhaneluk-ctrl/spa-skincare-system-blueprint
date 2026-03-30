<?php

declare(strict_types=1);

/**
 * FND-TNT-13 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-07
 * ClientMembershipRepository: tenant-scoped update + explicit repair path; honest global ops renewal scan name.
 */

$root = dirname(__DIR__, 3);
$cmRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/ClientMembershipRepository.php');
$memSvc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipService.php');

$ok = true;
if (preg_match('/public\s+function\s+update\s*\(\s*int\s+\$/', $cmRepo) === 1) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository must not expose ambiguous public update(int \$id).\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'function updateInTenantScope(')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository missing updateInTenantScope.\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'function updateRepairOrUnscopedById(')) {
    fwrite(STDERR, "FAIL: ClientMembershipRepository missing updateRepairOrUnscopedById.\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'UPDATE client_memberships cm SET') || !str_contains($cmRepo, 'WHERE cm.id = ? AND')) {
    fwrite(STDERR, "FAIL: updateInTenantScope must use aliased UPDATE + scoped WHERE.\n");
    $ok = false;
}
if (!str_contains($cmRepo, 'function listActiveNonExpiredForRenewalScanGlobalOps(')) {
    fwrite(STDERR, "FAIL: missing listActiveNonExpiredForRenewalScanGlobalOps.\n");
    $ok = false;
}
if (!str_contains($memSvc, 'listActiveNonExpiredForRenewalScanGlobalOps()')) {
    fwrite(STDERR, "FAIL: MembershipService must call listActiveNonExpiredForRenewalScanGlobalOps.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
