<?php

declare(strict_types=1);

/**
 * FND-TNT-24 — Stock movement must not apply on-hand via id-only {@code UPDATE products}; must delegate to
 * {@see \Modules\Inventory\Repositories\ProductRepository::updateStockQuantityForStockMutationInResolvedOrg}
 * (same visibility family as {@see findLockedForStockMutationInResolvedOrg}).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_product_stock_quantity_mutation_resolved_scope_closure_readonly_15.php
 */

$root = dirname(__DIR__, 3);
$system = $root . '/system';

$sms = (string) file_get_contents($system . '/modules/inventory/services/StockMovementService.php');
$prod = (string) file_get_contents($system . '/modules/inventory/repositories/ProductRepository.php');

$failed = false;

if (str_contains($sms, 'UPDATE products SET stock_quantity') || preg_match('/UPDATE\s+products\s+SET\s+stock_quantity/i', $sms) === 1) {
    fwrite(STDERR, "FAIL: StockMovementService must not contain raw UPDATE products stock_quantity.\n");
    $failed = true;
}

if (!str_contains($prod, 'function updateStockQuantityForStockMutationInResolvedOrg')) {
    fwrite(STDERR, "FAIL: ProductRepository must define updateStockQuantityForStockMutationInResolvedOrg.\n");
    $failed = true;
}

if (!str_contains($prod, 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')) {
    fwrite(STDERR, "FAIL: ProductRepository expected to use product catalog union for stock mutation paths.\n");
    $failed = true;
}

if (!str_contains($sms, 'updateStockQuantityForStockMutationInResolvedOrg')) {
    fwrite(STDERR, "FAIL: StockMovementService must call updateStockQuantityForStockMutationInResolvedOrg.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo 'FND-TNT-24 product stock_quantity mutation resolved-scope closure: OK' . PHP_EOL;
exit(0);
