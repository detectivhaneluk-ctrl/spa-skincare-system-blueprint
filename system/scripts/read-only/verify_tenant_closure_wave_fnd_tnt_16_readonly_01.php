<?php

declare(strict_types=1);

/**
 * FND-TNT-16 — static proof: FOUNDATION-TENANT-REPOSITORY-CLOSURE-10
 * Public client email lock: positive branch id + live branch/org EXISTS (no silent branch-only match on dangling FK).
 */

$root = dirname(__DIR__, 3);
$scope = (string) file_get_contents($root . '/system/core/Organization/OrganizationRepositoryScope.php');
$repo = (string) file_get_contents($root . '/system/modules/clients/repositories/ClientRepository.php');

$ok = true;
$lockPos = strpos($repo, 'function lockActiveByEmailBranch');
if ($lockPos === false) {
    fwrite(STDERR, "FAIL: ClientRepository missing lockActiveByEmailBranch.\n");
    exit(1);
}
$lockFn = substr($repo, $lockPos, 2200);

if (!str_contains($scope, 'function publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause(')) {
    fwrite(STDERR, "FAIL: OrganizationRepositoryScope missing publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause.\n");
    $ok = false;
}
if (!str_contains($scope, 'FROM branches b') || !str_contains($scope, 'publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause')) {
    fwrite(STDERR, "FAIL: scope helper must join branches + organizations for public resolution proof.\n");
    $ok = false;
}
if (!preg_match('/if\s*\(\s*\$branchId\s*<=\s*0/', $lockFn)) {
    fwrite(STDERR, "FAIL: lockActiveByEmailBranch must fail-closed for non-positive branchId.\n");
    $ok = false;
}
if (!str_contains($lockFn, 'publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause(\'c\')')) {
    fwrite(STDERR, "FAIL: lockActiveByEmailBranch must use publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c').\n");
    $ok = false;
}
if (substr_count($lockFn, 'SELECT * FROM clients c') < 2) {
    fwrite(STDERR, "FAIL: lockActiveByEmailBranch must use clients c in both normalized and legacy SQL paths.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
