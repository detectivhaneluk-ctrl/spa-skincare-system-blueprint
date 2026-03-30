<?php

declare(strict_types=1);

/**
 * FOUNDATION-STORAGE-ABSTRACTION-02 — static proof: serving/streaming closure (no DB / no writes).
 *
 * From repository root:
 *   php system/scripts/read-only/verify_foundation_storage_abstraction_wave_02_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$iface = (string) file_get_contents($root . '/system/core/Storage/Contracts/StorageProviderInterface.php');
foreach (
    [
        'supportsPublicFilesystemPath',
        'resolvePublicFilesystemPathIfSupported',
        'resolvePublicUrl',
        'openReadStream',
        'readStreamToOutput',
    ] as $needle
) {
    if (!str_contains($iface, $needle)) {
        fwrite(STDERR, "FAIL: StorageProviderInterface missing: {$needle}\n");
        $failed = true;
    }
}

$local = (string) file_get_contents($root . '/system/core/Storage/LocalFilesystemStorageProvider.php');
foreach (['function supportsPublicFilesystemPath', 'function openReadStream', 'function readStreamToOutput'] as $needle) {
    if (!str_contains($local, $needle)) {
        fwrite(STDERR, "FAIL: LocalFilesystemStorageProvider missing: {$needle}\n");
        $failed = true;
    }
}

$disp = (string) file_get_contents($root . '/system/core/router/Dispatcher.php');
if (!str_contains($disp, 'readStreamToOutput')) {
    fwrite(STDERR, "FAIL: Dispatcher must call storage readStreamToOutput for processed media.\n");
    $failed = true;
}
if (!str_contains($disp, 'StorageKey::publicMedia')) {
    fwrite(STDERR, "FAIL: Dispatcher must build StorageKey::publicMedia for processed path.\n");
    $failed = true;
}
if (str_contains($disp, 'readfile(')) {
    fwrite(STDERR, "FAIL: Dispatcher must not use readfile() (use storage stream).\n");
    $failed = true;
}
if (preg_match('/realpath\s*\(\s*\$publicRoot/m', $disp) === 1 || str_contains($disp, "realpath(\$publicRoot")) {
    fwrite(STDERR, "FAIL: Dispatcher tryServePublicProcessedMedia must not realpath publicRoot.\n");
    $failed = true;
}

$doc = (string) file_get_contents($root . '/system/modules/documents/services/DocumentService.php');
if (!str_contains($doc, 'readStreamToOutput')) {
    fwrite(STDERR, "FAIL: DocumentService must stream downloads via readStreamToOutput.\n");
    $failed = true;
}
if (str_contains($doc, 'readfile(')) {
    fwrite(STDERR, "FAIL: DocumentService must not use readfile().\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_foundation_storage_abstraction_wave_02_readonly_01: OK\n";
exit(0);
