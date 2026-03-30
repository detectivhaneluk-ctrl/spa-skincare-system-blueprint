<?php

declare(strict_types=1);

/**
 * FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02 — storage factory + drivers truth.
 *
 *   php system/scripts/read-only/verify_storage_driver_factory_truth_readonly_02.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

$factory = $root . '/core/Storage/StorageProviderFactory.php';
if (!is_readable($factory)) {
    $fail[] = 'Missing StorageProviderFactory';
} else {
    $f = (string) file_get_contents($factory);
    if (!str_contains($f, 's3_compatible') || !str_contains($f, 'S3CompatibleObjectStorageProvider')) {
        $fail[] = 'StorageProviderFactory must support s3_compatible';
    }
    if (!str_contains($f, 'local')) {
        $fail[] = 'StorageProviderFactory must support local';
    }
}

$s3 = $root . '/core/Storage/S3CompatibleObjectStorageProvider.php';
if (!is_readable($s3) || !str_contains((string) file_get_contents($s3), 'implements StorageProviderInterface')) {
    $fail[] = 'S3CompatibleObjectStorageProvider must implement StorageProviderInterface';
}

$cfg = $root . '/config/storage.php';
if (!is_readable($cfg) || !str_contains((string) file_get_contents($cfg), 'STORAGE_S3_ENDPOINT')) {
    $fail[] = 'storage.php should document STORAGE_S3_* env keys';
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL storage factory readonly 02:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_storage_driver_factory_truth_readonly_02\n";
