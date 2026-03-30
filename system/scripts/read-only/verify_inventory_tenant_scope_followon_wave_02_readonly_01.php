<?php

declare(strict_types=1);

/**
 * INVENTORY-TENANT-DATA-PLANE-HARDENING-01 — FOLLOW-ON-WAVE-02 static proof: taxonomy index/list + parent batch map,
 * tenant-scoped parent validation, product taxonomy assignability, invoice settlement product read, duplicate relink/retire helpers.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_tenant_scope_followon_wave_02_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$catRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductCategoryRepository.php');
$brandRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductBrandRepository.php');
$prodRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductRepository.php');
$catCtl = (string) file_get_contents($system . '/modules/inventory/controllers/ProductCategoryController.php');
$brandCtl = (string) file_get_contents($system . '/modules/inventory/controllers/ProductBrandController.php');
$assign = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyAssignabilityService.php');
$prodSvc = (string) file_get_contents($system . '/modules/inventory/services/ProductService.php');
$invSettle = (string) file_get_contents($system . '/modules/inventory/services/InvoiceStockSettlementService.php');
$dupRelink = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryDuplicateParentCanonicalRelinkService.php');
$retire = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyDuplicateNoncanonicalRetireService.php');
$postTree = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_inventory.php');

$checks['ProductCategoryRepository: listInTenantScope + mapByIdsForParentLabelLookupInTenantScope + ancestorChainContainsIdInTenantScope'] = str_contains($catRepo, 'function listInTenantScope')
    && str_contains($catRepo, 'function mapByIdsForParentLabelLookupInTenantScope')
    && str_contains($catRepo, 'function ancestorChainContainsIdInTenantScope')
    && str_contains($catRepo, 'assertValidParentAssignment(?int $categoryId, ?int $parentId, int $operationBranchId)')
    && str_contains($catRepo, 'findInTenantScope($parentId, $operationBranchId)')
    && str_contains($catRepo, 'ancestorChainContainsIdInTenantScope($parentId, $categoryId, $operationBranchId)');
$checks['ProductBrandRepository: listInTenantScope'] = str_contains($brandRepo, 'function listInTenantScope');

$checks['ProductCategoryController index uses listInTenantScope + mapByIds InTenantScope'] = str_contains($catCtl, 'function index')
    && preg_match('/function index\([\s\S]*?listInTenantScope\(\$opBranch\)[\s\S]*?mapByIdsForParentLabelLookupInTenantScope/', $catCtl) === 1;
$checks['ProductCategoryController show uses mapByIdsForParentLabelLookupInTenantScope'] = preg_match(
    '/function show\([\s\S]*?mapByIdsForParentLabelLookupInTenantScope/',
    $catCtl
) === 1;
$checks['ProductBrandController index uses listInTenantScope + requireTaxonomyOperationBranchId'] = preg_match(
    '/function index\([\s\S]*?listInTenantScope\(\$this->requireTaxonomyOperationBranchId\(\)\)/',
    $brandCtl
) === 1;

$checks['ProductTaxonomyAssignabilityService uses findInTenantScope + operationBranchId on assertFinal'] = str_contains($assign, 'assertFinalProductTaxonomy(?int $productBranchId, ?int $categoryId, ?int $brandId, int $operationBranchId)')
    && str_contains($assign, 'findInTenantScope($categoryId, $operationBranchId)')
    && str_contains($assign, 'findInTenantScope($brandId, $operationBranchId)');
$checks['ProductService passes tenantBranchId into assertFinalProductTaxonomy (both paths)'] = substr_count($prodSvc, 'assertFinalProductTaxonomy(') === 2
    && substr_count($prodSvc, 'assertFinalProductTaxonomy($effectiveBranch, $catId, $brandId, $tenantBranchId)') === 1
    && substr_count($prodSvc, 'assertFinalProductTaxonomy($productBranch, $catId, $brandId, $tenantBranchId)') === 1;

$checks['ProductRepository findReadableForStockMutationInResolvedOrg'] = str_contains($prodRepo, 'function findReadableForStockMutationInResolvedOrg');
$checks['InvoiceStockSettlementService uses findReadableForStockMutationInResolvedOrg when invoice branch set'] = str_contains($invSettle, 'findReadableForStockMutationInResolvedOrg($productId, $invoiceBranch)');

$checks['ProductCategoryDuplicateParentCanonicalRelinkService: BranchContext + loadCategoryRowForRelink + scoped cycle check'] = str_contains($dupRelink, 'BranchContext')
    && str_contains($dupRelink, 'loadCategoryRowForRelink')
    && str_contains($dupRelink, 'ancestorChainContainsIdInTenantScope');
$checks['ProductTaxonomyDuplicateNoncanonicalRetireService: BranchContext + loadCategoryRowForTaxonomyDuplicate'] = str_contains($retire, 'BranchContext')
    && str_contains($retire, 'loadCategoryRowForTaxonomyDuplicate')
    && str_contains($retire, 'loadBrandRowForTaxonomyDuplicate');
$checks['ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService: BranchContext + load helpers'] = str_contains($postTree, 'BranchContext')
    && str_contains($postTree, 'loadCategoryRowForTaxonomyDuplicate');

$checks['register_inventory: duplicate relink + retire + post-tree get BranchContext'] = str_contains($bootstrap, 'ProductCategoryDuplicateParentCanonicalRelinkService::class')
    && str_contains($bootstrap, 'ProductTaxonomyDuplicateNoncanonicalRetireService::class')
    && str_contains($bootstrap, 'ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService::class')
    && preg_match('/ProductCategoryDuplicateParentCanonicalRelinkService::class[\s\S]*?BranchContext::class/s', $bootstrap) === 1
    && preg_match('/ProductTaxonomyDuplicateNoncanonicalRetireService::class[\s\S]*?BranchContext::class/s', $bootstrap) === 1
    && preg_match('/ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService::class[\s\S]*?BranchContext::class/s', $bootstrap) === 1;

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

echo PHP_EOL . 'All inventory follow-on wave 02 static checks passed.' . PHP_EOL;
exit(0);
