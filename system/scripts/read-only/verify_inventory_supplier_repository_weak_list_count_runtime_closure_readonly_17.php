<?php

declare(strict_types=1);

/**
 * FND-TNT-23 — SupplierRepository: tenant module code must not call deprecated unscoped
 * {@see \Modules\Inventory\Repositories\SupplierRepository::find}, {@see list}, {@see count}, or id-only
 * {@see update}, {@see softDelete}. Use *InTenantScope variants from tenant runtime.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_supplier_repository_weak_list_count_runtime_closure_readonly_17.php
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';

if (!is_dir($modules)) {
    fwrite(STDERR, "FAIL: expected system/modules at {$modules}\n");
    exit(1);
}

/** @var list<string> */
$violations = [];

$weakRepoPatterns = [
    '/\$this->repo->find\s*\(/',
    '/\$this->repo->list\s*\(/',
    '/\$this->repo->count\s*\(/',
    '/\$this->repo->update\s*\(/',
    '/\$this->repo->softDelete\s*\(/',
];

$supPath = str_replace('\\', '/', $modules . '/inventory/repositories/SupplierRepository.php');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    if (str_replace('\\', '/', $path) === $supPath) {
        continue;
    }

    $text = (string) file_get_contents($path);
    if ($text === '' || !str_contains($text, 'SupplierRepository')) {
        continue;
    }

    foreach ($weakRepoPatterns as $rx) {
        if (preg_match($rx, $text) === 1) {
            $violations[] = "Weak SupplierRepository call ({$rx}): {$path}";
        }
    }

    if (preg_match('/private\s+SupplierRepository\s+\$repo\b/', $text) === 1) {
        foreach (['find', 'list', 'count', 'update', 'softDelete'] as $meth) {
            if (preg_match('/\$this->repo->' . preg_quote($meth, '/') . '\s*\(/', $text) === 1) {
                $violations[] = "SupplierRepository injected as \$repo must not use ->{$meth}(: {$path}";
            }
        }
    }
}

$supText = (string) file_get_contents($supPath);
if (!str_contains($supText, 'function findInTenantScope')) {
    $violations[] = 'SupplierRepository must define findInTenantScope';
}
if (!str_contains($supText, 'function listInTenantScope')) {
    $violations[] = 'SupplierRepository must define listInTenantScope';
}
if (!str_contains($supText, 'function countInTenantScope')) {
    $violations[] = 'SupplierRepository must define countInTenantScope';
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, 'FAIL: ' . $v . PHP_EOL);
    }
    exit(1);
}

echo 'FND-TNT-23 SupplierRepository weak list/count + id-only mutation runtime closure: OK' . PHP_EOL;
exit(0);
