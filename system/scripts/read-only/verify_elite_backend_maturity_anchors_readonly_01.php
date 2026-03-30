<?php

declare(strict_types=1);

/**
 * Read-only repo anchors for FINAL-ELITE-BACKEND-MATURITY-WAVE-01 (logging schema, notification cache, metrics, canonical entry).
 *
 * From repository root:
 *   php system/scripts/read-only/verify_elite_backend_maturity_anchors_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "FAIL: could not resolve repository root.\n");
    exit(1);
}

$failures = [];

$logger = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'StructuredLogger.php';
$lg = is_file($logger) ? (string) file_get_contents($logger) : '';
if (!str_contains($lg, 'spa_structured_v3')) {
    $failures[] = 'StructuredLogger must use log_schema spa_structured_v3';
}
if (!str_contains($lg, 'event_code')) {
    $failures[] = 'StructuredLogger must emit event_code';
}
if (!str_contains($lg, 'spa_structured_encode_fallback_v1')) {
    $failures[] = 'StructuredLogger must define encode-fallback schema spa_structured_encode_fallback_v1';
}
if (!str_contains($lg, 'correlation_id')) {
    $failures[] = 'StructuredLogger base context must include correlation_id';
}

$helpers = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'helpers.php';
$hp = is_file($helpers) ? (string) file_get_contents($helpers) : '';
if (!str_contains($hp, 'spa_structured_slog_fallback_v1')) {
    $failures[] = 'helpers slog() fallback must use spa_structured_slog_fallback_v1 JSON line';
}

$settings = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'SettingsService.php';
$st = is_file($settings) ? (string) file_get_contents($settings) : '';
if (!str_contains($st, 'notificationSettingsSharedCacheKey')) {
    $failures[] = 'SettingsService must define packed shared cache for notification settings';
}

$pubIdx = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
$pi = is_file($pubIdx) ? (string) file_get_contents($pubIdx) : '';
if (!str_contains($pi, 'DEPLOYMENT-DOCROOT-CANONICAL-PUBLIC-ENTRY-MARKER-01')) {
    $failures[] = 'system/public/index.php must retain DEPLOYMENT-DOCROOT-CANONICAL-PUBLIC-ENTRY-MARKER-01';
}

foreach ($failures as $f) {
    fwrite(STDERR, 'FAIL: ' . $f . "\n");
}

if ($failures !== []) {
    exit(1);
}

echo "CHECK: Elite backend maturity code anchors present (logging v3, slog JSON fallback, notification cache key, canonical public entry).\n";
exit(0);
