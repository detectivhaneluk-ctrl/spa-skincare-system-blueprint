<?php

declare(strict_types=1);

/**
 * WAVE-01 Production Runtime Foundation — proof script.
 *
 * Verifies that all WAVE-01 deliverables are present and structurally correct:
 *  W1-A  RedisConnectionProvider + SharedCacheInterface wired through provider
 *  W1-B  RedisSessionHandler exists and implements SessionHandlerInterface
 *  W1-C  DistributedLockInterface + RedisDistributedLock + MysqlDistributedLock exist
 *        WaitlistService uses DistributedLockInterface (no GET_LOCK / RELEASE_LOCK)
 *  W1-D  ProductionRuntimeGuard exists and declares assertRedisOrDie()
 *  W1-E  Ops runbook + .env.example REDIS_URL marked mandatory
 *
 * Run: php system/scripts/read-only/verify_wave01_production_runtime_foundation_01.php
 * Expected: all assertions PASS, exit code 0.
 * On failure: non-zero exit.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function wave01_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "  PASS  {$label}\n";
    } else {
        ++$fail;
        echo "  FAIL  {$label}\n";
    }
}

function wave01_file_contains(string $file, string $needle, string $label): void
{
    $content = file_exists($file) ? file_get_contents($file) : '';
    wave01_assert(str_contains((string) $content, $needle), $label);
}

echo "\n=== WAVE-01 PRODUCTION RUNTIME FOUNDATION PROOF ===\n\n";

// ─── W1-A: RedisConnectionProvider + SharedCacheInterface wired through provider ───

echo "W1-A: Redis connection provider + shared cache wiring\n";

$redisProviderFile = $repoRoot . '/system/core/Runtime/Redis/RedisConnectionProvider.php';
wave01_assert(file_exists($redisProviderFile), 'RedisConnectionProvider.php exists');
wave01_file_contains($redisProviderFile, 'final class RedisConnectionProvider', 'RedisConnectionProvider is a final class');
wave01_file_contains($redisProviderFile, 'public function isConnected(): bool', 'RedisConnectionProvider::isConnected() exists');
wave01_file_contains($redisProviderFile, 'public function backend(): string', 'RedisConnectionProvider::backend() exists');

$bootstrapFile = $repoRoot . '/system/bootstrap.php';
wave01_file_contains($bootstrapFile, 'RedisConnectionProvider::class', 'bootstrap.php registers RedisConnectionProvider singleton');
wave01_file_contains($bootstrapFile, 'DistributedLockInterface::class', 'bootstrap.php registers DistributedLockInterface singleton');
wave01_file_contains($bootstrapFile, '$provider->isConnected()', 'SharedCacheInterface uses provider->isConnected()');

// Confirm old inline Redis connect block is gone from SharedCacheInterface closure
wave01_assert(
    !str_contains((string) file_get_contents($bootstrapFile), 'if ($url !== \'\' && extension_loaded(\'redis\'))'),
    'bootstrap.php: old inline Redis-URL block removed from SharedCacheInterface closure'
);

echo "\n";

// ─── W1-B: RedisSessionHandler ───

echo "W1-B: Redis session handler\n";

$sessionHandlerFile = $repoRoot . '/system/core/Runtime/Redis/RedisSessionHandler.php';
wave01_assert(file_exists($sessionHandlerFile), 'RedisSessionHandler.php exists');
wave01_file_contains($sessionHandlerFile, 'implements SessionHandlerInterface', 'RedisSessionHandler implements SessionHandlerInterface');
wave01_file_contains($sessionHandlerFile, 'public static function registerIfAvailable', 'RedisSessionHandler::registerIfAvailable() static factory exists');
wave01_file_contains($sessionHandlerFile, 'session_set_save_handler', 'RedisSessionHandler calls session_set_save_handler()');
wave01_file_contains($sessionHandlerFile, ':sess:', 'RedisSessionHandler uses :sess: key namespace');
wave01_file_contains($bootstrapFile, 'RedisSessionHandler::registerIfAvailable', 'bootstrap.php calls RedisSessionHandler::registerIfAvailable()');

echo "\n";

// ─── W1-C: DistributedLockInterface + Redis/MySQL implementations + WaitlistService ───

echo "W1-C: DistributedLockInterface + implementations + WaitlistService\n";

$lockInterfaceFile = $repoRoot . '/system/core/contracts/DistributedLockInterface.php';
wave01_assert(file_exists($lockInterfaceFile), 'DistributedLockInterface.php exists');
wave01_file_contains($lockInterfaceFile, 'interface DistributedLockInterface', 'DistributedLockInterface is an interface');
wave01_file_contains($lockInterfaceFile, 'public function tryAcquire(string $key', 'DistributedLockInterface::tryAcquire() declared');
wave01_file_contains($lockInterfaceFile, 'public function release(string $key): void', 'DistributedLockInterface::release() declared');

$redisLockFile = $repoRoot . '/system/core/Runtime/Redis/RedisDistributedLock.php';
wave01_assert(file_exists($redisLockFile), 'RedisDistributedLock.php exists');
wave01_file_contains($redisLockFile, 'implements DistributedLockInterface', 'RedisDistributedLock implements DistributedLockInterface');
wave01_file_contains($redisLockFile, "['NX', 'PX'", 'RedisDistributedLock uses SET NX PX pattern');
wave01_file_contains($redisLockFile, 'redis->eval', 'RedisDistributedLock uses Lua atomic release');

$mysqlLockFile = $repoRoot . '/system/core/Runtime/Redis/MysqlDistributedLock.php';
wave01_assert(file_exists($mysqlLockFile), 'MysqlDistributedLock.php exists');
wave01_file_contains($mysqlLockFile, 'implements DistributedLockInterface', 'MysqlDistributedLock implements DistributedLockInterface');
wave01_file_contains($mysqlLockFile, 'GET_LOCK', 'MysqlDistributedLock uses GET_LOCK as fallback');

$waitlistFile = $repoRoot . '/system/modules/appointments/services/WaitlistService.php';
$waitlistContent = (string) file_get_contents($waitlistFile);

wave01_assert(str_contains($waitlistContent, 'DistributedLockInterface'), 'WaitlistService imports DistributedLockInterface');
wave01_assert(str_contains($waitlistContent, 'private DistributedLockInterface $distributedLock'), 'WaitlistService constructor has DistributedLockInterface property');
wave01_assert(!str_contains($waitlistContent, "GET_LOCK"), 'WaitlistService: GET_LOCK removed from business logic');
wave01_assert(!str_contains($waitlistContent, "RELEASE_LOCK"), 'WaitlistService: RELEASE_LOCK removed from business logic');
wave01_assert(str_contains($waitlistContent, 'distributedLock->tryAcquire'), 'WaitlistService uses distributedLock->tryAcquire()');
wave01_assert(str_contains($waitlistContent, 'distributedLock->release'), 'WaitlistService uses distributedLock->release()');

$appointmentsBootstrap = $repoRoot . '/system/modules/bootstrap/register_appointments_online_contracts.php';
wave01_file_contains($appointmentsBootstrap, 'DistributedLockInterface::class', 'WaitlistService DI injects DistributedLockInterface');

// Guardrail updated to include WaitlistService
$guardrailFile = $repoRoot . '/system/scripts/ci/guardrail_service_layer_db_ban.php';
wave01_file_contains($guardrailFile, 'WaitlistService.php', 'WaitlistService added to DB-ban protected services after GET_LOCK removal');

echo "\n";

// ─── W1-D: ProductionRuntimeGuard ───

echo "W1-D: ProductionRuntimeGuard\n";

$guardFile = $repoRoot . '/system/core/Runtime/Guard/ProductionRuntimeGuard.php';
wave01_assert(file_exists($guardFile), 'ProductionRuntimeGuard.php exists');
wave01_file_contains($guardFile, 'final class ProductionRuntimeGuard', 'ProductionRuntimeGuard is a final class');
wave01_file_contains($guardFile, 'public static function assertRedisOrDie', 'ProductionRuntimeGuard::assertRedisOrDie() exists');
wave01_file_contains($guardFile, "http_response_code(503)", 'ProductionRuntimeGuard returns 503 on failure');
wave01_file_contains($guardFile, 'exit(1)', 'ProductionRuntimeGuard calls exit(1) on failure');
wave01_file_contains($guardFile, "env !== 'production' && \$env !== 'prod'", 'ProductionRuntimeGuard is non-production no-op');
wave01_file_contains($bootstrapFile, 'ProductionRuntimeGuard::assertRedisOrDie', 'bootstrap.php calls ProductionRuntimeGuard::assertRedisOrDie()');

echo "\n";

// ─── W1-E: Ops runbook + .env.example ───

echo "W1-E: Ops runbook + .env.example Redis documentation\n";

$opsFile = $repoRoot . '/system/docs/WAVE-01-PRODUCTION-RUNTIME-FOUNDATION-OPS.md';
wave01_assert(file_exists($opsFile), 'WAVE-01-PRODUCTION-RUNTIME-FOUNDATION-OPS.md exists');
wave01_file_contains($opsFile, 'MANDATORY in production', 'Ops runbook states Redis is mandatory in production');
wave01_file_contains($opsFile, 'Cache key conventions', 'Ops runbook contains cache key conventions section');
wave01_file_contains($opsFile, 'Distributed lock', 'Ops runbook documents distributed lock WaitlistService migration');
wave01_file_contains($opsFile, 'Redis session handler', 'Ops runbook documents Redis session handler');
wave01_file_contains($opsFile, 'Rollback path', 'Ops runbook documents rollback path');

$envExample = $repoRoot . '/system/.env.example';
wave01_file_contains($envExample, 'MANDATORY in production', '.env.example marks REDIS_URL as mandatory in production');
wave01_file_contains($envExample, 'Distributed lock', '.env.example documents distributed lock use');

$scaleCharter = $repoRoot . '/system/docs/SCALE-WAVE-EXECUTION-CHARTER-01.md';
wave01_assert(file_exists($scaleCharter), 'SCALE-WAVE-EXECUTION-CHARTER-01.md exists');
wave01_file_contains($scaleCharter, 'WAVE-01 — PRODUCTION RUNTIME FOUNDATION', 'Scale charter contains WAVE-01 definition');
wave01_file_contains($scaleCharter, 'WAVE-02 — QUEUE THROUGHPUT HARDENING', 'Scale charter contains WAVE-02 definition');
wave01_file_contains($scaleCharter, 'WAVE-03 — SCALE READINESS', 'Scale charter contains WAVE-03 definition');
wave01_file_contains($scaleCharter, 'WAVE-04 — SAFE SCALE OPERATIONS', 'Scale charter contains WAVE-04 definition');

echo "\n";

// ─── Autoload integrity ───

echo "Autoload integrity\n";

wave01_file_contains(
    $repoRoot . '/composer.json',
    '"Core\\\\Runtime\\\\": "system/core/Runtime/"',
    'composer.json PSR-4 maps Core\\Runtime\\ to system/core/Runtime/ (covers Guard, Redis subdirs)'
);

$autoloadFile = $repoRoot . '/system/core/app/autoload.php';
$autoloadContent = (string) file_get_contents($autoloadFile);
wave01_assert(
    str_contains($autoloadContent, "'Core\\\\Runtime\\\\'") || str_contains($autoloadContent, "'Core\\\\Kernel\\\\'"),
    'autoload.php SPL map includes Core namespaces'
);

echo "\n";

// ─── Summary ───

$total = $pass + $fail;
echo "===========================================\n";
echo "WAVE-01 PROOF: {$pass}/{$total} assertions passed\n";
if ($fail > 0) {
    echo "RESULT: FAIL — {$fail} assertion(s) failed\n";
    exit(1);
}
echo "RESULT: PASS — WAVE-01 Production Runtime Foundation deliverables verified.\n";
exit(0);
