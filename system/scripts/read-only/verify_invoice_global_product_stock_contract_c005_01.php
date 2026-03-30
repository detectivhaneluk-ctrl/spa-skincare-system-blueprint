<?php

declare(strict_types=1);

/**
 * C-005-GLOBAL-PRODUCT-STOCK-CONTRACT-TRUTH-ALIGNMENT-01: static proof that branch-invoice + global-product
 * settlement can lock products and write movements without contradicting org scope (no DB).
 *
 * Usage:
 *   php system/scripts/read-only/verify_invoice_global_product_stock_contract_c005_01.php
 */

$base = dirname(__DIR__, 2);
$paths = [
    'scope' => $base . '/core/organization/OrganizationRepositoryScope.php',
    'repo' => $base . '/modules/inventory/repositories/ProductRepository.php',
    'movement' => $base . '/modules/inventory/services/StockMovementService.php',
    'contract' => $base . '/modules/inventory/services/InvoiceProductStockBranchContract.php',
];

foreach ($paths as $label => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing {$label}: {$path}\n");
        exit(1);
    }
}

$scope = (string) file_get_contents($paths['scope']);
$repo = (string) file_get_contents($paths['repo']);
$movement = (string) file_get_contents($paths['movement']);
$contract = (string) file_get_contents($paths['contract']);

$checks = [
    'OrganizationRepositoryScope::branchIdBelongsToResolvedOrganizationExistsClause' => str_contains($scope, 'function branchIdBelongsToResolvedOrganizationExistsClause'),
    'ProductRepository::findLockedForStockMutationInResolvedOrg' => str_contains($repo, 'function findLockedForStockMutationInResolvedOrg')
        && str_contains($repo, 'p.branch_id IS NULL')
        && str_contains($repo, 'branchIdBelongsToResolvedOrganizationExistsClause'),
    'StockMovementService uses findLockedForStockMutationInResolvedOrg' => str_contains($movement, 'findLockedForStockMutationInResolvedOrg'),
    'StockMovementService: no findLockedInTenantScope for product lock' => !str_contains($movement, 'findLockedInTenantScope'),
    'InvoiceProductStockBranchContract references stock lock alignment' => str_contains($contract, 'findLockedForStockMutationInResolvedOrg'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
