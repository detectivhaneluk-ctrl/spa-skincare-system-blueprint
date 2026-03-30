<?php

declare(strict_types=1);

/**
 * Read-only queue truth for media job backlog / stale blockers.
 *
 * Usage (from system/):
 *   php scripts/read-only/media_queue_health_truth.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;

$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;
$maxAttempts = max(1, (int) env('IMAGE_JOB_MAX_ATTEMPTS', 5));
$staleMinutes = max(1, (int) env('IMAGE_JOB_STALE_LOCK_MINUTES', 30));
$db = app(\Core\App\Database::class);

$latest = $db->fetchOne(
    "SELECT i.id AS image_id, i.media_asset_id, ma.status AS asset_status
     FROM marketing_gift_card_images i
     INNER JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.deleted_at IS NULL
     ORDER BY i.id DESC
     LIMIT 1"
);

$pendingTotal = (int) (($db->fetchOne("SELECT COUNT(*) AS c FROM media_jobs WHERE status='pending' AND job_type = ?", [$jobType]))['c'] ?? 0);
$processingTotal = (int) (($db->fetchOne("SELECT COUNT(*) AS c FROM media_jobs WHERE status='processing' AND job_type = ?", [$jobType]))['c'] ?? 0);
$staleProcessing = (int) (($db->fetchOne(
    "SELECT COUNT(*) AS c FROM media_jobs
     WHERE status='processing' AND job_type = ?
       AND locked_at IS NOT NULL
       AND locked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
    [$jobType, $staleMinutes]
))['c'] ?? 0);

$oldestPending = $db->fetchOne(
    "SELECT id, created_at, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_minutes
     FROM media_jobs
     WHERE status='pending' AND job_type = ?
     ORDER BY id ASC
     LIMIT 1",
    [$jobType]
);

echo "=== media_queue_health_truth ===\n";
echo "total_pending_jobs={$pendingTotal}\n";
echo "total_processing_jobs={$processingTotal}\n";
echo "stale_processing_jobs={$staleProcessing}\n";
if ($oldestPending !== null) {
    echo "oldest_pending_job_id=" . (int) ($oldestPending['id'] ?? 0) . "\n";
    echo "oldest_pending_job_age_minutes=" . (int) ($oldestPending['age_minutes'] ?? 0) . "\n";
} else {
    echo "oldest_pending_job_id=none\n";
    echo "oldest_pending_job_age_minutes=0\n";
}

if ($latest === null || (int) ($latest['media_asset_id'] ?? 0) <= 0) {
    echo "latest_marketing_image=none\n";
    echo "VERDICT: QUEUE-HEALTHY\n";
    exit(0);
}

$assetId = (int) $latest['media_asset_id'];
$job = $db->fetchOne(
    "SELECT id, status, attempts, error_message, locked_at
     FROM media_jobs
     WHERE media_asset_id = ? AND job_type = ?
     ORDER BY id DESC LIMIT 1",
    [$assetId, $jobType]
);
$jobId = $job !== null ? (int) ($job['id'] ?? 0) : 0;
echo 'latest_marketing_image=' . json_encode($latest, JSON_UNESCAPED_UNICODE) . "\n";
echo 'latest_marketing_job=' . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";

$ahead = [];
if ($jobId > 0) {
    $ahead = $db->fetchAll(
        "SELECT j.id, j.media_asset_id, j.status, j.attempts, j.locked_at, j.error_message, a.status AS asset_status
         FROM media_jobs j
         LEFT JOIN media_assets a ON a.id = j.media_asset_id
         WHERE j.job_type = ? AND j.id < ?
           AND j.status IN ('pending','processing','failed')
         ORDER BY j.id ASC
         LIMIT 200",
        [$jobType, $jobId]
    );
}

$blockedReason = null;
$aheadOut = [];
foreach ($ahead as $r) {
    $status = (string) ($r['status'] ?? '');
    $assetStatus = (string) ($r['asset_status'] ?? '');
    $attempts = (int) ($r['attempts'] ?? 0);
    $lockedAt = $r['locked_at'] ?? null;
    $health = 'healthy';
    if ($status === 'processing' && $lockedAt !== null && strtotime((string) $lockedAt) !== false
        && time() - (int) strtotime((string) $lockedAt) > ($staleMinutes * 60)) {
        $health = 'stale';
        $blockedReason = $blockedReason ?? ('stale processing job ahead id=' . (int) ($r['id'] ?? 0));
    } elseif ($status === 'pending' && ($assetStatus !== 'pending' || $attempts >= $maxAttempts)) {
        $health = 'stale';
        $blockedReason = $blockedReason ?? ('nonclaimable pending job ahead id=' . (int) ($r['id'] ?? 0));
    } elseif ($status === 'failed' && in_array($assetStatus, ['pending', 'processing'], true)) {
        $health = 'failed-but-not-closed';
        $blockedReason = $blockedReason ?? ('failed-but-not-closed job ahead id=' . (int) ($r['id'] ?? 0));
    }
    $aheadOut[] = [
        'id' => (int) ($r['id'] ?? 0),
        'media_asset_id' => (int) ($r['media_asset_id'] ?? 0),
        'status' => $status,
        'asset_status' => $assetStatus,
        'attempts' => $attempts,
        'locked_at' => $lockedAt,
        'health' => $health,
    ];
}
echo 'jobs_ahead_of_latest_marketing_image=' . json_encode($aheadOut, JSON_UNESCAPED_UNICODE) . "\n";

if ($blockedReason !== null) {
    echo 'VERDICT: QUEUE-BLOCKED: ' . $blockedReason . "\n";
    exit(2);
}

echo "VERDICT: QUEUE-HEALTHY\n";
exit(0);

