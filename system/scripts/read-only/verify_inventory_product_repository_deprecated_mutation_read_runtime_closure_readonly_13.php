<?php

declare(strict_types=1);

/**
 * FND-TNT-21 — ProductRepository deprecated id-only read/mutation closure: tenant module code must not call
 * {@see \Modules\Inventory\Repositories\ProductRepository::find}, {@see findLocked}, {@see update}, or {@see softDelete}.
 * Repair flows use resolved-catalog helpers (e.g. {@see updateTaxonomyFkPatchInResolvedTenantCatalog}).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_product_repository_deprecated_mutation_read_runtime_closure_readonly_13.php
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';

if (!is_dir($modules)) {
    fwrite(STDERR, "FAIL: expected system/modules at {$modules}\n");
    exit(1);
}

/** @var list<string> */
$violations = [];

$weakReceiverPatterns = [
    '/\$this->productRepo->find\s*\(/',
    '/\$this->productRepo->findLocked\s*\(/',
    '/\$this->productRepo->update\s*\(/',
    '/\$this->productRepo->softDelete\s*\(/',
    '/\$this->products->find\s*\(/',
    '/\$this->products->findLocked\s*\(/',
    '/\$this->products->update\s*\(/',
    '/\$this->products->softDelete\s*\(/',
    '/\$this->productRepository->find\s*\(/',
    '/\$this->productRepository->findLocked\s*\(/',
    '/\$this->productRepository->update\s*\(/',
    '/\$this->productRepository->softDelete\s*\(/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    if (str_replace('\\', '/', $path) === str_replace('\\', '/', $modules . '/inventory/repositories/ProductRepository.php')) {
        continue;
    }

    $text = (string) file_get_contents($path);
    if ($text === '' || !str_contains($text, 'ProductRepository')) {
        continue;
    }

    foreach ($weakReceiverPatterns as $rx) {
        if (preg_match($rx, $text) === 1) {
            $violations[] = "Deprecated ProductRepository id-only call ({$rx}): {$path}";
        }
    }

    if (preg_match('/private\s+ProductRepository\s+\$repo\b/', $text) === 1) {
        foreach (['find', 'findLocked', 'update', 'softDelete'] as $meth) {
            if (preg_match('/\$this->repo->' . preg_quote($meth, '/') . '\s*\(/', $text) === 1) {
                $violations[] = "ProductRepository injected as \$repo must not use ->{$meth}(: {$path}";
            }
        }
    }
}

$prodRepoPath = $modules . '/inventory/repositories/ProductRepository.php';
$prodText = (string) file_get_contents($prodRepoPath);
if (!str_contains($prodText, 'function updateTaxonomyFkPatchInResolvedTenantCatalog')) {
    $violations[] = 'ProductRepository must define updateTaxonomyFkPatchInResolvedTenantCatalog';
}
$backfill = (string) file_get_contents($modules . '/inventory/services/ProductTaxonomyLegacyBackfillService.php');
if (str_contains($backfill, '$this->products->update(')) {
    $violations[] = 'ProductTaxonomyLegacyBackfillService must not call deprecated products->update(';
}
if (!str_contains($backfill, 'updateTaxonomyFkPatchInResolvedTenantCatalog')) {
    $violations[] = 'ProductTaxonomyLegacyBackfillService must call updateTaxonomyFkPatchInResolvedTenantCatalog';
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, 'FAIL: ' . $v . PHP_EOL);
    }
    exit(1);
}

echo 'FND-TNT-21 ProductRepository deprecated mutation/read runtime closure: OK' . PHP_EOL;
exit(0);
