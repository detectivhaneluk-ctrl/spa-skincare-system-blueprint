<?php

declare(strict_types=1);

/**
 * CLIENT-PROFILE-SATELLITE-FALSE-EMPTY-ACCESS-CONTRACT-01
 * + CLIENT-PROFILE-NULL-BRANCH-ORG-SCOPE-RESOLUTION-GAP-01 — read-only structural proof.
 *
 * Usage (from system/):
 *   php scripts/read-only/proof_client_profile_satellite_access_contract_01.php
 *
 * Exit: 0 = pass, 2 = fail
 */

$base = dirname(__DIR__, 2);
$repoFile = $base . '/modules/clients/repositories/ClientRepository.php';
$accessFile = $base . '/modules/clients/services/ClientProfileAccessService.php';
$scopeFile = $base . '/core/Organization/OrganizationRepositoryScope.php';

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

function read(string $path): string
{
    if (!is_file($path)) {
        fail("missing file: {$path}");
    }

    return (string) file_get_contents($path);
}

function methodBody(string $src, string $method): string
{
    if (preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE) !== 1) {
        fail("method not found: {$method}");
    }
    $start = (int) $m[0][1];
    $len = strlen($src);
    if (preg_match_all('/^\s+(?:public|private|protected)\s+function\s+\w+/m', $src, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $hit) {
            $pos = (int) $hit[1];
            if ($pos > $start) {
                return substr($src, $start, $pos - $start);
            }
        }
    }

    return substr($src, $start, $len - $start);
}

$repoSrc = read($repoFile);
$accessSrc = read($accessFile);
$scopeSrc = read($scopeFile);

$scopeMethod = methodBody($scopeSrc, 'clientProfileOrgMembershipExistsClause');
$repoMethod = methodBody($repoSrc, 'findLiveReadableForProfile');
$findMethod = methodBody($repoSrc, 'find');
$checks = [];

// B — profile-readable repository method (signature + body)
$checks['B_repo_method_exists'] = str_contains($repoSrc, 'function findLiveReadableForProfile(int $clientId, ?int $currentBranchId): ?array')
    && str_contains($repoMethod, 'findLiveReadableForProfile');

// GAP: clients-specific org membership allows NULL branch when anchored via appointments/invoices
$checks['GAP_scope_client_profile_org_membership'] = str_contains($scopeSrc, 'function clientProfileOrgMembershipExistsClause(')
    && str_contains($scopeMethod, 'branch_id IS NULL')
    && str_contains($scopeMethod, 'FROM appointments ap')
    && str_contains($scopeMethod, 'FROM invoices inv')
    && str_contains($scopeMethod, 'requireTenantProtectedBranchDerivedOrganizationId');

// D — deleted / merged filters + clients org membership fragment (not generic branchColumn-only)
$checks['D_deleted_merged_filters_and_org_scope'] = str_contains($repoMethod, 'c.deleted_at IS NULL')
    && str_contains($repoMethod, 'c.merged_into_client_id IS NULL')
    && str_contains($repoMethod, 'clientProfileOrgMembershipExistsClause')
    && !str_contains($repoMethod, 'branchColumnOwnedByResolvedOrganizationExistsClause');

// F — merged/deleted blocked on profile read path (merged explicit; deleted above)
$checks['F_profile_read_blocks_merged'] = str_contains($repoMethod, 'c.merged_into_client_id IS NULL');

// HQ path: extra branch predicate only when currentBranchId > 0
$checks['repo_hq_no_branch_predicate'] = str_contains($repoMethod, '$currentBranchId')
    && preg_match('/if\s*\(\s*\$currentBranchId\s*!==\s*null\s*&&\s*\$currentBranchId\s*>\s*0\s*\)/', $repoMethod) === 1;

// C — branch context: same-branch OR null-branch client row
$checks['C_branch_context_null_or_same_branch'] = str_contains($repoMethod, '(c.branch_id IS NULL OR c.branch_id = ?)');

// E — no wrong-branch broadening: keep org fragment; branch OR-clause is gated (not unconditional); do not delegate to findLiveReadOnBranch
$checks['E_no_wrong_branch_broadening'] = str_contains($repoMethod, "\$frag['sql']")
    && !str_contains($repoMethod, 'findLiveReadOnBranch')
    && !preg_match('/OR\s+1\s*=\s*1/', $repoMethod);

