<?php

declare(strict_types=1);

/**
 * Prints shared cache backend mode and in-process metrics snapshot (after one cold get probe).
 * Requires full bootstrap + modules (DB must be reachable if your modules bootstrap connects eagerly).
 *
 * From repository root:
 *   php system/scripts/read-only/report_shared_cache_operational_readonly_01.php
 *
 * Exit: 0 on success, 1 if bootstrap fails.
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$systemPath = $repoRoot . DIRECTORY_SEPARATOR . 'system';

try {
    require $systemPath . '/bootstrap.php';
    require $systemPath . '/modules/bootstrap.php';
} catch (Throwable $e) {
    fwrite(STDERR, 'Bootstrap failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$container = \Core\App\Application::container();
/** @var \Core\Runtime\Cache\SharedCacheMetrics $metrics */
$metrics = $container->get(\Core\Runtime\Cache\SharedCacheMetrics::class);
/** @var \Core\Contracts\SharedCacheInterface $cache */
$cache = $container->get(\Core\Contracts\SharedCacheInterface::class);
$cache->get('__operational_readiness_probe_v1__');

$snap = $metrics->snapshot();
$redisUrlConfigured = trim((string) \Core\App\Application::config('app.redis_url', '')) !== '';

echo "FINAL-ELITE-BACKEND-MATURITY-WAVE-01 — shared cache operational snapshot\n";
echo 'REDIS_URL configured (non-empty): ' . ($redisUrlConfigured ? 'yes' : 'no') . "\n";
echo 'ext-redis loaded: ' . (extension_loaded('redis') ? 'yes' : 'no') . "\n";
echo "Metrics (since PHP process start): " . json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "\nDocumented packed hotspots (SettingsService + shared keys; TTL typically 45–120s, invalidated on writes/patches):\n";
echo "  - settings_v1:branch_org:*\n";
echo "  - settings_v1:public_commerce_packed:*\n";
echo "  - settings_v1:payment_settings_packed:*\n";
echo "  - settings_v1:online_booking_packed:*\n";
echo "  - settings_v1:security_settings_packed:*\n";
echo "  - settings_v1:intake_settings_packed:*\n";
echo "  - settings_v1:hardware_settings_packed:*\n";
echo "  - settings_v1:notification_settings_packed:*\n";
echo "\nDegraded mode: `shared_cache_degraded` true when Redis is not effective; hit/miss apply only when `redis_effective`.\n";

exit(0);
