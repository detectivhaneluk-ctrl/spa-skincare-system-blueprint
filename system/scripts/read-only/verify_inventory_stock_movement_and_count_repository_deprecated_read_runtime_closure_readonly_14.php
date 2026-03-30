<?php

declare(strict_types=1);

/**
 * FND-TNT-22 — StockMovementRepository + InventoryCountRepository: tenant module code must not call deprecated
 * unscoped {@see find}, {@see list}, or {@see count} (no product/org guard on joins where applicable).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_inventory_stock_movement_and_count_repository_deprecated_read_runtime_closure_readonly_14.php
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
];

$weakStockMovementsPatterns = [
    '/\$this->stockMovements->find\s*\(/',
    '/\$this->stockMovements->list\s*\(/',
    '/\$this->stockMovements->count\s*\(/',
];

$smPath = str_replace('\\', '/', $modules . '/inventory/repositories/StockMovementRepository.php');
$icPath = str_replace('\\', '/', $modules . '/inventory/repositories/InventoryCountRepository.php');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = $fileInfo->getPathname();
    $normPath = str_replace('\\', '/', $path);
    if ($normPath === $smPath || $normPath === $icPath) {
        continue;
    }

    $text = (string) file_get_contents($path);
    if ($text === '') {
        continue;
    }

    if (str_contains($text, 'StockMovementRepository')) {
        foreach ($weakRepoPatterns as $rx) {
            if (preg_match($rx, $text) === 1) {
                $violations[] = "StockMovementRepository: weak call ({$rx}) in file that references StockMovementRepository: {$path}";
            }
        }
        foreach ($weakStockMovementsPatterns as $rx) {
            if (preg_match($rx, $text) === 1) {
                $violations[] = "StockMovementRepository: weak call ({$rx}): {$path}";
            }
        }
    }

    if (str_contains($text, 'InventoryCountRepository')) {
        foreach ($weakRepoPatterns as $rx) {
            if (preg_match($rx, $text) === 1) {
                $violations[] = "InventoryCountRepository: weak call ({$rx}) in file that references InventoryCountRepository: {$path}";
            }
        }
    }
}

$smText = (string) file_get_contents($smPath);
$icText = (string) file_get_contents($icPath);
if (!str_contains($smText, 'function findInTenantScope')) {
    $violations[] = 'StockMovementRepository must define findInTenantScope';
}
if (!str_contains($icText, 'function findInTenantScope')) {
    $violations[] = 'InventoryCountRepository must define findInTenantScope';
}
if (!str_contains($smText, '@deprecated') || !str_contains($smText, 'public function find(int $id): ?array')) {
    $violations[] = 'StockMovementRepository must retain deprecated find(int $id): ?array with @deprecated docblock';
}
if (!str_contains($icText, '@deprecated') || !str_contains($icText, 'public function find(int $id): ?array')) {
    $violations[] = 'InventoryCountRepository must retain deprecated find(int $id): ?array with @deprecated docblock';
}

if ($violations !== []) {
    foreach ($violations as $v) {
        fwrite(STDERR, 'FAIL: ' . $v . PHP_EOL);
    }
    exit(1);
}

echo 'FND-TNT-22 StockMovement + InventoryCount deprecated read runtime closure: OK' . PHP_EOL;
exit(0);