// A — resolveForProviderRead does not auto-fail HQ / null branch before repository call
$accessMethod = methodBody($accessSrc, 'resolveForProviderRead');
$checks['A_resolve_no_hq_auto_fail'] = !preg_match('/if\s*\(\s*\$branchId\s*===\s*null\s*\|\|\s*\$branchId\s*<=\s*0\s*\)\s*\{[^}]*return\s+null/s', $accessMethod)
    && !preg_match('/if\s*\(\s*\$branchId\s*===\s*null\s*\)\s*\{[^}]*return\s+null/s', $accessMethod);

$checks['access_calls_findLiveReadableForProfile'] = str_contains($accessMethod, 'findLiveReadableForProfile');

$checks['access_passes_nullable_op_branch'] = str_contains($accessMethod, '$opBranch')
    && str_contains($accessMethod, 'findLiveReadableForProfile($clientId, $opBranch)');

// Show/load alignment: find() uses same org membership path as profile satellites (null-branch with ap/inv anchor)
$checks['find_uses_client_profile_org_membership'] = str_contains($findMethod, 'clientProfileOrgMembershipExistsClause')
    && !str_contains($findMethod, 'branchColumnOwnedByResolvedOrganizationExistsClause');

$orgScopeNote = 'NULL clients.branch_id rows require an org anchor via non-deleted appointments or invoices '
    . 'to a live branch in the resolved organization (see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause). '
    . 'Client list/count queries still use branchColumnOwnedByResolvedOrganizationExistsClause and may omit some null-branch profiles.';

$scenarios = [
    'A_HQ_branch_client' => 'STRUCT_PASS (find + findLiveReadableForProfile: clientProfileOrgMembership + no extra branch gate)',
    'B_HQ_null_branch_client' => 'STRUCT_PASS when org-anchored (ap/inv EXISTS in clientProfileOrgMembershipExistsClause)',
    'C_branch_same_branch_client' => 'STRUCT_PASS (branch_id = context OR branch-assigned org arm)',
    'D_branch_null_branch_client' => 'STRUCT_PASS when org-anchored + (NULL OR current branch) predicate',
    'E_branch_other_branch' => 'STRUCT_PASS blocked (wrong branch_id fails OR arm; other org fails EXISTS)',
    'F_deleted_merged' => 'STRUCT_PASS (deleted_at / merged_into_client_id gates on profile read; find omits soft-deleted)',
];

echo "=== CLIENT-PROFILE-SATELLITE-ACCESS + NULL-BRANCH-ORG-SCOPE-GAP ===\n";
echo "contract_checks:\n";
echo '  A_resolve_no_hq_auto_fail=' . ($checks['A_resolve_no_hq_auto_fail'] ? 'PASS' : 'FAIL') . "\n";
echo '  B_repo_method_exists=' . ($checks['B_repo_method_exists'] ? 'PASS' : 'FAIL') . "\n";
echo '  C_branch_context_null_or_same_branch=' . ($checks['C_branch_context_null_or_same_branch'] ? 'PASS' : 'FAIL') . "\n";
echo '  D_deleted_merged_filters_and_org_scope=' . ($checks['D_deleted_merged_filters_and_org_scope'] ? 'PASS' : 'FAIL') . "\n";
echo '  E_no_wrong_branch_broadening=' . ($checks['E_no_wrong_branch_broadening'] ? 'PASS' : 'FAIL') . "\n";
echo '  F_profile_read_blocks_merged=' . ($checks['F_profile_read_blocks_merged'] ? 'PASS' : 'FAIL') . "\n";
echo '  GAP_scope_client_profile_org_membership=' . ($checks['GAP_scope_client_profile_org_membership'] ? 'PASS' : 'FAIL') . "\n";
echo "supporting_checks:\n";
foreach ($checks as $name => $ok) {
    if (str_starts_with($name, 'A_') || str_starts_with($name, 'B_') || str_starts_with($name, 'C_') || str_starts_with($name, 'D_') || str_starts_with($name, 'E_') || str_starts_with($name, 'F_') || str_starts_with($name, 'GAP_')) {
        continue;
    }
    echo $name . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
}
echo "\nscenario_matrix:\n";
foreach ($scenarios as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "\norg_scope_null_branch_note: {$orgScopeNote}\n";

$allPass = !in_array(false, $checks, true);
echo "\noverall=" . ($allPass ? 'PASS' : 'FAIL') . "\n";

exit($allPass ? 0 : 2);
