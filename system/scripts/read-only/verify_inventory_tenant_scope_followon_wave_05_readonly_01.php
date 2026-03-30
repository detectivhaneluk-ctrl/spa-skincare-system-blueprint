<?php

declare(strict_types=1);

/**
 * INVENTORY-TENANT-DATA-PLANE-HARDENING-01 — FOLLOW-ON-WAVE-05 static proof: product update/delete in tenant scope,
 * invoice-plane–joined stock settlement aggregates, scoped taxonomy backfill + orphan audit + duplicate retire/post-tree deletes,
 * catalog-scoped duplicate group listing + parent cycle walk without unscoped find.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_tenant_scope_followon_wave_05_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$prodRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductRepository.php');
$prodSvc = (string) file_get_contents($system . '/modules/inventory/services/ProductService.php');
$smRepo = (string) file_get_contents($system . '/modules/inventory/repositories/StockMovementRepository.php');
$backfill = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyLegacyBackfillService.php');
$orphan = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyOrphanFkAuditService.php');
$catRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductCategoryRepository.php');
$brandRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductBrandRepository.php');
$dupRelink = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryDuplicateParentCanonicalRelinkService.php');
$retire = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyDuplicateNoncanonicalRetireService.php');
$postTree = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_inventory.php');

$checks['ProductRepository: updateInTenantScope + softDeleteInTenantScope + backfill/orphan InResolvedTenantCatalog'] = str_contains($prodRepo, 'function updateInTenantScope')
    && str_contains($prodRepo, 'function softDeleteInTenantScope')
    && str_contains($prodRepo, 'listNonDeletedForTaxonomyBackfillInResolvedTenantCatalog')
    && str_contains($prodRepo, 'countNonDeletedProductsInResolvedTenantCatalog')
    && str_contains($prodRepo, 'clearOrphanProductCategoryFkOnActiveProductsInResolvedTenantCatalog');

$checks['ProductService uses scoped product update/delete'] = str_contains($prodSvc, 'updateInTenantScope($id, $tenantBranchId, $data)')
    && str_contains($prodSvc, 'softDeleteInTenantScope($id, $tenantBranchId)');

$checks['StockMovementRepository: SalesTenantScope + invoice join path for settlement aggregates'] = str_contains($smRepo, 'use Modules\\Sales\\Services\\SalesTenantScope')
    && str_contains($smRepo, 'private SalesTenantScope $salesTenantScope')
    && str_contains($smRepo, 'INNER JOIN invoices inv')
    && str_contains($smRepo, 'sumNetQuantityForInvoiceItem');

$checks['register_inventory wires StockMovementRepository with SalesTenantScope'] = (bool) preg_match(
    '/StockMovementRepository::class,\s*fn\s*\(\$c\)\s*=>\s*new\s+\\\\Modules\\\\Inventory\\\\Repositories\\\\StockMovementRepository\([^;]+SalesTenantScope::class/s',
    $bootstrap
);

$checks['ProductTaxonomyLegacyBackfillService uses scoped product list'] = str_contains($backfill, 'listNonDeletedForTaxonomyBackfillInResolvedTenantCatalog()');

$checks['ProductTaxonomyOrphanFkAuditService uses scoped counts/lists/clear + scoped duplicate groups'] = str_contains($orphan, 'countNonDeletedProductsInResolvedTenantCatalog()')
    && str_contains($orphan, 'listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope()')
    && str_contains($orphan, 'clearOrphanProductCategoryFkOnActiveProductsInResolvedTenantCatalog()');

$checks['ProductCategoryRepository: scoped duplicate groups + softDeleteLive + ancestorChain InResolvedTenantCatalog'] = str_contains($catRepo, 'listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope')
    && str_contains($catRepo, 'softDeleteLiveInResolvedTenantCatalogScope')
    && str_contains($catRepo, 'ancestorChainContainsIdInResolvedTenantCatalogScope');

$checks['ProductBrandRepository: scoped duplicate groups + softDeleteLive'] = str_contains($brandRepo, 'listDuplicateTrimmedNameGroupsInResolvedTenantCatalogScope')
    && str_contains($brandRepo, 'softDeleteLiveInResolvedTenantCatalogScope');

$checks['Duplicate parent relink uses ancestorChainContainsIdInResolvedTenantCatalogScope'] = str_contains($dupRelink, 'ancestorChainContainsIdInResolvedTenantCatalogScope($newParentId, $childCategoryId)');

$checks['Retire + post-tree apply use softDeleteLiveInResolvedTenantCatalogScope'] = str_contains($retire, 'softDeleteLiveInResolvedTenantCatalogScope')
    && str_contains($postTree, 'softDeleteLiveInResolvedTenantCatalogScope');

$checks['Inventory HTTP controllers: no unscoped repo ->find() calls'] = !str_contains((string) file_get_contents($system . '/modules/inventory/controllers/ProductController.php'), '->find(')
    && !str_contains((string) file_get_contents($system . '/modules/inventory/controllers/StockMovementController.php'), '->find(')
    && !str_contains((string) file_get_contents($system . '/modules/inventory/controllers/InventoryCountController.php'), '->find(')
    && !str_contains((string) file_get_contents($system . '/modules/inventory/controllers/SupplierController.php'), '->find(');

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

echo 'INVENTORY tenant scope follow-on wave 05 readonly proof: OK' . PHP_EOL;
exit(0);
