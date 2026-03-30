<?php

declare(strict_types=1);

/**
 * Read-only: validates session config keys and (when driver=redis) ext-redis + TCP reachability of configured URL.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_session_runtime_configuration_readonly_01.php
 *
 * Exit 0 = checks passed; 1 = misconfiguration for current env rules.
 */

$systemPath = realpath(dirname(__DIR__, 2));
if ($systemPath === false) {
    fwrite(STDERR, "Could not resolve system path.\n");
    exit(1);
}

require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$driver = strtolower(trim((string) \Core\App\Application::config('session.driver', 'files')));
$redisUrl = trim((string) \Core\App\Application::config('session.redis_url', ''));
$env = strtolower((string) \Core\App\Application::config('app.env', 'production'));
$isProduction = ($env === 'production');
$ext = extension_loaded('redis');

echo "session.driver={$driver} app.env={$env}\n";
echo 'session.redis_url_configured=' . ($redisUrl !== '' ? 'yes' : 'no') . "\n";
echo 'ext-redis=' . ($ext ? 'yes' : 'no') . "\n";

$errors = [];

if ($driver === 'redis') {
    if (!$ext) {
        $errors[] = 'SESSION_DRIVER=redis requires PHP ext-redis.';
    }
    if ($redisUrl === '') {
        $errors[] = 'SESSION_DRIVER=redis requires SESSION_REDIS_URL or REDIS_URL.';
    }
    if ($errors === [] && $redisUrl !== '') {
        try {
            $redis = \Core\Runtime\Redis\RedisFactory::connect($redisUrl);
            $redis->ping();
        } catch (\Throwable $e) {
            $errors[] = 'Redis session URL not reachable: ' . $e->getMessage();
        }
    }
}

if ($isProduction && $driver === 'redis' && $errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, $e . "\n");
    }
    exit(1);
}

if ($errors !== []) {
    echo "warnings (non-production: app may fall back or fail at session_start):\n";
    foreach ($errors as $e) {
        echo ' - ' . $e . "\n";
    }
}

echo "verify_session_runtime_configuration_readonly_01: OK\n";
exit(0);
