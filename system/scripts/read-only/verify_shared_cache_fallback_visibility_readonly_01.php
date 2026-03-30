<?php

declare(strict_types=1);

/**
 * Read-only code-anchor check: shared cache must be wrapped with metrics and explicit degraded backend labels in bootstrap.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_shared_cache_fallback_visibility_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "FAIL: could not resolve repository root.\n");
    exit(1);
}

$bootstrap = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "FAIL: missing system/bootstrap.php\n");
    exit(1);
}

$src = (string) file_get_contents($bootstrap);
$failures = [];
if (!str_contains($src, 'InstrumentedSharedCache')) {
    $failures[] = 'bootstrap.php must register InstrumentedSharedCache around SharedCacheInterface';
}
if (!str_contains($src, 'SharedCacheMetrics')) {
    $failures[] = 'bootstrap.php must register SharedCacheMetrics';
}
if (!str_contains($src, 'redis_connect_failed')) {
    $failures[] = 'bootstrap.php must label redis_connect_failed backend when Redis connection fails';
}
if (!str_contains($src, 'redis_extension_missing')) {
    $failures[] = 'bootstrap.php must label redis_extension_missing when REDIS_URL set without ext-redis';
}

$metricsFile = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR . 'SharedCacheMetrics.php';
if (!is_file($metricsFile)) {
    $failures[] = 'Missing system/core/Runtime/Cache/SharedCacheMetrics.php';
} else {
    $ms = (string) file_get_contents($metricsFile);
    if (!str_contains($ms, 'redis_effective') || !str_contains($ms, 'shared_cache_degraded')) {
        $failures[] = 'SharedCacheMetrics must expose redis_effective and shared_cache_degraded in snapshot';
    }
}

foreach ($failures as $f) {
    fwrite(STDERR, 'FAIL: ' . $f . "\n");
}

if ($failures !== []) {
    exit(1);
}

echo "CHECK: Shared cache fallback/metrics wiring anchors present in system/bootstrap.php.\n";
exit(0);
