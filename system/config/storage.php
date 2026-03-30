<?php

declare(strict_types=1);

/**
 * Storage backend selection (FOUNDATION-STORAGE-ABSTRACTION-01 + DISTRIBUTED-RUNTIME-02).
 *
 * `local` — host filesystem under `system/` (default, honest single-node).
 * `s3_compatible` — SigV4 REST against S3/R2/MinIO (`STORAGE_S3_*`); requires ext-curl.
 *
 * `STORAGE_LOCAL_SYSTEM_PATH` / `storage.local.system_root` must equal the PHP app
 * `system/` directory when using local. The image worker uses the same physical layout via `MEDIA_SYSTEM_ROOT`.
 */
$driver = strtolower(trim((string) env('STORAGE_DRIVER', 'local')));

return [
    'driver' => $driver,
    'local' => [
        /** Absolute path to application `system/` tree, or null to use SYSTEM_PATH at runtime. */
        'system_root' => env('STORAGE_LOCAL_SYSTEM_PATH'),
    ],
    's3' => [
        'endpoint' => trim((string) env('STORAGE_S3_ENDPOINT', '')),
        'bucket' => trim((string) env('STORAGE_S3_BUCKET', '')),
        'access_key' => trim((string) env('STORAGE_S3_ACCESS_KEY', '')),
        'secret_key' => trim((string) env('STORAGE_S3_SECRET_KEY', '')),
        'region' => trim((string) env('STORAGE_S3_REGION', 'us-east-1')),
        /** Path-style URLs (`https://endpoint/bucket/key`) suit R2/MinIO; virtual-hosted when false. */
        'path_style' => filter_var(env('STORAGE_S3_PATH_STYLE', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
