<?php

declare(strict_types=1);

/**
 * MINIMUM-OPS-RESILIENCE-GATE-01 — structural proof verifier.
 *
 * Verifies:
 *   OPS-1  BackendHealthLayer::ASYNC_QUEUE constant exists
 *   OPS-2  BackendHealthReasonCodes::ASYNC_QUEUE_TABLE_MISSING exists
 *   OPS-3  BackendHealthReasonCodes::ASYNC_QUEUE_DEAD_JOBS exists
 *   OPS-4  BackendHealthReasonCodes::ASYNC_QUEUE_STALE_JOBS exists
 *   OPS-5  BackendHealthCollector includes probeAsyncQueue() in collectAll()
 *   OPS-6  BackendHealthCollector::probeAsyncQueue() is defined
 *   OPS-7  probeAsyncQueue handles missing table (ASYNC_QUEUE_TABLE_MISSING)
 *   OPS-8  probeAsyncQueue probes dead-letter jobs (status='dead')
 *   OPS-9  probeAsyncQueue probes stale processing rows (900 second threshold)
 *   OPS-10 OPS-WORKER-SUPERVISION-01.md exists
 *   OPS-11 OPS-WORKER-SUPERVISION-01.md contains queue topology section
 *   OPS-12 OPS-WORKER-SUPERVISION-01.md contains systemd supervision template
 *   OPS-13 OPS-WORKER-SUPERVISION-01.md contains Supervisor alternative
 *   OPS-14 OPS-WORKER-SUPERVISION-01.md contains liveness check via queue_health_metrics_cli
 *   OPS-15 OPS-WORKER-SUPERVISION-01.md contains dead-letter policy section
 *   OPS-16 OPS-WORKER-SUPERVISION-01.md contains stale-reclaim cron schedule
 *   OPS-17 OPS-WORKER-SUPERVISION-01.md documents exit code 2 (warning) for queue health
 *   OPS-18 OPS-WORKER-SUPERVISION-01.md documents exit code 3 (critical) for dead jobs
 *   OPS-19 OPS-BACKUP-RESTORE-01.md exists
 *   OPS-20 OPS-BACKUP-RESTORE-01.md contains MySQL dump procedure (--single-transaction)
 *   OPS-21 OPS-BACKUP-RESTORE-01.md contains restore procedure
 *   OPS-22 OPS-BACKUP-RESTORE-01.md contains honesty boundary (no automated backup)
 *   OPS-23 OPS-BACKUP-RESTORE-01.md contains verification steps after restore
 *   OPS-24 OPS-BACKUP-RESTORE-01.md documents Redis as ephemeral (optional backup)
 *   OPS-25 worker_runtime_async_jobs_cli_02.php exists (worker entrypoint)
 *   OPS-26 worker_runtime_async_jobs_cli_02.php handles --queue= and --once flags
 *   OPS-27 run_queue_stale_reclaim_cron.php exists (stale reclaim cron)
 *   OPS-28 queue_health_metrics_cli.php exists (operator liveness check)
 *   OPS-29 queue_health_metrics_cli.php supports --json output
 *   OPS-30 queue_health_metrics_cli.php exits with severity 3 for dead jobs (critical)
 *   OPS-31 AsyncQueueWorkerLoop marks failed jobs dead after attempts exhausted
 *   OPS-32 RuntimeAsyncJobRepository::STATUS_DEAD constant preserved
 *   OPS-33 RuntimeAsyncJobRepository::markFailedRetryOrDead() handles exhausted attempts
 *   OPS-34 RuntimeAsyncJobRepository::reclaimStaleJobs() preserved
 *   OPS-35 AsyncQueueStatusReader::getDeadJobs() preserved
 *   OPS-36 BackendHealthCollector now has 6 probes in collectAll() (including async_queue)
 *   OPS-37 report_backend_health_critical_readonly_01.php exists (consolidated health CLI)
 *   OPS-38 FOUNDATION-OBSERVABILITY-AND-ALERTING-01-AUDIT.md references async job tracking
 *   OPS-39 FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md references heartbeat registry
 *   OPS-40 OPS-WORKER-SUPERVISION-01.md references consolidated backend health report
 *
 * Run: php system/scripts/read-only/verify_minimum_ops_resilience_gate_01.php
 * Expected: all assertions PASS, exit code 0.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function ops_assert(bool $condition, string $label): void
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

function ops_contains(string $file, string $needle, string $label): void
{
    $content = file_exists($file) ? (string) file_get_contents($file) : '';
    ops_assert(str_contains($content, $needle), $label);
}

function ops_not_contains(string $file, string $needle, string $label): void
{
    $content = file_exists($file) ? (string) file_get_contents($file) : '';
    ops_assert(!str_contains($content, $needle), $label);
}

echo "\n=== MINIMUM-OPS-RESILIENCE-GATE-01 VERIFIER ===\n\n";

// --- File paths ---
$layerFile       = $repoRoot . '/system/core/Observability/BackendHealthLayer.php';
$reasonFile      = $repoRoot . '/system/core/Observability/BackendHealthReasonCodes.php';
$collectorFile   = $repoRoot . '/system/core/Observability/BackendHealthCollector.php';
$workerSupDoc    = $repoRoot . '/system/docs/OPS-WORKER-SUPERVISION-01.md';
$backupDoc       = $repoRoot . '/system/docs/OPS-BACKUP-RESTORE-01.md';
$workerScript    = $repoRoot . '/system/scripts/worker_runtime_async_jobs_cli_02.php';
$reclaimCron     = $repoRoot . '/system/scripts/run_queue_stale_reclaim_cron.php';
$healthCli       = $repoRoot . '/system/scripts/read-only/queue_health_metrics_cli.php';
$workerLoop      = $repoRoot . '/system/core/Runtime/Queue/AsyncQueueWorkerLoop.php';
$jobRepo         = $repoRoot . '/system/core/Runtime/Queue/RuntimeAsyncJobRepository.php';
$statusReader    = $repoRoot . '/system/core/Runtime/Queue/AsyncQueueStatusReader.php';
$healthReport    = $repoRoot . '/system/scripts/read-only/report_backend_health_critical_readonly_01.php';
$obsAudit        = $repoRoot . '/system/docs/FOUNDATION-OBSERVABILITY-AND-ALERTING-01-AUDIT.md';
$jobsOps         = $repoRoot . '/system/docs/FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md';

// ─── OPS-1..4: New Observability constants ───────────────────────────────────

echo "OPS-1..4: BackendHealthLayer and BackendHealthReasonCodes new constants\n";
ops_contains($layerFile, "ASYNC_QUEUE = 'async_queue'", 'OPS-1: BackendHealthLayer::ASYNC_QUEUE constant exists');
ops_contains($reasonFile, "ASYNC_QUEUE_TABLE_MISSING = 'ASYNC_QUEUE_TABLE_MISSING'", 'OPS-2: BackendHealthReasonCodes::ASYNC_QUEUE_TABLE_MISSING exists');
ops_contains($reasonFile, "ASYNC_QUEUE_DEAD_JOBS = 'ASYNC_QUEUE_DEAD_JOBS'", 'OPS-3: BackendHealthReasonCodes::ASYNC_QUEUE_DEAD_JOBS exists');
ops_contains($reasonFile, "ASYNC_QUEUE_STALE_JOBS = 'ASYNC_QUEUE_STALE_JOBS'", 'OPS-4: BackendHealthReasonCodes::ASYNC_QUEUE_STALE_JOBS exists');
echo "\n";

// ─── OPS-5..9: BackendHealthCollector probeAsyncQueue ────────────────────────

echo "OPS-5..9: BackendHealthCollector::probeAsyncQueue() wiring\n";
ops_contains($collectorFile, '$this->probeAsyncQueue()', 'OPS-5: probeAsyncQueue() called in collectAll()');
ops_contains($collectorFile, 'private function probeAsyncQueue()', 'OPS-6: probeAsyncQueue() method defined');
ops_contains($collectorFile, 'ASYNC_QUEUE_TABLE_MISSING', 'OPS-7: probeAsyncQueue handles missing table');
ops_contains($collectorFile, "status = 'dead'", 'OPS-8: probeAsyncQueue queries dead-letter jobs');
ops_contains($collectorFile, 'INTERVAL 900 SECOND', 'OPS-9: probeAsyncQueue uses 900s stale threshold');
echo "\n";

// ─── OPS-10..18: OPS-WORKER-SUPERVISION-01.md ────────────────────────────────

echo "OPS-10..18: OPS-WORKER-SUPERVISION-01.md existence and required content\n";
ops_assert(file_exists($workerSupDoc), 'OPS-10: OPS-WORKER-SUPERVISION-01.md exists');
ops_contains($workerSupDoc, 'worker_runtime_async_jobs_cli_02.php', 'OPS-11: Contains queue topology / worker script reference');
ops_contains($workerSupDoc, '[Unit]', 'OPS-12: Contains systemd unit file template');
ops_contains($workerSupDoc, '[program:spa-worker', 'OPS-13: Contains Supervisor config alternative');
ops_contains($workerSupDoc, 'queue_health_metrics_cli.php', 'OPS-14: Contains liveness check via queue_health_metrics_cli');
ops_contains($workerSupDoc, 'dead', 'OPS-15: Contains dead-letter policy section');
ops_contains($workerSupDoc, 'run_queue_stale_reclaim_cron.php', 'OPS-16: Contains stale-reclaim cron reference');
ops_contains($workerSupDoc, 'stale `processing` rows detected', 'OPS-17: Documents exit code 2 warning (stale processing) for queue health');
ops_contains($workerSupDoc, 'Critical — `dead` letter jobs exist', 'OPS-18: Documents exit code 3 critical (dead jobs) for queue health');
echo "\n";

// ─── OPS-19..24: OPS-BACKUP-RESTORE-01.md ────────────────────────────────────

echo "OPS-19..24: OPS-BACKUP-RESTORE-01.md existence and required content\n";
ops_assert(file_exists($backupDoc), 'OPS-19: OPS-BACKUP-RESTORE-01.md exists');
ops_contains($backupDoc, '--single-transaction', 'OPS-20: Contains MySQL dump with --single-transaction');
ops_contains($backupDoc, 'Restore Procedure', 'OPS-21: Contains restore procedure section');
ops_contains($backupDoc, 'no automated backup', 'OPS-22: Contains honesty boundary about no automated backup');
ops_contains($backupDoc, 'run_mandatory_tenant_isolation_proof_release_gate_01.php', 'OPS-23: Verification steps after restore reference release law');
ops_contains($backupDoc, 'EPHEMERAL', 'OPS-24: Documents Redis as ephemeral (optional backup)');
echo "\n";

// ─── OPS-25..30: Worker entrypoint and health CLI ────────────────────────────

echo "OPS-25..30: Worker script and health CLI presence + behavior\n";
ops_assert(file_exists($workerScript), 'OPS-25: worker_runtime_async_jobs_cli_02.php exists');
ops_contains($workerScript, '--queue=', 'OPS-26a: Worker handles --queue= flag');
ops_contains($workerScript, '--once', 'OPS-26b: Worker handles --once flag');
ops_assert(file_exists($reclaimCron), 'OPS-27: run_queue_stale_reclaim_cron.php exists');
ops_assert(file_exists($healthCli), 'OPS-28: queue_health_metrics_cli.php exists');
ops_contains($healthCli, '--json', 'OPS-29: queue_health_metrics_cli.php supports --json');
ops_contains($healthCli, "'critical'", 'OPS-30: queue_health_metrics_cli.php reports critical for dead jobs');
echo "\n";

// ─── OPS-31..35: Queue state machine preservation ────────────────────────────

echo "OPS-31..35: Queue state machine (dead-letter, stale-reclaim) preserved\n";
ops_contains($workerLoop, 'markFailedRetryOrDead', 'OPS-31: AsyncQueueWorkerLoop marks jobs dead after attempts exhausted');
ops_contains($jobRepo, "STATUS_DEAD = 'dead'", 'OPS-32: RuntimeAsyncJobRepository::STATUS_DEAD constant preserved');
$jobRepoContent = (string) file_get_contents($jobRepo);
$deadBlock = strpos($jobRepoContent, 'if ($attempts >= $max)');
ops_assert($deadBlock !== false && str_contains(substr($jobRepoContent, $deadBlock, 300), 'STATUS_DEAD'), 'OPS-33: markFailedRetryOrDead transitions to dead when attempts exhausted');
ops_contains($jobRepo, 'public function reclaimStaleJobs(', 'OPS-34: RuntimeAsyncJobRepository::reclaimStaleJobs() preserved');
ops_contains($statusReader, 'public function getDeadJobs(', 'OPS-35: AsyncQueueStatusReader::getDeadJobs() preserved');
echo "\n";

// ─── OPS-36..40: Health report + docs cross-reference ───────────────────────

echo "OPS-36..40: Consolidated health report + cross-reference docs\n";
$collectorContent = (string) file_get_contents($collectorFile);
$probeCount = substr_count($collectorContent, '$this->probe');
ops_assert($probeCount >= 6, "OPS-36: BackendHealthCollector has >= 6 probes in collectAll() (found {$probeCount})");
ops_assert(file_exists($healthReport), 'OPS-37: report_backend_health_critical_readonly_01.php exists');
ops_contains($obsAudit, 'runtime_execution_registry', 'OPS-38: FOUNDATION-OBSERVABILITY-AND-ALERTING-01-AUDIT.md covers async job tracking');
ops_contains($jobsOps, 'heartbeat', 'OPS-39: FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md documents heartbeat registry');
ops_contains($workerSupDoc, 'report_backend_health_critical_readonly_01.php', 'OPS-40: OPS-WORKER-SUPERVISION-01.md references consolidated backend health report');
echo "\n";

// ─── Summary ─────────────────────────────────────────────────────────────────

$total = $pass + $fail;
echo "===========================================\n";
echo "MINIMUM-OPS-RESILIENCE-GATE-01: {$pass}/{$total} assertions passed\n";
if ($fail > 0) {
    echo "RESULT: FAIL — {$fail} assertion(s) failed\n";
    exit(1);
}
echo "RESULT: PASS — Minimum operational resilience gate verified.\n";
exit(0);
