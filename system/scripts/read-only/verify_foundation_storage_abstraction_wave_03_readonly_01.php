<?php

declare(strict_types=1);

/**
 * FOUNDATION-STORAGE-ABSTRACTION-03 — static proof: checksum + stream validation (no DB / no writes).
 *
 * From repository root:
 *   php system/scripts/read-only/verify_foundation_storage_abstraction_wave_03_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$iface = (string) file_get_contents($root . '/system/core/Storage/Contracts/StorageProviderInterface.php');
foreach (['supportsContentHashing', 'computeSha256HexForKey'] as $needle) {
    if (!str_contains($iface, $needle)) {
        fwrite(STDERR, "FAIL: StorageProviderInterface missing: {$needle}\n");
        $failed = true;
    }
}

$local = (string) file_get_contents($root . '/system/core/Storage/LocalFilesystemStorageProvider.php');
if (!str_contains($local, 'function supportsContentHashing') || !str_contains($local, 'function computeSha256HexForKey')) {
    fwrite(STDERR, "FAIL: LocalFilesystemStorageProvider must implement hashing methods.\n");
    $failed = true;
}
if (!str_contains($local, 'hash_update_stream')) {
    fwrite(STDERR, "FAIL: computeSha256HexForKey should use hash_update_stream.\n");
    $failed = true;
}

$doc = (string) file_get_contents($root . '/system/modules/documents/services/DocumentService.php');
if (str_contains($doc, 'hash_file(')) {
    fwrite(STDERR, "FAIL: DocumentService must not use hash_file().\n");
    $failed = true;
}
if (!str_contains($doc, 'computeSha256HexForKey')) {
    fwrite(STDERR, "FAIL: DocumentService must use computeSha256HexForKey.\n");
    $failed = true;
}

$media = (string) file_get_contents($root . '/system/modules/media/services/MediaAssetUploadService.php');
if (str_contains($media, 'hash_file(') || str_contains($media, 'localFilesystemPathFor')) {
    fwrite(STDERR, "FAIL: MediaAssetUploadService must not use hash_file or localFilesystemPathFor for staging.\n");
    $failed = true;
}
if (!str_contains($media, 'computeSha256HexForKey')) {
    fwrite(STDERR, "FAIL: MediaAssetUploadService must use computeSha256HexForKey.\n");
    $failed = true;
}

$sig = (string) file_get_contents($root . '/system/modules/media/services/MediaImageSignatureValidator.php');
if (!str_contains($sig, 'function validateFromStream')) {
    fwrite(STDERR, "FAIL: MediaImageSignatureValidator must expose validateFromStream.\n");
    $failed = true;
}
if (str_contains($sig, 'finfo->file(')) {
    fwrite(STDERR, "FAIL: MediaImageSignatureValidator must not use finfo->file (use buffer).\n");
    $failed = true;
}

$mkt = (string) file_get_contents($root . '/system/modules/marketing/services/MarketingGiftCardTemplateService.php');
if (str_contains($mkt, 'localFilesystemPathFor($vk)')) {
    fwrite(STDERR, "FAIL: MarketingGiftCardTemplateService variant purge must not call localFilesystemPathFor on vk.\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_foundation_storage_abstraction_wave_03_readonly_01: OK\n";
exit(0);
