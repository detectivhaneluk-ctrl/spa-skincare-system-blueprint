<?php

declare(strict_types=1);

/**
 * FOUNDATION-TENANCY-CANONICAL-CONTRACT — inventory catalog slice (Tier A, read-only):
 * product + taxonomy repositories must consume {@see OrganizationRepositoryScope} union helpers instead of re-hand-rolling
 * branch ∪ org-global-null OR trees.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_catalog_tenant_union_fragments_closure_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$orgScope = (string) file_get_contents($system . '/core/Organization/OrganizationRepositoryScope.php');
$prodRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductRepository.php');
$catRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductCategoryRepository.php');
$brandRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductBrandRepository.php');

$checks['OrganizationRepositoryScope defines inventory catalog union helpers'] = str_contains($orgScope, 'function productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')
    && str_contains($orgScope, 'function taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause')
    && str_contains($orgScope, 'function taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause');

$checks['ProductRepository uses product operation-branch union (stock + unified catalog)'] = substr_count(
    $prodRepo,
    '->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('
) >= 3;

$checks['ProductRepository full-catalog visibility defers to org-has taxonomy union'] = str_contains($prodRepo, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause')
    && str_contains($prodRepo, 'resolvedTenantCatalogProductVisibilityClause');

$checks['ProductCategoryRepository uses both taxonomy unions (operation branch + org-has)'] = substr_count(
    $catRepo,
    '->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('
) >= 1
    && substr_count($catRepo, '->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause(') >= 1;

$checks['ProductBrandRepository uses both taxonomy unions (operation branch + org-has)'] = substr_count(
    $brandRepo,
    '->taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause('
) >= 1
    && substr_count($brandRepo, '->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause(') >= 1;

$checks['No re-hand-rolled (1=1 + branchOwned) taxonomy OR trees in category/brand repos'] = !str_contains($catRepo, "(1=1' .")
    && !str_contains($brandRepo, "(1=1' .");

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

exit(0);
