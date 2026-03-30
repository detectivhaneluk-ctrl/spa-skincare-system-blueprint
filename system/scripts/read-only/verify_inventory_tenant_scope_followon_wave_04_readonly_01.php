<?php

declare(strict_types=1);

/**
 * INVENTORY-TENANT-DATA-PLANE-HARDENING-01 — FOLLOW-ON-WAVE-04 static proof: invoice product line read contract,
 * org-scoped unified catalog lists (cashier / sellable provider), supplier scoped mutations, taxonomy duplicate repair
 * fallbacks to catalog-scoped row reads, deprecated unscoped inventory repo primitives marked.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_tenant_scope_followon_wave_04_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$prodRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductRepository.php');
$brandRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductBrandRepository.php');
$supRepo = (string) file_get_contents($system . '/modules/inventory/repositories/SupplierRepository.php');
$smRepo = (string) file_get_contents($system . '/modules/inventory/repositories/StockMovementRepository.php');
$icRepo = (string) file_get_contents($system . '/modules/inventory/repositories/InventoryCountRepository.php');
$invSvc = (string) file_get_contents($system . '/modules/sales/services/InvoiceService.php');
$cashier = (string) file_get_contents($system . '/modules/sales/services/CashierWorkspaceViewDataBuilder.php');
$catalog = (string) file_get_contents($system . '/modules/sales/providers/CatalogSellableReadModelProviderImpl.php');
$supSvc = (string) file_get_contents($system . '/modules/inventory/services/SupplierService.php');
$dupRelink = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryDuplicateParentCanonicalRelinkService.php');
$retire = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyDuplicateNoncanonicalRetireService.php');
$postTree = (string) file_get_contents($system . '/modules/inventory/services/ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService.php');

$checks['ProductRepository: invoice line contract + org-scoped active catalog lists'] = str_contains($prodRepo, 'function findForInvoiceProductLineAssignmentContractInResolvedOrg')
    && str_contains($prodRepo, 'function listActiveForUnifiedCatalogInResolvedOrg')
    && str_contains($prodRepo, 'function listActiveOrgGlobalCatalogInResolvedOrg');

$checks['InvoiceService assertInvoiceItems uses findForInvoiceProductLineAssignmentContractInResolvedOrg'] = str_contains($invSvc, 'findForInvoiceProductLineAssignmentContractInResolvedOrg($pid, $invoiceBranchId)')
    && !str_contains($invSvc, '$this->productRepo->find(');

$checks['CashierWorkspaceViewDataBuilder uses org-scoped catalog lists'] = str_contains($cashier, 'activeProductsForCashierBranch')
    && str_contains($cashier, 'listActiveForUnifiedCatalogInResolvedOrg')
    && str_contains($cashier, 'listActiveOrgGlobalCatalogInResolvedOrg');

$checks['CatalogSellableReadModelProviderImpl uses org-scoped product lists'] = str_contains($catalog, 'listActiveForUnifiedCatalogInResolvedOrg')
    && str_contains($catalog, 'listActiveOrgGlobalCatalogInResolvedOrg')
    && !str_contains($catalog, 'listActiveForUnifiedCatalog(');

$checks['SupplierRepository: updateInTenantScope + softDeleteInTenantScope'] = str_contains($supRepo, 'function updateInTenantScope')
    && str_contains($supRepo, 'function softDeleteInTenantScope');

$checks['SupplierService uses scoped update/delete'] = str_contains($supSvc, 'updateInTenantScope($id, $tenantBranchId, $data)')
    && str_contains($supSvc, 'softDeleteInTenantScope($id, $tenantBranchId)');

$checks['ProductBrandRepository findLiveInResolvedTenantCatalogScope'] = str_contains($brandRepo, 'function findLiveInResolvedTenantCatalogScope');

$checks['Taxonomy duplicate repair services: catalog-scoped fallback reads'] = str_contains($dupRelink, 'findLiveInResolvedTenantCatalogScope($id)')
    && str_contains($retire, 'findLiveInResolvedTenantCatalogScope($id)')
    && str_contains($postTree, 'findLiveInResolvedTenantCatalogScope($id)')
    && !str_contains($retire, 'return $this->categories->find($id)')
    && !str_contains($retire, 'return $this->brands->find($id)')
    && !str_contains($postTree, 'return $this->categories->find($id)')
    && !str_contains($postTree, 'return $this->brands->find($id)');

$checks['StockMovementRepository marks unscoped find/list/count deprecated in docblock'] = str_contains($smRepo, '@deprecated')
    && str_contains($smRepo, 'findInTenantScope');

$checks['InventoryCountRepository marks unscoped find/list/count deprecated in docblock'] = str_contains($icRepo, '@deprecated')
    && str_contains($icRepo, 'findInTenantScope');

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

echo 'INVENTORY tenant scope follow-on wave 04 readonly proof: OK' . PHP_EOL;
exit(0);
