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
 *  - NoopSharedCache is NOT a valid production runtime path for HTTP requests.
 *  - Any backend other than 'redis' in production causes a hard stop for HTTP requests.
 *  - CLI invocations (workers, crons, probes, migrations) are never terminated by this guard.
 *  - In non-production environments (local, staging, test), the guard is a no-op.
 *
 * Must be called AFTER SharedCacheInterface has been resolved from the container
 * (which populates SharedCacheMetrics::backend()).
 *
 * SAPI behaviour contract (HOTFIX-01):
 *  HTTP/web SAPI:
 *   - Drains all output buffers before sending response (prevents stale HTML prefix)
 *   - Suppresses display_errors to prevent PHP HTML error output after the JSON body
 *   - Uses error_log() for server-side logging — never writes to STDERR in web SAPI
 *   - Sends exactly one valid JSON object, then exits
 *  CLI SAPI:
 *   - Returns immediately (no-op) — CLI tools are never terminated by this guard
 */
final class ProductionRuntimeGuard
{
    private function __construct()
    {
    }

    /**
     * Assert that Redis is available in production, or terminate with HTTP 503.
     *
     * This guard is HTTP-only: CLI invocations (workers, crons, probes, migrations)
     * are never terminated here. CLI scripts that need Redis must handle
     * connectivity failures on their own.
     *
     * @param Config             $config  Application config (reads app.env)
     * @param SharedCacheMetrics $metrics Cache metrics with resolved backend name
     */
    public static function assertRedisOrDie(Config $config, SharedCacheMetrics $metrics): void
    {
        // Never kill CLI processes — workers, crons, and release-law probes must not be terminated.
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            return;
        }

        $env = strtolower(trim((string) $config->get('app.env', 'production')));
        if ($env !== 'production' && $env !== 'prod') {
            return;
        }

        if ($metrics->redisEffective()) {
            return;
        }

        $backend = $metrics->backend();
        $reason  = match ($backend) {
            'redis_connect_failed'    => 'REDIS_URL is configured but the connection attempt failed. Check Redis host/port/auth and network reachability.',
            'redis_extension_missing' => 'REDIS_URL is configured but the PHP ext-redis extension is not loaded. Install php-redis and verify it appears in php -m.',
            'noop'                    => 'REDIS_URL is not configured. Redis is mandatory in production. Set REDIS_URL in your environment (e.g. redis://127.0.0.1:6379).',
            default                   => 'Redis backend is in an unknown state: ' . $backend . '. Redis is mandatory in production.',
        };

        // ── Suppress PHP from appending HTML error/warning output after our JSON body. ──
        // This must happen before any output so that display_errors cannot inject HTML.
        @ini_set('display_errors', '0');

        // ── Drain all open output buffers so no previously buffered content precedes our JSON. ──
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $payload = (string) json_encode([
            'error'   => 'Service unavailable: Redis is required in production.',
            'detail'  => $reason,
            'backend' => $backend,
        ], JSON_UNESCAPED_SLASHES);

        // ── Send HTTP 503 with a single, clean JSON body. ──
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: 60');
            // Prevent downstream proxies from caching the error response.
            header('Cache-Control: no-store');
        }

        echo $payload . "\n";

        // ── Log to the PHP error log — safe in ALL SAPIs (never touches the response body). ──
        // error_log() writes to the configured PHP error log regardless of SAPI.
        // Avoid using STDERR in web SAPI: in CGI/FastCGI configurations the stderr
        // stream can be wired to the HTTP response output, which would corrupt the JSON body.
        error_log('[ProductionRuntimeGuard] FATAL: ' . $reason);

        // ── Terminate immediately. No further output must follow this point. ──
        exit(1);
    }
}
