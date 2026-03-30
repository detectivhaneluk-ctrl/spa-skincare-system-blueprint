<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;
use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$assetId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset-id=')) {
        $assetId = (int) substr($arg, strlen('--asset-id='));
    }
}

$db = app(\Core\App\Database::class);
$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;

if ($assetId === null || $assetId <= 0) {
    $latest = $db->fetchOne(
        "SELECT ma.id
         FROM marketing_gift_card_images gi
         INNER JOIN media_assets ma ON ma.id = gi.media_asset_id
         WHERE gi.deleted_at IS NULL AND ma.status IN ('pending','processing','failed')
         ORDER BY gi.id DESC
         LIMIT 1"
    );
    $assetId = $latest !== null ? (int) ($latest['id'] ?? 0) : 0;
}

if ($assetId <= 0) {
    echo "asset_id: none\n";
    echo "diagnosis: READY\n";
    exit(0);
}

$asset = $db->fetchOne('SELECT id, status, updated_at FROM media_assets WHERE id = ? LIMIT 1', [$assetId]);
$job = $db->fetchOne(
    'SELECT id, status, attempts, locked_at, updated_at FROM media_jobs WHERE media_asset_id = ? AND job_type = ? ORDER BY id DESC LIMIT 1',
    [$assetId, $jobType]
);
$jobId = $job !== null ? (int) ($job['id'] ?? 0) : 0;
$jobsAhead = 0;
if ($jobId > 0 && (string) ($job['status'] ?? '') === 'pending') {
    $older = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM media_jobs WHERE status = 'pending' AND job_type = ? AND id < ?",
        [$jobType, $jobId]
    );
    $jobsAhead = (int) ($older['c'] ?? 0);
}

$spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
$drain = null;
$drainPath = base_path('storage/logs/media_dev_worker_drain.json');
if (is_file($drainPath)) {
    $raw = @file_get_contents($drainPath);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $drain = $decoded;
        }
    }
}
$autoDrain = MediaUploadWorkerDevTrigger::readAutoDrainStateForAsset($assetId);
$runtimeLogPath = MediaUploadWorkerDevTrigger::runtimeLogPathForAsset($assetId);
$heartbeatAt = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_last_heartbeat_at'] ?? '') : '';
$heartbeatAge = null;
if ($heartbeatAt !== '') {
    $ts = strtotime($heartbeatAt);
    if ($ts !== false) {
        $heartbeatAge = max(0, time() - (int) $ts);
    }
}
$lastWorkerExitCode = is_array($autoDrain) && array_key_exists('auto_drain_last_worker_exit_code', $autoDrain)
    ? (int) $autoDrain['auto_drain_last_worker_exit_code']
    : null;

$assetStatus = (string) ($asset['status'] ?? 'missing');
$jobStatus = (string) ($job['status'] ?? 'none');
$autoState = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_state'] ?? '') : '';
$workerDetected = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();

$diagnosis = 'READY';
if ($assetStatus === 'failed' || $jobStatus === 'failed') {
    $diagnosis = 'TERMINAL_FAILED';
} elseif ($assetStatus === 'ready') {
    $diagnosis = 'READY';
} elseif ($lastWorkerExitCode !== null && $lastWorkerExitCode !== 0) {
    $diagnosis = 'WORKER_EXITED_NO_PROGRESS';
} elseif ($autoState === 'started' && $heartbeatAge !== null && $heartbeatAge > 60) {
    $diagnosis = 'STALLED_DRAIN';
} elseif ($jobsAhead > 0 && $workerDetected !== 'no') {
    $diagnosis = 'HEALTHY_BACKLOG';
} elseif (($jobStatus === 'pending' || $assetStatus === 'pending' || $assetStatus === 'processing') && $workerDetected === 'no') {
    $diagnosis = 'WORKER_NOT_RUNNING';
}

echo 'asset_id: ' . $assetId . "\n";
echo 'asset_state: ' . $assetStatus . "\n";
echo 'job_state: ' . $jobStatus . "\n";
echo 'jobs_ahead_count: ' . $jobsAhead . "\n";
echo 'spawn_diagnostics_summary: ' . json_encode([
    'ok' => $spawn['ok'] ?? null,
    'reason' => $spawn['reason'] ?? null,
    'detail' => $spawn['detail'] ?? null,
    'asset_id' => $spawn['asset_id'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";
echo 'drain_diagnostics_summary: ' . json_encode([
    'ok' => $drain['ok'] ?? null,
    'reason' => $drain['reason'] ?? null,
    'detail' => $drain['detail'] ?? null,
    'asset_id' => $drain['asset_id'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";
echo 'heartbeat_age_seconds: ' . ($heartbeatAge !== null ? (string) $heartbeatAge : 'null') . "\n";
echo 'last_worker_exit_code: ' . ($lastWorkerExitCode !== null ? (string) $lastWorkerExitCode : 'null') . "\n";
echo 'runtime_log_path: ' . $runtimeLogPath . "\n";
echo 'diagnosis: ' . $diagnosis . "\n";
