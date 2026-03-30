<?php

declare(strict_types=1);

/**
 * Read-only end-to-end truth proof for latest marketing image.
 *
 * Usage (from system/):
 *   php scripts/read-only/prove_marketing_image_end_to_end.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;
use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$db = app(\Core\App\Database::class);
$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;
$maxAttempts = max(1, (int) env('IMAGE_JOB_MAX_ATTEMPTS', 5));

function readJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

echo "=== prove_marketing_image_end_to_end ===\n";

$latest = $db->fetchOne(
    "SELECT i.* FROM marketing_gift_card_images i
     WHERE i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
     ORDER BY i.id DESC LIMIT 1"
);
if ($latest === null) {
    echo "latest_marketing_image_row=null\n";
    echo "VERDICT: FAILED:no_marketing_image_row\n";
    exit(1);
}

$assetId = (int) ($latest['media_asset_id'] ?? 0);
$asset = $db->fetchOne("SELECT * FROM media_assets WHERE id = ? LIMIT 1", [$assetId]);
$jobs = $db->fetchAll(
    "SELECT * FROM media_jobs WHERE media_asset_id = ? AND job_type = ? ORDER BY id ASC",
    [$assetId, $jobType]
);
$latestJob = $jobs !== [] ? $jobs[count($jobs) - 1] : null;
$latestJobId = $latestJob !== null ? (int) ($latestJob['id'] ?? 0) : 0;

$ahead = [];
$claimableAhead = 0;
$staleAhead = 0;
if ($latestJobId > 0) {
    $ahead = $db->fetchAll(
        "SELECT j.id, j.media_asset_id, j.status, j.attempts, j.locked_at, j.error_message, a.status AS asset_status
         FROM media_jobs j
         LEFT JOIN media_assets a ON a.id = j.media_asset_id
         WHERE j.job_type = ? AND j.id < ?
           AND j.status IN ('pending','processing','failed')
         ORDER BY j.id ASC
         LIMIT 200",
        [$jobType, $latestJobId]
    );
    foreach ($ahead as $a) {
        $jst = (string) ($a['status'] ?? '');
        $ast = (string) ($a['asset_status'] ?? '');
        $att = (int) ($a['attempts'] ?? 0);
        if ($jst === 'pending' && $ast === 'pending' && $att < $maxAttempts) {
            $claimableAhead++;
        }
        if ($jst === 'processing' && ($a['locked_at'] ?? null) !== null) {
            $staleMin = max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));
            $lockedTs = strtotime((string) $a['locked_at']);
            if ($lockedTs !== false && (time() - (int) $lockedTs) > ($staleMin * 60)) {
                $staleAhead++;
            }
        }
    }
}

$spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
$drain = readJsonFile(base_path('storage/logs/media_dev_worker_drain.json'));
$autoDrain = MediaUploadWorkerDevTrigger::readAutoDrainStateForAsset($assetId);
$worker = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();
$claimed = false;
if ($latestJob !== null) {
    $claimed = ((int) ($latestJob['attempts'] ?? 0) > 0) || ((string) ($latestJob['status'] ?? '') === 'completed') || (($latestJob['locked_at'] ?? null) !== null);
}

echo 'latest_marketing_image_row=' . json_encode($latest, JSON_UNESCAPED_UNICODE) . "\n";
echo 'linked_media_asset_row=' . json_encode($asset, JSON_UNESCAPED_UNICODE) . "\n";
echo 'linked_media_job_rows=' . json_encode($jobs, JSON_UNESCAPED_UNICODE) . "\n";
echo 'spawn_last=' . json_encode($spawn, JSON_UNESCAPED_UNICODE) . "\n";
echo 'drain_last=' . json_encode($drain, JSON_UNESCAPED_UNICODE) . "\n";
echo 'auto_drain_state=' . json_encode($autoDrain, JSON_UNESCAPED_UNICODE) . "\n";
echo 'jobs_ahead_snapshot=' . json_encode($ahead, JSON_UNESCAPED_UNICODE) . "\n";
echo "claimable_pending_jobs_ahead={$claimableAhead}\n";
echo "stale_processing_rows_ahead={$staleAhead}\n";
echo 'worker_claimed_target=' . ($claimed ? 'yes' : 'no') . "\n";
echo 'worker_process_detected=' . $worker . "\n";
echo 'final_asset_status=' . (string) ($asset['status'] ?? 'missing') . "\n";
echo 'final_job_status=' . (string) ($latestJob['status'] ?? 'none') . "\n";

$assetStatus = (string) ($asset['status'] ?? '');
$jobStatus = (string) ($latestJob['status'] ?? '');

if ($assetStatus === 'ready') {
    echo "VERDICT: READY\n";
    exit(0);
}
if ($assetStatus === 'failed') {
    $reason = (string) ($latestJob['error_message'] ?? 'asset_failed');
    echo 'VERDICT: FAILED: ' . $reason . "\n";
    exit(2);
}
if (is_array($spawn) && array_key_exists('ok', $spawn) && $spawn['ok'] === false && (int) ($spawn['asset_id'] ?? 0) === $assetId) {
    echo 'VERDICT: SPAWN-FAILED: ' . (string) ($spawn['reason'] ?? 'spawn_failed') . "\n";
    exit(3);
}
if (is_array($drain) && (int) ($drain['asset_id'] ?? 0) === $assetId && array_key_exists('ok', $drain) && $drain['ok'] === false) {
    echo 'VERDICT: DRAIN-FAILED: ' . (string) ($drain['reason'] ?? 'drain_failed') . "\n";
    exit(4);
}
if ($jobStatus === 'processing' || (($latestJob['locked_at'] ?? null) !== null && (string) ($latestJob['locked_at'] ?? '') !== '')) {
    echo "VERDICT: PROCESSING\n";
    exit(0);
}
if ($claimableAhead > 0) {
    echo "VERDICT: HEALTHY-BACKLOG\n";
    exit(0);
}
if ($worker === 'no' && (string) env('APP_ENV', 'production') === 'local') {
    echo "VERDICT: WORKER-NOT-RUNNING\n";
    exit(5);
}
if (!$claimed) {
    echo "VERDICT: JOB-NOT-CLAIMED\n";
    exit(6);
}
echo "VERDICT: PROCESSING\n";
exit(0);

