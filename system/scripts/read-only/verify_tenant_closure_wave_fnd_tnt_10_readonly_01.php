<?php

declare(strict_types=1);

/**
 * FND-TNT-10 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-04 membership definition list/find/count
 * + membership billing cycle invoice-plane find/findForUpdate.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$defRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipDefinitionRepository.php');
if (!str_contains($defRepo, 'function findBranchOwnedInResolvedOrganization(')) {
    fwrite(STDERR, "FAIL: MembershipDefinitionRepository::findBranchOwnedInResolvedOrganization missing.\n");
    $failed = true;
}
if (!str_contains($defRepo, 'function findForClientMembershipContext(')) {
    fwrite(STDERR, "FAIL: MembershipDefinitionRepository::findForClientMembershipContext missing.\n");
    $failed = true;
}
if (preg_match('/function\s+find\s*\([^)]*\)\s*:\s*\?array\s*\{[^}]*SELECT\s*\*\s+FROM\s+membership_definitions\s+WHERE\s+id\s*=\s*\?/s', $defRepo) === 1) {
    fwrite(STDERR, "FAIL: MembershipDefinitionRepository::find must not use raw SELECT * FROM membership_definitions WHERE id = ?.\n");
    $failed = true;
}
if (!str_contains($defRepo, 'branchColumnOwnedByResolvedOrganizationExistsClause(\'md\')')
    || substr_count($defRepo, 'function list(') < 1
    || !preg_match('/function\s+list\([\s\S]*?branchColumnOwnedByResolvedOrganizationExistsClause\s*\(\s*[\'"]md[\'"]\s*\)/', $defRepo)) {
    fwrite(STDERR, "FAIL: MembershipDefinitionRepository::list must use branchColumnOwnedByResolvedOrganizationExistsClause('md').\n");
    $failed = true;
}
if (!preg_match('/function\s+count\([\s\S]*?branchColumnOwnedByResolvedOrganizationExistsClause\s*\(\s*[\'"]md[\'"]\s*\)/', $defRepo)) {
    fwrite(STDERR, "FAIL: MembershipDefinitionRepository::count must use branchColumnOwnedByResolvedOrganizationExistsClause('md').\n");
    $failed = true;
}

$cycleRepo = (string) file_get_contents($root . '/system/modules/memberships/Repositories/MembershipBillingCycleRepository.php');
if (!str_contains($cycleRepo, 'function findInInvoicePlane(')
    || !str_contains($cycleRepo, 'function findForUpdateInInvoicePlane(')) {
    fwrite(STDERR, "FAIL: MembershipBillingCycleRepository invoice-plane find helpers missing.\n");
    $failed = true;
}
if (!str_contains($cycleRepo, 'function findForInvoice(')
    || !str_contains($cycleRepo, 'function findForUpdateForInvoice(')) {
    fwrite(STDERR, "FAIL: MembershipBillingCycleRepository findForInvoice / findForUpdateForInvoice missing.\n");
    $failed = true;
}
if (preg_match('/function\s+find\s*\(\s*int\s+\$id\s*\)\s*:\s*\?array\s*\{\s*return\s+\$this->db->fetchOne\s*\(\s*[\'"]SELECT\s*\*\s+FROM\s+membership_billing_cycles\s+WHERE\s+id\s*=\s*\?/', $cycleRepo) === 1) {
    fwrite(STDERR, "FAIL: MembershipBillingCycleRepository::find must not be raw id-only SELECT *.\n");
    $failed = true;
}
if (preg_match('/function\s+findForUpdate\s*\(\s*int\s+\$id\s*\)\s*:\s*\?array\s*\{\s*return\s+\$this->db->fetchOne\s*\(\s*[\'"]SELECT\s*\*\s+FROM\s+membership_billing_cycles\s+WHERE\s+id\s*=\s*\?\s+FOR\s+UPDATE/', $cycleRepo) === 1) {
    fwrite(STDERR, "FAIL: MembershipBillingCycleRepository::findForUpdate must not be raw id-only FOR UPDATE.\n");
    $failed = true;
}

$billSvc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipBillingService.php');
if (str_contains($billSvc, 'definitions->find(')) {
    fwrite(STDERR, "FAIL: MembershipBillingService must not call definitions->find( — use findForClientMembershipContext.\n");
    $failed = true;
}
if (substr_count($billSvc, 'findForClientMembershipContext(') < 1) {
    fwrite(STDERR, "FAIL: MembershipBillingService expected findForClientMembershipContext usage.\n");
    $failed = true;
}

$saleSvc = (string) file_get_contents($root . '/system/modules/memberships/Services/MembershipSaleService.php');
if (preg_match('/definitions->find\s*\(\s*\$defId\s*\)/', $saleSvc) === 1) {
    fwrite(STDERR, "FAIL: MembershipSaleService must not use definitions->find(\$defId) for activation check.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_tenant_closure_wave_fnd_tnt_10_readonly_01: OK\n";
exit(0);
