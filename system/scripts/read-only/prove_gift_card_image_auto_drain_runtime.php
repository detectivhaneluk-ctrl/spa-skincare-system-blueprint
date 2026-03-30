<?php

declare(strict_types=1);

/**
 * Read-only runtime proof for latest marketing gift-card upload auto-drain outcome.
 *
 * Usage (from system/):
 *   php scripts/read-only/prove_gift_card_image_auto_drain_runtime.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;
use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$db = app(\Core\App\Database::class);

echo "=== prove_gift_card_image_auto_drain_runtime ===\n";

$img = $db->fetchOne(
    "SELECT i.id AS image_id, i.media_asset_id, i.created_at, i.title
     FROM marketing_gift_card_images i
     WHERE i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
     ORDER BY i.id DESC
     LIMIT 1"
);
if ($img === null) {
    echo "latest_marketing_image=none\n";
    echo "VERDICT: BLOCKED: no media-backed marketing image upload row found\n";
    exit(1);
}

$assetId = (int) ($img['media_asset_id'] ?? 0);
$asset = $db->fetchOne('SELECT id, status, stored_basename, updated_at FROM media_assets WHERE id = ? LIMIT 1', [$assetId]);
$job = $db->fetchOne(
    "SELECT id, status, attempts, error_message, locked_at, created_at, updated_at
     FROM media_jobs
     WHERE media_asset_id = ? AND job_type = ?
     ORDER BY id DESC
     LIMIT 1",
    [$assetId, MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
);
$ahead = [];
if ($job !== null && (int) ($job['id'] ?? 0) > 0) {
    $ahead = $db->fetchAll(
        "SELECT j.id, j.status, j.attempts, j.locked_at, a.status AS asset_status
         FROM media_jobs j
         LEFT JOIN media_assets a ON a.id = j.media_asset_id
         WHERE j.job_type = ? AND j.id < ?
           AND j.status IN ('pending','processing','failed')
         ORDER BY j.id ASC
         LIMIT 200",
        [MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO, (int) ($job['id'] ?? 0)]
    );
}
$spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
$drainPath = base_path('storage/logs/media_dev_worker_drain.json');
$drain = null;
if (is_file($drainPath)) {
    $raw = @file_get_contents($drainPath);
    $tmp = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($tmp)) {
        $drain = $tmp;
    }
}

$workerDetected = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();
$workerStarted = false;
if ($workerDetected === 'yes') {
    $workerStarted = true;
}
if (is_array($job) && ((string) ($job['status'] ?? '') === 'completed' || ((string) ($job['locked_at'] ?? '')) !== '' || (int) ($job['attempts'] ?? 0) > 0)) {
    $workerStarted = true;
}

echo 'latest_marketing_image=' . json_encode($img, JSON_UNESCAPED_UNICODE) . "\n";
echo 'media_asset_row=' . json_encode($asset, JSON_UNESCAPED_UNICODE) . "\n";
echo 'media_job_row=' . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";
echo 'ahead_jobs=' . json_encode($ahead, JSON_UNESCAPED_UNICODE) . "\n";
echo 'spawn_diag=' . json_encode($spawn, JSON_UNESCAPED_UNICODE) . "\n";
echo 'drain_diag=' . json_encode($drain, JSON_UNESCAPED_UNICODE) . "\n";
echo "worker_process_detected={$workerDetected}\n";
echo 'worker_started=' . ($workerStarted ? 'yes' : 'no') . "\n";

$blocker = null;
if ($asset === null) {
    $blocker = 'media asset row missing for latest marketing upload';
} elseif ($job === null) {
    $blocker = 'media job row missing for latest marketing upload';
} elseif (!is_array($spawn) || (int) ($spawn['asset_id'] ?? 0) !== $assetId) {
    $blocker = 'auto-drain launch diagnostics missing for this asset';
} elseif (array_key_exists('ok', $spawn) && $spawn['ok'] !== true) {
    $blocker = 'auto-drain launch failed: ' . (string) ($spawn['reason'] ?? 'spawn_failed') . ' ' . (string) ($spawn['detail'] ?? '');
} elseif (!$workerStarted) {
    $blocker = 'worker did not start for this upload';
} else {
    foreach ($ahead as $a) {
        $st = (string) ($a['status'] ?? '');
        $ast = (string) ($a['asset_status'] ?? '');
        $att = (int) ($a['attempts'] ?? 0);
        if ($st === 'processing' && ($a['locked_at'] ?? null) !== null) {
            $lockedTs = strtotime((string) $a['locked_at']);
            if ($lockedTs !== false && (time() - (int) $lockedTs) > (max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30)) * 60)) {
                $blocker = 'queue blocked by stale older processing job id=' . (int) ($a['id'] ?? 0);
                break;
            }
        }
        if ($st === 'pending' && ($att >= max(1, (int) env('IMAGE_JOB_MAX_ATTEMPTS', 5)) || $ast !== 'pending')) {
            $blocker = 'queue blocked by nonclaimable older pending job id=' . (int) ($a['id'] ?? 0);
            break;
        }
        if ($st === 'failed' && in_array($ast, ['pending', 'processing'], true)) {
            $blocker = 'queue blocked by failed-but-not-closed older job id=' . (int) ($a['id'] ?? 0);
            break;
        }
    }
    if ($blocker !== null) {
        echo 'repair_hint=php scripts/dev-only/repair_media_queue_stale_jobs.php --apply' . "\n";
    }
    if ($blocker === null) {
    $assetStatus = (string) ($asset['status'] ?? '');
    if ($assetStatus === 'ready') {
        $blocker = null;
    } elseif ($assetStatus === 'failed') {
        $blocker = 'job reached failed: ' . (string) ($job['error_message'] ?? 'no reason');
    } else {
        $blocker = 'asset still non-terminal status=' . $assetStatus;
    }
    }
}

if ($blocker === null) {
    echo "VERDICT: SUCCESS\n";
    exit(0);
}

echo 'VERDICT: BLOCKED: ' . $blocker . "\n";
exit(2);

