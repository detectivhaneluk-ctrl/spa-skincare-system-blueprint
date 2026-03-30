<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;

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
    echo "spawn_pid: null\n";
    echo "booted_at: null\n";
    echo "heartbeat_at: null\n";
    echo "stdout_log_path: null\n";
    echo "stderr_log_path: null\n";
    echo "diagnosis: TERMINAL_READY\n";
    exit(0);
}

$asset = $db->fetchOne(
    'SELECT id, status FROM media_assets WHERE id = ? LIMIT 1',
    [$assetId]
);
$job = $db->fetchOne(
    'SELECT id, status FROM media_jobs WHERE media_asset_id = ? AND job_type = ? ORDER BY id DESC LIMIT 1',
    [$assetId, $jobType]
);
$autoDrain = MediaUploadWorkerDevTrigger::readAutoDrainStateForAsset($assetId);

$assetStatus = (string) ($asset['status'] ?? '');
$jobStatus = (string) ($job['status'] ?? '');
$state = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_state'] ?? '') : '';
$spawnPid = is_array($autoDrain) && isset($autoDrain['auto_drain_spawn_pid']) ? (int) $autoDrain['auto_drain_spawn_pid'] : null;
$bootedAt = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_booted_at'] ?? '') : '';
$heartbeatAt = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_last_heartbeat_at'] ?? '') : '';
$stdoutPath = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_stdout_log_path'] ?? '') : '';
$stderrPath = is_array($autoDrain) ? (string) ($autoDrain['auto_drain_stderr_log_path'] ?? '') : '';
if ($stdoutPath === '') {
    $stdoutPath = MediaUploadWorkerDevTrigger::runtimeStdoutLogPathForAsset($assetId);
}
if ($stderrPath === '') {
    $stderrPath = MediaUploadWorkerDevTrigger::runtimeStderrLogPathForAsset($assetId);
}

$diagnosis = 'DRAIN_BOOTED';
if ($assetStatus === 'ready') {
    $diagnosis = 'TERMINAL_READY';
} elseif ($assetStatus === 'failed' || $jobStatus === 'failed') {
    $diagnosis = 'TERMINAL_FAILED';
} elseif ($spawnPid === null || $spawnPid <= 0 || $state === 'failed_before_start') {
    $diagnosis = 'SPAWN_FAILED';
} elseif ($state === 'spawned_but_boot_missing' || $bootedAt === '') {
    $diagnosis = 'SPAWN_ACCEPTED_BOOT_MISSING';
}

echo 'asset_id: ' . $assetId . "\n";
echo 'spawn_pid: ' . ($spawnPid !== null && $spawnPid > 0 ? (string) $spawnPid : 'null') . "\n";
echo 'booted_at: ' . ($bootedAt !== '' ? $bootedAt : 'null') . "\n";
echo 'heartbeat_at: ' . ($heartbeatAt !== '' ? $heartbeatAt : 'null') . "\n";
echo 'stdout_log_path: ' . $stdoutPath . "\n";
echo 'stderr_log_path: ' . $stderrPath . "\n";
echo 'diagnosis: ' . $diagnosis . "\n";
