<?php

declare(strict_types=1);

/**
 * FND-TNT-14 — static proof: FOUNDATION-GIFTCARD-TENANCY-CANONICAL-CONTRACT-CLOSURE-06
 * GiftCardRepository: tenant paths use OrganizationRepositoryScope gift-card fragments; no hand-rolled client/org EXISTS trees.
 */

$root = dirname(__DIR__, 3);
$scopePath = $root . '/system/core/Organization/OrganizationRepositoryScope.php';
$gcRepoPath = $root . '/system/modules/gift-cards/repositories/GiftCardRepository.php';
$scope = (string) file_get_contents($scopePath);
$gc = (string) file_get_contents($gcRepoPath);

$ok = true;

if (!str_contains($scope, 'function giftCardVisibleFromBranchContextClause(')) {
    fwrite(STDERR, "FAIL: OrganizationRepositoryScope must define giftCardVisibleFromBranchContextClause.\n");
    $ok = false;
}
if (!str_contains($scope, 'function giftCardGlobalNullClientAnchoredInResolvedOrgClause(')) {
    fwrite(STDERR, "FAIL: OrganizationRepositoryScope must define giftCardGlobalNullClientAnchoredInResolvedOrgClause.\n");
    $ok = false;
}

if (!str_contains($gc, 'giftCardVisibleFromBranchContextClause')) {
    fwrite(STDERR, "FAIL: GiftCardRepository must delegate tenant visibility to giftCardVisibleFromBranchContextClause (directly or via private wrapper).\n");
    $ok = false;
}
if (!str_contains($gc, 'giftCardGlobalNullClientAnchoredInResolvedOrgClause')) {
    fwrite(STDERR, "FAIL: GiftCardRepository INDEX_SCOPE_GLOBAL_ONLY must use giftCardGlobalNullClientAnchoredInResolvedOrgClause.\n");
    $ok = false;
}

if (str_contains($gc, 'resolvedOrganizationId()')) {
    fwrite(STDERR, "FAIL: GiftCardRepository must not call resolvedOrganizationId(); tenant gates live in OrganizationRepositoryScope fragments.\n");
    $ok = false;
}

// Removed ambiguous hand-rolled null-branch OR arm (client EXISTS + literal org id param).
if (str_contains($gc, 'WHERE cl.id = gc.client_id')) {
    fwrite(STDERR, "FAIL: GiftCardRepository must not embed hand-rolled client EXISTS for gc.client_id; use scope fragments.\n");
    $ok = false;
}

if (!str_contains($gc, 'findInTenantScope') || !str_contains($gc, 'findLockedInTenantScope')) {
    fwrite(STDERR, "FAIL: GiftCardRepository tenant read API surface missing.\n");
    $ok = false;
}

exit($ok ? 0 : 1);
