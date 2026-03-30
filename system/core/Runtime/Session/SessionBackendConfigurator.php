<?php

declare(strict_types=1);

namespace Core\Runtime\Session;

use Core\App\Application;

/**
 * Applies {@code session.save_handler} / {@code session.save_path} before {@see session_start()}.
 *
 * FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01: environment-driven backend selection (files for local default,
 * Redis for shared multi-node production). Does not start the session.
 */
final class SessionBackendConfigurator
{
    /**
     * @param array<string, mixed> $cfg Merged {@code config('session')} array
     */
    public static function apply(array $cfg): void
    {
        $driver = strtolower(trim((string) ($cfg['driver'] ?? 'files')));
        $env = strtolower((string) Application::config('app.env', 'production'));
        $isProduction = ($env === 'production');

        if ($driver === 'redis') {
            if (!extension_loaded('redis')) {
                if ($isProduction) {
                    throw new \RuntimeException(
                        'SESSION_DRIVER=redis requires the PHP redis extension in production. '
                        . 'Install ext-redis or set SESSION_DRIVER=files.'
                    );
                }
                error_log('spa_session_redis_extension_missing_fallback_v1: SESSION_DRIVER=redis but ext-redis missing; using files (non-production).');
                $driver = 'files';
            } else {
                $url = trim((string) ($cfg['redis_url'] ?? ''));
                if ($url === '') {
                    if ($isProduction) {
                        throw new \RuntimeException(
                            'SESSION_DRIVER=redis requires SESSION_REDIS_URL or REDIS_URL to be set in production.'
                        );
                    }
                    error_log('spa_session_redis_url_missing_fallback_v1: no Redis URL; using files (non-production).');
                    $driver = 'files';
                } else {
                    $prefix = (string) ($cfg['redis_prefix'] ?? 'spa:sess:');
                    try {
                        $savePath = self::buildRedisSavePath($url, $prefix);
                        ini_set('session.save_handler', 'redis');
                        ini_set('session.save_path', $savePath);

                        return;
                    } catch (\InvalidArgumentException $e) {
                        if ($isProduction) {
                            throw new \RuntimeException(
                                'SESSION_DRIVER=redis: invalid Redis URL for session save_path: ' . $e->getMessage(),
                                0,
                                $e
                            );
                        }
                        error_log('spa_session_redis_url_invalid_fallback_v1: ' . $e->getMessage());
                        $driver = 'files';
                    }
                }
            }
        }

        if ($driver === 'files') {
            ini_set('session.save_handler', 'files');
            $dir = trim((string) ($cfg['files_path'] ?? ''));
            if ($dir === '' && defined('SYSTEM_PATH') && SYSTEM_PATH !== '') {
                $dir = rtrim(SYSTEM_PATH, '/') . '/storage/sessions';
            }
            if ($dir !== '') {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0770, true);
                }
                if (is_dir($dir) && is_writable($dir)) {
                    ini_set('session.save_path', $dir);
                }
            }
        }
    }

    /**
     * Build phpredis session save_path (tcp scheme + query params per ext-redis session handler).
     *
     * @throws \InvalidArgumentException
     */
    public static function buildRedisSavePath(string $url, string $prefix): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \InvalidArgumentException('Session Redis URL must include a host.');
        }
        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : 6379;
        $auth = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '';
        $db = 0;
        if (!empty($parts['path']) && $parts['path'] !== '/') {
            $db = (int) ltrim((string) $parts['path'], '/');
            if ($db < 0 || $db > 15) {
                $db = 0;
            }
        }

        $pairs = [
            'timeout' => '2',
            'prefix' => $prefix,
        ];
        if ($auth !== '') {
            $pairs['auth'] = $auth;
        }
        if ($db > 0) {
            $pairs['database'] = (string) $db;
        }
        $query = [];
        foreach ($pairs as $k => $v) {
            $query[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }

        return 'tcp://' . $host . ':' . $port . '?' . implode('&', $query);
    }

    /**
     * Snapshot for health/verifiers (non-secret: URL host/port only).
     *
     * @param array<string, mixed> $cfg
     * @return array{driver: string, save_handler: string, save_path_public: string, redis_extension_loaded: bool, redis_url_configured: bool}
     */
    public static function describeRuntime(array $cfg): array
    {
        $driver = strtolower(trim((string) ($cfg['driver'] ?? 'files')));
        $handler = (string) ini_get('session.save_handler');
        $path = (string) ini_get('session.save_path');
        $redisUrl = trim((string) ($cfg['redis_url'] ?? ''));

        return [
            'driver' => $driver,
            'save_handler' => $handler,
            'save_path_public' => self::maskSavePath($path),
            'redis_extension_loaded' => extension_loaded('redis'),
            'redis_url_configured' => $redisUrl !== '',
        ];
    }

    public static function maskSavePath(string $savePath): string
    {
        if ($savePath === '') {
            return '';
        }
        if (!str_starts_with($savePath, 'tcp://')) {
            return $savePath;
        }
        $parts = parse_url($savePath);
        if ($parts === false) {
            return 'tcp://(unparsed)';
        }
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? (string) $parts['port'] : '6379';

        return 'tcp://' . $host . ':' . $port . '?…';
    }
}
