<?php

declare(strict_types=1);

$appDebugRaw = env('APP_DEBUG', false);
$appDebug = is_bool($appDebugRaw)
    ? $appDebugRaw
    : filter_var((string) $appDebugRaw, FILTER_VALIDATE_BOOLEAN);

return [
    'env' => env('APP_ENV', 'production'),
    'debug' => $appDebug,
    /**
     * When true, HTTP requests abort with 503 if disk migrations and {@code migrations} table are not fully aligned
     * (pending files, orphan stamps, or missing migrations table). Off by default for local dev; set via {@code MIGRATION_BASELINE_ENFORCE}.
     */
    'migration_baseline_enforce' => filter_var(env('MIGRATION_BASELINE_ENFORCE', false), FILTER_VALIDATE_BOOLEAN),
    /** Comma-separated IPs of reverse proxies that may set X-Forwarded-For / X-Real-IP. Empty = use REMOTE_ADDR only (default). */
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'url' => env('APP_URL', 'http://localhost'),
    // Fallback when `establishment.timezone` is empty or invalid (see ApplicationTimezone, BKM-005).
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'session_lifetime' => (int) (env('SESSION_LIFETIME', 120)),
    'csrf_token_name' => 'csrf_token',
    /**
     * Optional Redis for shared cache + sliding-window public abuse limits. Empty = DB limiter + in-process Noop shared cache only.
     * Requires PHP ext-redis. Example: redis://127.0.0.1:6379 or redis://:password@host:6379/0
     */
    'redis_url' => trim((string) env('REDIS_URL', '')),
    'redis_key_prefix' => trim((string) env('REDIS_KEY_PREFIX', 'spa')),
];
