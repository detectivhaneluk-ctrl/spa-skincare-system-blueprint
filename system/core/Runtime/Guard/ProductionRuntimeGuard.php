<?php

declare(strict_types=1);

namespace Core\Runtime\Guard;

use Core\App\Config;
use Core\Runtime\Cache\SharedCacheMetrics;

/**
 * Production startup guard — WAVE-01.
 *
 * Enforces that Redis is available and connected when APP_ENV is 'production' or 'prod'.
 * If Redis is unavailable in production, responds with HTTP 503 and terminates the process.
 *
 * Fail-closed contract:
 *  - NoopSharedCache is NOT a valid production runtime path.
 *  - Any backend other than 'redis' in production causes a hard stop.
 *  - In non-production environments (local, staging, test), the guard is a no-op.
 *
 * Must be called AFTER SharedCacheInterface has been resolved from the container
 * (which populates SharedCacheMetrics::backend()).
 */
final class ProductionRuntimeGuard
{
    private function __construct()
    {
    }

    /**
     * Assert that Redis is available in production, or terminate with 503.
     *
     * @param Config              $config  Application config (reads app.env)
     * @param SharedCacheMetrics  $metrics Cache metrics with resolved backend name
     */
    public static function assertRedisOrDie(Config $config, SharedCacheMetrics $metrics): void
    {
        $env = strtolower(trim((string) $config->get('app.env', 'production')));
        if ($env !== 'production' && $env !== 'prod') {
            return;
        }

        if ($metrics->redisEffective()) {
            return;
        }

        $backend = $metrics->backend();
        $reason = match ($backend) {
            'redis_connect_failed' => 'REDIS_URL is configured but the connection attempt failed. Check Redis host/port/auth and network reachability.',
            'redis_extension_missing' => 'REDIS_URL is configured but the PHP ext-redis extension is not loaded. Install php-redis and verify it appears in php -m.',
            'noop' => 'REDIS_URL is not configured. Redis is mandatory in production. Set REDIS_URL in your environment (e.g. redis://127.0.0.1:6379).',
            default => 'Redis backend is in an unknown state: ' . $backend . '. Redis is mandatory in production.',
        };

        $payload = json_encode([
            'error' => 'Service unavailable: Redis is required in production.',
            'detail' => $reason,
            'backend' => $backend,
        ], JSON_UNESCAPED_SLASHES);

        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 60');
        }

        echo $payload . "\n";

        // Write to STDERR so server logs capture the startup failure.
        fwrite(STDERR, '[ProductionRuntimeGuard] FATAL: ' . $reason . "\n");

        exit(1);
    }
}
