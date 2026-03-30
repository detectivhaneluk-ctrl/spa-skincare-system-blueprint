<?php

declare(strict_types=1);

$sessionRedisUrl = trim((string) env('SESSION_REDIS_URL', ''));
if ($sessionRedisUrl === '') {
    $sessionRedisUrl = trim((string) env('REDIS_URL', ''));
}

return [
    /**
     * Session persistence backend: {@code files} (default, local/dev) or {@code redis} (shared store for multi-node).
     * Production + {@code redis} requires PHP ext-redis and {@code SESSION_REDIS_URL} or {@code REDIS_URL}.
     */
    'driver' => strtolower(trim((string) env('SESSION_DRIVER', 'files'))),
    /** Effective Redis URL for sessions; {@code SESSION_REDIS_URL} overrides {@code REDIS_URL}. */
    'redis_url' => $sessionRedisUrl,
    /** Key prefix for phpredis session handler (isolated from cache keys). */
    'redis_prefix' => trim((string) env('SESSION_REDIS_PREFIX', 'spa:sess:')),
    /** Override session file directory; empty = {@code system/storage/sessions} when using files driver. */
    'files_path' => trim((string) env('SESSION_FILES_PATH', '')),
    'early_release' => [
        /** Master switch for {@see \Core\Middleware\SessionEarlyReleaseMiddleware} (per-route opt-in still required). */
        'enabled' => filter_var(env('SESSION_EARLY_RELEASE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
    'cookie_name' => env('SESSION_COOKIE', 'spa_session'),
    'lifetime' => (int) (env('SESSION_LIFETIME', 120)),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN', ''),
    'secure' => filter_var(
        env('SESSION_SECURE', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOLEAN
    ),
    'httponly' => true,
    'samesite' => 'Lax',
];
