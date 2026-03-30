<?php

declare(strict_types=1);

/**
 * Read-only canonical blocker truth for latest marketing upload.
 *
 * Usage (from system/):
 *   php scripts/read-only/marketing_latest_upload_blocker_truth.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;
use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$db = app(\Core\App\Database::class);
$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;
$maxAttempts = max(1, (int) env('IMAGE_JOB_MAX_ATTEMPTS', 5));
$staleMinutes = max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));

$img = $db->fetchOne(
    "SELECT i.id AS image_id, i.media_asset_id
     FROM marketing_gift_card_images i
     WHERE i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
     ORDER BY i.id DESC LIMIT 1"
);

echo "=== marketing_latest_upload_blocker_truth ===\n";
if ($img === null) {
    echo "latest_marketing_image_id=none\n";
    echo "VERDICT: FAILED:no_marketing_media_image\n";
    exit(1);
}

$assetId = (int) ($img['media_asset_id'] ?? 0);
$asset = $db->fetchOne("SELECT id, status FROM media_assets WHERE id = ? LIMIT 1", [$assetId]);
$job = $db->fetchOne(
    "SELECT id, status, attempts, locked_at, error_message
     FROM media_jobs
     WHERE media_asset_id = ? AND job_type = ?
     ORDER BY id DESC LIMIT 1",
    [$assetId, $jobType]
);
$jobId = $job !== null ? (int) ($job['id'] ?? 0) : 0;

$ahead = [];
if ($jobId > 0) {
    $ahead = $db->fetchAll(
        "SELECT j.id, j.media_asset_id, j.status, j.attempts, j.locked_at, a.status AS asset_status
         FROM media_jobs j
         LEFT JOIN media_assets a ON a.id = j.media_asset_id
         WHERE j.job_type = ? AND j.id < ?
           AND j.status IN ('pending','processing','failed')
         ORDER BY j.id ASC
         LIMIT 200",
        [$jobType, $jobId]
    );
}

$claimableAhead = 0;
$staleProcessingAhead = 0;
foreach ($ahead as $a) {
    $jst = (string) ($a['status'] ?? '');
    $ast = (string) ($a['asset_status'] ?? '');
    $att = (int) ($a['attempts'] ?? 0);
    if ($jst === 'pending' && $ast === 'pending' && $att < $maxAttempts) {
        $claimableAhead++;
    }
    if ($jst === 'processing' && ($a['locked_at'] ?? null) !== null) {
        $lockedTs = strtotime((string) $a['locked_at']);
        if ($lockedTs !== false && (time() - (int) $lockedTs) > ($staleMinutes * 60)) {
            $staleProcessingAhead++;
        }
    }
}

$spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
$drain = null;
$drainPath = base_path('storage/logs/media_dev_worker_drain.json');
if (is_file($drainPath)) {
    $raw = @file_get_contents($drainPath);
    $parsed = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($parsed)) {
        $drain = $parsed;
    }
}
$workerDetected = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();

echo "latest_marketing_image_id=" . (int) ($img['image_id'] ?? 0) . "\n";
echo "linked_media_asset_id={$assetId}\n";
echo 'linked_media_asset_status=' . (string) ($asset['status'] ?? 'missing') . "\n";
echo 'linked_latest_media_job_id=' . ($jobId > 0 ? (string) $jobId : 'none') . "\n";
echo 'linked_latest_media_job_status=' . (string) ($job['status'] ?? 'none') . "\n";
echo "claimable_pending_jobs_ahead={$claimableAhead}\n";
echo "stale_processing_rows_ahead={$staleProcessingAhead}\n";
echo 'drain_last=' . json_encode([
    'ok' => $drain['ok'] ?? null,
    'reason' => $drain['reason'] ?? null,
    'detail' => $drain['detail'] ?? null,
    'asset_id' => $drain['asset_id'] ?? null,
    'job_id' => $drain['job_id'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";
echo 'spawn_last=' . json_encode([
    'ok' => $spawn['ok'] ?? null,
    'reason' => $spawn['reason'] ?? null,
    'detail' => $spawn['detail'] ?? null,
    'asset_id' => $spawn['asset_id'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";
echo "worker_process_detected={$workerDetected}\n";

$assetStatus = (string) ($asset['status'] ?? '');
$jobStatus = (string) ($job['status'] ?? '');
$drainTargetsAsset = is_array($drain) && (int) ($drain['asset_id'] ?? 0) === $assetId;

if ($assetStatus === 'ready') {
    echo "VERDICT: READY\n";
    exit(0);
}
if ($assetStatus === 'failed') {
    $reason = (string) ($job['error_message'] ?? 'asset_failed');
    echo 'VERDICT: FAILED:' . $reason . "\n";
    exit(2);
}
if ($drainTargetsAsset && isset($drain['ok']) && $drain['ok'] === false) {
    $r = (string) ($drain['reason'] ?? 'drain_failed');
    if ($r === 'drain_exhausted') {
        echo "VERDICT: DRAIN-FAILED\n";
        exit(3);
    }
    echo "VERDICT: DRAIN-FAILED\n";
    exit(3);
}
if ($jobStatus === 'processing' || (($job['locked_at'] ?? null) !== null && (string) ($job['locked_at'] ?? '') !== '')) {
    echo "VERDICT: PROCESSING\n";
    exit(0);
}
if ($claimableAhead > 0) {
    echo "VERDICT: HEALTHY-BACKLOG\n";
    exit(0);
}
if ((string) env('APP_ENV', 'production') === 'local' && $workerDetected === 'no') {
    echo "VERDICT: WORKER-NOT-RUNNING\n";
    exit(4);
}

echo "VERDICT: PROCESSING\n";
exit(0);

