<?php

declare(strict_types=1);

/**
 * Read-only: media_jobs / media_assets queue truth + worker heartbeat from runtime_execution_registry.
 *
 * From repository root:
 *   php system/scripts/read-only/report_image_pipeline_runtime_health_readonly_01.php
 *
 * Signals (stdout, honest — not a green badge):
 *   - backlog_no_recent_worker_heartbeat: pending jobs exist but worker:last_heartbeat_at is missing or older than warn window
 *   - stale_processing_jobs: rows in processing longer than IMAGE_JOB_STALE_LOCK_MINUTES (app env, default 30)
 *
 * Exit: 0 always when tables exist (signals are informational). Exit 1 if required tables missing.
 * For alert-style exit codes (degraded/failed), use `system/scripts/read-only/report_backend_health_critical_readonly_01.php` (includes this queue/heartbeat logic).
 */

$systemPath = realpath(dirname(__DIR__, 2));
if ($systemPath === false) {
    fwrite(STDERR, "Could not resolve system path.\n");
    exit(1);
}

require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\Runtime\Jobs\RuntimeExecutionKeys;

$db = app(\Core\App\Database::class)->connection();
$config = app(\Core\App\Config::class);

$has = static function (string $table) use ($db): bool {
    $s = $db->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $s->execute([$table]);

    return (bool) $s->fetchColumn();
};

if (!$has('media_jobs') || !$has('media_assets')) {
    fwrite(STDERR, "CRITICAL: media_jobs or media_assets missing.\n");
    exit(1);
}

$jobType = 'process_photo_variants_v1';
$staleLockMin = max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));

$cnt = static function (string $status) use ($db, $jobType): int {
    $s = $db->prepare('SELECT COUNT(*) FROM media_jobs WHERE job_type = ? AND status = ?');
    $s->execute([$jobType, $status]);

    return (int) $s->fetchColumn();
};

$pending = $cnt('pending');
$processing = $cnt('processing');
$failed = $cnt('failed');
$completed = $cnt('completed');

$st = $db->prepare(
    "SELECT COUNT(*) FROM media_jobs j
     WHERE j.job_type = ?
       AND j.status = 'processing'
       AND j.locked_at IS NOT NULL
       AND j.locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
);
$st->execute([$jobType, $staleLockMin]);
$staleProcessing = (int) $st->fetchColumn();

$assetsPending = (int) $db->query("SELECT COUNT(*) FROM media_assets WHERE status = 'pending'")->fetchColumn();

echo 'media_jobs.pending=' . $pending . PHP_EOL;
echo 'media_jobs.processing=' . $processing . PHP_EOL;
echo 'media_jobs.stale_processing_older_than_' . $staleLockMin . 'm=' . $staleProcessing . PHP_EOL;
echo 'media_jobs.failed=' . $failed . PHP_EOL;
echo 'media_jobs.completed=' . $completed . PHP_EOL;
echo 'media_assets.pending=' . $assetsPending . PHP_EOL;

$warnMin = max(1, (int) $config->get('runtime_jobs.image_worker_backlog_heartbeat_warn_minutes', 20));
$workerKey = RuntimeExecutionKeys::WORKER_IMAGE_PIPELINE;
$heartbeatRow = null;
if ($has('runtime_execution_registry')) {
    $wh = $db->prepare(
        'SELECT last_heartbeat_at, active_heartbeat_at, active_started_at, last_finished_at, last_failure_at, last_error_summary
         FROM runtime_execution_registry WHERE execution_key = ? LIMIT 1'
    );
    $wh->execute([$workerKey]);
    $heartbeatRow = $wh->fetch(\PDO::FETCH_ASSOC) ?: null;
}

$hb = $heartbeatRow['last_heartbeat_at'] ?? null;
echo 'worker.execution_key=' . $workerKey . PHP_EOL;
echo 'worker.last_heartbeat_at=' . ($hb !== null && $hb !== '' ? (string) $hb : 'null') . PHP_EOL;

$signals = [];
if ($staleProcessing > 0) {
    $signals[] = 'stale_processing_jobs';
}

if ($pending > 0) {
    if ($hb === null || $hb === '') {
        $signals[] = 'backlog_no_recent_worker_heartbeat';
    } else {
        $ts = strtotime((string) $hb);
        if ($ts === false || (time() - $ts) > ($warnMin * 60)) {
            $signals[] = 'backlog_no_recent_worker_heartbeat';
        }
    }
}

if ($signals === []) {
    echo 'health_signal=ok' . PHP_EOL;
} else {
    echo 'health_signal=' . implode(',', $signals) . PHP_EOL;
    foreach ($signals as $s) {
        echo 'health_detail=' . $s . PHP_EOL;
    }
}

echo "report_image_pipeline_runtime_health_readonly_01: OK\n";
exit(0);
