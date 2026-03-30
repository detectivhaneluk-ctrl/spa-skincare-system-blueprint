<?php

declare(strict_types=1);

/**
 * INVENTORY-TENANT-DATA-PLANE-HARDENING-01 — static proof: product brand/category taxonomy
 * HTTP + service + product label enrichment paths use org-scoped findInTenantScope (not id-only find).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_taxonomy_tenant_scope_readonly_01.php
 *
 * Exit: 0 = all checks passed, 1 = failure.
 */

$system = dirname(__DIR__, 2);
$checks = [];

$brandRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductBrandRepository.php');
$catRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductCategoryRepository.php');
$brandCtl = (string) file_get_contents($system . '/modules/inventory/controllers/ProductBrandController.php');
$catCtl = (string) file_get_contents($system . '/modules/inventory/controllers/ProductCategoryController.php');
$brandSvc = (string) file_get_contents($system . '/modules/inventory/services/ProductBrandService.php');
$catSvc = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryService.php');
$prodCtl = (string) file_get_contents($system . '/modules/inventory/controllers/ProductController.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_inventory.php');

$checks['ProductBrandRepository has findInTenantScope + org scope SQL'] = str_contains($brandRepo, 'function findInTenantScope')
    && str_contains($brandRepo, 'OrganizationRepositoryScope')
    && str_contains($brandRepo, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause');
$checks['ProductCategoryRepository has findInTenantScope + org scope SQL'] = str_contains($catRepo, 'function findInTenantScope')
    && str_contains($catRepo, 'OrganizationRepositoryScope')
    && str_contains($catRepo, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause');

$checks['register_inventory wires taxonomy repos with OrganizationRepositoryScope'] = str_contains($bootstrap, 'ProductCategoryRepository::class')
    && preg_match('/ProductCategoryRepository::class[^;]+OrganizationRepositoryScope::class/s', $bootstrap) === 1
    && preg_match('/ProductBrandRepository::class[^;]+OrganizationRepositoryScope::class/s', $bootstrap) === 1;

$checks['ProductBrandController CRUD loads use findInTenantScope'] = substr_count($brandCtl, 'findInTenantScope') >= 4
    && !str_contains($brandCtl, '->find($id)')
    && str_contains($brandCtl, 'requireTaxonomyOperationBranchId');
$checks['ProductCategoryController CRUD loads use findInTenantScope'] = substr_count($catCtl, 'findInTenantScope') >= 4
    && !str_contains($catCtl, '->find($id)')
    && str_contains($catCtl, 'requireTaxonomyOperationBranchId');

$checks['ProductBrandService update/delete load via findInTenantScope'] = str_contains($brandSvc, 'findInTenantScope($id, $opBranch)')
    && substr_count($brandSvc, 'findInTenantScope') >= 2;
$checks['ProductCategoryService update/delete/parent check use findInTenantScope'] = str_contains($catSvc, 'findInTenantScope($id, $opBranch)')
    && str_contains($catSvc, 'findInTenantScope($parentId, $parentLookupBranch)');

$checks['ProductController::withTaxonomyLabels uses findInTenantScope for category and brand'] = preg_match(
    '/function withTaxonomyLabels[\s\S]*productCategoryRepo->findInTenantScope[\s\S]*productBrandRepo->findInTenantScope/s',
    $prodCtl
) === 1;

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'All inventory taxonomy tenant-scope static checks passed.' . PHP_EOL;
exit(0);
