<?php

declare(strict_types=1);

/**
 * FOUNDATION-STORAGE-ABSTRACTION-01 — static proof for wave 01 (no DB / no writes).
 *
 * From repository root:
 *   php system/scripts/read-only/verify_foundation_storage_abstraction_wave_01_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$bootstrap = (string) file_get_contents($root . '/system/modules/bootstrap.php');
if (!str_contains($bootstrap, "'register_storage.php'")) {
    fwrite(STDERR, "FAIL: register_storage.php not listed in modules/bootstrap.php\n");
    $failed = true;
}

$mediaSvc = (string) file_get_contents($root . '/system/modules/media/services/MediaAssetUploadService.php');
if (!str_contains($mediaSvc, 'StorageProviderInterface')) {
    fwrite(STDERR, "FAIL: MediaAssetUploadService missing StorageProviderInterface\n");
    $failed = true;
}
if (str_contains($mediaSvc, "base_path('storage/media/quarantine") || str_contains($mediaSvc, 'base_path("storage/media/quarantine')) {
    fwrite(STDERR, "FAIL: MediaAssetUploadService still uses base_path quarantine literal\n");
    $failed = true;
}

$docSvc = (string) file_get_contents($root . '/system/modules/documents/services/DocumentService.php');
if (!str_contains($docSvc, 'StorageProviderInterface')) {
    fwrite(STDERR, "FAIL: DocumentService missing StorageProviderInterface\n");
    $failed = true;
}
if (str_contains($docSvc, "base_path('storage/documents") || str_contains($docSvc, 'base_path("storage/documents')) {
    fwrite(STDERR, "FAIL: DocumentService still uses base_path documents literal\n");
    $failed = true;
}

$mktSvc = (string) file_get_contents($root . '/system/modules/marketing/services/MarketingGiftCardTemplateService.php');
if (!str_contains($mktSvc, 'StorageProviderInterface')) {
    fwrite(STDERR, "FAIL: MarketingGiftCardTemplateService missing StorageProviderInterface\n");
    $failed = true;
}
foreach (["base_path('public/media/processed'", 'base_path("public/media/processed"', "base_path('storage/media/quarantine", 'base_path("storage/media/quarantine"'] as $needle) {
    if (str_contains($mktSvc, $needle)) {
        fwrite(STDERR, "FAIL: MarketingGiftCardTemplateService still contains: {$needle}\n");
        $failed = true;
    }
}

$processor = (string) file_get_contents($root . '/workers/image-pipeline/src/processor.mjs');
if (!str_contains($processor, 'STORAGE_LOCAL_SYSTEM_PATH')) {
    fwrite(STDERR, "FAIL: processor.mjs missing STORAGE_LOCAL_SYSTEM_PATH parity\n");
    $failed = true;
}

$needFiles = [
    '/system/config/storage.php',
    '/system/core/Storage/StorageKey.php',
    '/system/core/Storage/StorageProviderFactory.php',
    '/system/core/Storage/LocalFilesystemStorageProvider.php',
    '/system/core/Storage/Contracts/StorageProviderInterface.php',
    '/system/modules/bootstrap/register_storage.php',
];
foreach ($needFiles as $rel) {
    $p = $root . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($p)) {
        fwrite(STDERR, "FAIL: missing {$rel}\n");
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "verify_foundation_storage_abstraction_wave_01_readonly_01: OK\n";
exit(0);
