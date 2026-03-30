<?php

declare(strict_types=1);

/**
 * FND-TNT-25 — ProductRepository generic UPDATE policy: tenant-scoped product updates must not accept
 * {@code stock_quantity}; on-hand changes go through {@see updateStockQuantityForStockMutationInResolvedOrg} only.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_product_stock_quantity_mutation_policy_closure_readonly_16.php
 */

$root = dirname(__DIR__, 3);
$path = $root . '/system/modules/inventory/repositories/ProductRepository.php';
$text = is_file($path) ? (string) file_get_contents($path) : '';

if ($text === '') {
    fwrite(STDERR, "FAIL: could not read ProductRepository.php\n");
    exit(1);
}

$failed = false;

if (!str_contains($text, 'function normalizeForCreate')) {
    fwrite(STDERR, "FAIL: ProductRepository must define normalizeForCreate for INSERT (may include stock_quantity).\n");
    $failed = true;
}

if (!str_contains($text, 'function normalizeForTenantScopedProductUpdate')) {
    fwrite(STDERR, "FAIL: ProductRepository must define normalizeForTenantScopedProductUpdate.\n");
    $failed = true;
}

if (substr_count($text, 'normalizeForTenantScopedProductUpdate($data)') < 2) {
    fwrite(STDERR, "FAIL: normalizeForTenantScopedProductUpdate must be used by at least update + updateInTenantScope.\n");
    $failed = true;
}

if (!preg_match('/function\s+normalizeForTenantScopedProductUpdate[\s\S]*?\'stock_quantity\'/m', $text)) {
    // good: stock_quantity must NOT appear in tenant update normalizer body
} else {
    fwrite(STDERR, "FAIL: normalizeForTenantScopedProductUpdate must not list stock_quantity in its allow list.\n");
    $failed = true;
}

if (!preg_match('/function\s+normalizeForCreate[\s\S]*?\'stock_quantity\'/m', $text)) {
    fwrite(STDERR, "FAIL: normalizeForCreate must allow stock_quantity for INSERT-only initial row.\n");
    $failed = true;
}

if (!str_contains($text, 'normalizeForCreate($data)')) {
    fwrite(STDERR, "FAIL: create() must use normalizeForCreate.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo 'FND-TNT-25 product stock_quantity generic update policy closure: OK' . PHP_EOL;
exit(0);
