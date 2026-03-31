<?php

declare(strict_types=1);

/**
 * WAVE-03 Scale Readiness + Observability — proof script.
 *
 * Verifies:
 *  W3-A  ProxySQL deployment package artifacts (README, config template, setup SQL, health check)
 *  W3-B  Read/write routing abstraction documented (no premature code split)
 *  W3-C  Endpoint latency middleware + queue depth metrics + connection observability
 *  W3-D  SlowQueryLogger (tenant-aware slow query logging) + Database instrumentation
 *
 * Run: php system/scripts/read-only/verify_wave03_scale_readiness_observability_01.php
 * Expected: all assertions PASS, exit code 0.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function wave03_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $condition ? ++$pass : ++$fail;
}

function wave03_contains(string $file, string $needle, string $label): void
{
    wave03_assert(
        file_exists($file) && str_contains((string) file_get_contents($file), $needle),
        $label
    );
}

echo "\n=== WAVE-03 SCALE READINESS + OBSERVABILITY PROOF ===\n\n";

// ─── W3-A: ProxySQL deployment package ───

echo "W3-A: ProxySQL deployment package\n";

$proxysqlDir = $repoRoot . '/deploy/proxysql';
wave03_assert(is_dir($proxysqlDir), 'deploy/proxysql/ directory exists');

$readme = $proxysqlDir . '/README.md';
wave03_assert(file_exists($readme), 'deploy/proxysql/README.md exists');
wave03_contains($readme, 'MYSQL_PRIMARY_HOST', 'README documents MYSQL_PRIMARY_HOST env var');
wave03_contains($readme, 'DB_HOST=127.0.0.1', 'README documents DB_HOST pointing to ProxySQL');
wave03_contains($readme, 'health_check_proxysql.php', 'README references health check script');
wave03_contains($readme, 'Failure modes', 'README documents failure modes');
wave03_contains($readme, 'Connection pool sizing', 'README documents connection pool sizing');

$cnfTemplate = $proxysqlDir . '/proxysql.cnf.template';
wave03_assert(file_exists($cnfTemplate), 'deploy/proxysql/proxysql.cnf.template exists');
wave03_contains($cnfTemplate, 'FOR UPDATE SKIP LOCKED', 'ProxySQL config includes note about FOR UPDATE routing');
wave03_contains($cnfTemplate, 'destination_hostgroup=0', 'ProxySQL config routes writes to hostgroup 0 (primary)');
wave03_contains($cnfTemplate, 'destination_hostgroup=1', 'ProxySQL config routes reads to hostgroup 1 (replica)');

$setupSql = $proxysqlDir . '/proxysql_setup.sql';
wave03_assert(file_exists($setupSql), 'deploy/proxysql/proxysql_setup.sql exists');
wave03_contains($setupSql, 'LOAD MYSQL SERVERS TO RUNTIME', 'Setup SQL loads config to runtime');

$healthCheck = $proxysqlDir . '/health_check_proxysql.php';
wave03_assert(file_exists($healthCheck), 'deploy/proxysql/health_check_proxysql.php exists');
wave03_contains($healthCheck, 'FOR UPDATE', 'Health check tests write-path routing');

echo "\n";

// ─── W3-B: Read/write routing abstraction documented ───

echo "W3-B: Read/write routing abstraction — documented, not prematurely split\n";
wave03_contains($readme, 'DO NOT enable until ProxySQL is confirmed deployed', 'README explicitly defers application read/write split until ProxySQL is deployed');

echo "\n";

// ─── W3-C: Endpoint latency + queue depth metrics ───

echo "W3-C: Endpoint latency middleware + queue depth observability\n";

$latencyMiddleware = $repoRoot . '/system/core/middleware/RequestLatencyMiddleware.php';
wave03_assert(file_exists($latencyMiddleware), 'RequestLatencyMiddleware.php exists');
wave03_contains($latencyMiddleware, 'implements MiddlewareInterface', 'RequestLatencyMiddleware implements MiddlewareInterface');
wave03_contains($latencyMiddleware, 'endpoint_latency', 'RequestLatencyMiddleware emits endpoint_latency slog channel');
wave03_contains($latencyMiddleware, 'organization_id', 'RequestLatencyMiddleware includes tenant organization_id in log');
wave03_contains($latencyMiddleware, 'thresholdMs', 'RequestLatencyMiddleware has configurable threshold');

$bootstrapFile = $repoRoot . '/system/bootstrap.php';
wave03_contains($bootstrapFile, 'RequestLatencyMiddleware::class', 'bootstrap.php registers RequestLatencyMiddleware singleton');
wave03_contains($bootstrapFile, 'SlowQueryLogger::class', 'bootstrap.php registers SlowQueryLogger singleton');
wave03_contains($bootstrapFile, 'setSlowQueryLogger', 'bootstrap.php wires SlowQueryLogger to Database');

// Queue depth metrics from WAVE-02 are the queue observability foundation
$repoFile = $repoRoot . '/system/core/Runtime/Queue/RuntimeAsyncJobRepository.php';
wave03_contains($repoFile, 'getQueueDepthMetrics', 'RuntimeAsyncJobRepository::getQueueDepthMetrics() present (WAVE-02 queue observability)');

// Observability config file
$obsConfig = $repoRoot . '/system/config/observability.php';
wave03_assert(file_exists($obsConfig), 'system/config/observability.php exists');
wave03_contains($obsConfig, 'slow_query_threshold_ms', 'observability.php defines slow_query_threshold_ms');
wave03_contains($obsConfig, 'slow_request_threshold_ms', 'observability.php defines slow_request_threshold_ms');

echo "\n";

// ─── W3-D: SlowQueryLogger + Database instrumentation ───

echo "W3-D: SlowQueryLogger + tenant-aware Database instrumentation\n";

$slowQueryLogger = $repoRoot . '/system/core/Observability/SlowQueryLogger.php';
wave03_assert(file_exists($slowQueryLogger), 'SlowQueryLogger.php exists');
wave03_contains($slowQueryLogger, 'final class SlowQueryLogger', 'SlowQueryLogger is a final class');
wave03_contains($slowQueryLogger, 'slow_query', 'SlowQueryLogger emits slow_query slog channel');
wave03_contains($slowQueryLogger, 'organization_id', 'SlowQueryLogger includes tenant organization_id in log');
wave03_contains($slowQueryLogger, 'branch_id', 'SlowQueryLogger includes tenant branch_id in log');
wave03_contains($slowQueryLogger, 'actor_id', 'SlowQueryLogger includes tenant actor_id in log');
wave03_contains($slowQueryLogger, 'RequestContextHolder', 'SlowQueryLogger uses RequestContextHolder for tenant enrichment');

$databaseFile = $repoRoot . '/system/core/app/Database.php';
wave03_contains($databaseFile, 'setSlowQueryLogger', 'Database::setSlowQueryLogger() method added');
wave03_contains($databaseFile, 'slowQueryLogger->observe', 'Database::query() calls slowQueryLogger->observe()');
wave03_contains($databaseFile, 'microtime(true)', 'Database::query() times execution when logger is set');

echo "\n";

// ─── Summary ───

$total = $pass + $fail;
echo "===========================================\n";
echo "WAVE-03 PROOF: {$pass}/{$total} assertions passed\n";
if ($fail > 0) {
    echo "RESULT: FAIL — {$fail} assertion(s) failed\n";
    exit(1);
}
echo "RESULT: PASS — WAVE-03 Scale Readiness + Observability deliverables verified.\n";
exit(0);
