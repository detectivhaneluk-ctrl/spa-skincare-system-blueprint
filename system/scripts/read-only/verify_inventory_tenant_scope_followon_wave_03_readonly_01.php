<?php

declare(strict_types=1);

/**
 * INVENTORY-TENANT-DATA-PLANE-HARDENING-01 — FOLLOW-ON-WAVE-03 static proof: tenant-scoped tree audit/cycle discovery,
 * safe-break row load, HQ invoice global product read, taxonomy select lists org-scoped.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_tenant_scope_followon_wave_03_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$orgScope = (string) file_get_contents($system . '/core/Organization/OrganizationRepositoryScope.php');
$catRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductCategoryRepository.php');
$brandRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductBrandRepository.php');
$prodRepo = (string) file_get_contents($system . '/modules/inventory/repositories/ProductRepository.php');
$treeInt = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryTreeIntegrityAuditService.php');
$cycleAudit = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryTreeCycleClusterAuditService.php');
$safeBreak = (string) file_get_contents($system . '/modules/inventory/services/ProductCategoryTreeCycleClusterSafeBreakService.php');
$invSettle = (string) file_get_contents($system . '/modules/inventory/services/InvoiceStockSettlementService.php');
$bootstrap = (string) file_get_contents($system . '/modules/bootstrap/register_inventory.php');

$checks['OrganizationRepositoryScope: resolvedTenantOrganizationHasLiveBranchExistsClause + getAnyLiveBranchId'] = str_contains($orgScope, 'function resolvedTenantOrganizationHasLiveBranchExistsClause')
    && str_contains($orgScope, 'function getAnyLiveBranchIdForResolvedTenantOrganization');

$checks['ProductCategoryRepository: listAll + findLiveInResolved + graph audit scoped'] = str_contains($catRepo, 'function listAllLiveInResolvedTenantCatalogScope')
    && str_contains($catRepo, 'function findLiveInResolvedTenantCatalogScope')
    && str_contains($catRepo, 'function listLiveForParentGraphAuditInResolvedTenantCatalogScope')
    && str_contains($catRepo, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause');

$checks['ProductBrandRepository listSelectable uses org scope (tenantVis)'] = str_contains($brandRepo, 'taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause')
    && str_contains($brandRepo, 'listSelectableForProductBranch');

$checks['ProductRepository findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg'] = str_contains($prodRepo, 'function findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg');

$checks['InvoiceStockSettlementService HQ path uses findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg'] = str_contains($invSettle, 'findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg($productId)');

$checks['ProductCategoryTreeIntegrityAuditService: listAllLive + findLiveInResolved + InTenantScope cycle + BranchContext'] = preg_match(
    '/listAllLiveInResolvedTenantCatalogScope[\s\S]*?findLiveInResolvedTenantCatalogScope\(\$parentId\)[\s\S]*?ancestorChainContainsIdInTenantScope/',
    $treeInt
) === 1 && str_contains($treeInt, 'BranchContext');

$checks['ProductCategoryTreeCycleClusterAuditService uses listLiveForParentGraphAuditInResolvedTenantCatalogScope'] = str_contains(
    $cycleAudit,
    'listLiveForParentGraphAuditInResolvedTenantCatalogScope'
);

$checks['ProductCategoryTreeCycleClusterSafeBreakService uses findLiveInResolvedTenantCatalogScope'] = str_contains(
    $safeBreak,
    'findLiveInResolvedTenantCatalogScope($breakId)'
);

$checks['register_inventory: TreeIntegrityAudit gets BranchContext'] = preg_match(
    '/ProductCategoryTreeIntegrityAuditService::class[\s\S]*?BranchContext::class/s',
    $bootstrap
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

echo PHP_EOL . 'All inventory follow-on wave 03 static checks passed.' . PHP_EOL;
exit(0);
