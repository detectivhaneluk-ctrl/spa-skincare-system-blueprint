<?php

declare(strict_types=1);

/**
 * FND-TNT-20 — ProductRepository weak list/count/search closure: tenant module code must not call deprecated
 * {@see \Modules\Inventory\Repositories\ProductRepository::list}, {@see count}, or unscoped
 * {@see listActiveForUnifiedCatalog}; {@see genericSearchCondition} must not appear outside ProductRepository.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_product_repository_weak_list_count_runtime_closure_readonly_12.php
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
    '/\$this->productRepo->list\s*\(/',
    '/\$this->productRepo->count\s*\(/',
    '/\$this->products->list\s*\(/',
    '/\$this->products->count\s*\(/',
    '/\$this->productRepository->list\s*\(/',
    '/\$this->productRepository->count\s*\(/',
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
            $violations[] = "Weak ProductRepository list/count call ({$rx}): {$path}";
        }
    }

    if (preg_match('/private\s+ProductRepository\s+\$repo\b/', $text) === 1) {
        if (preg_match('/\$this->repo->list\s*\(/', $text) === 1) {
            $violations[] = "ProductRepository injected as \$repo must not use ->list(: {$path}";
        }
        if (preg_match('/\$this->repo->count\s*\(/', $text) === 1) {
            $violations[] = "ProductRepository injected as \$repo must not use ->count(: {$path}";
        }
    }

    if (str_contains($text, 'listActiveForUnifiedCatalog(')) {
        $violations[] = "Deprecated listActiveForUnifiedCatalog( must not appear outside ProductRepository: {$path}";
    }

    if (str_contains($text, 'genericSearchCondition(')) {
        $violations[] = "genericSearchCondition( must remain private to ProductRepository: {$path}";
    }
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, 'FAIL: ' . $v . PHP_EOL);
    }
    exit(1);
}

echo 'FND-TNT-20 ProductRepository weak list/count runtime closure: OK' . PHP_EOL;
exit(0);
