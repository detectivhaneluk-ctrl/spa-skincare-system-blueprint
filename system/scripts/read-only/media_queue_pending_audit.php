<?php

declare(strict_types=1);

/**
 * Read-only: strict FIFO queue audit for gift-card (and global) media_jobs.
 *
 * Usage (from system/):
 *   php scripts/read-only/media_queue_pending_audit.php
 *   php scripts/read-only/media_queue_pending_audit.php --asset-id=42
 *
 * Optional: set MEDIA_QUEUE_AUDIT_PROBE_PROCESSES=0 to skip worker process detection.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$jobType = 'process_photo_variants_v1';

$assetFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset-id=')) {
        $assetFilter = (int) substr($arg, strlen('--asset-id='));
    }
}

$db = app(\Core\App\Database::class);

echo "=== media_queue_pending_audit (job_type={$jobType}) ===\n";

$stuck = null;
if ($assetFilter !== null && $assetFilter > 0) {
    $stuck = $db->fetchOne(
        "SELECT i.id AS library_id, i.media_asset_id, ma.status AS asset_status
         FROM marketing_gift_card_images i
         INNER JOIN media_assets ma ON ma.id = i.media_asset_id
         WHERE i.deleted_at IS NULL AND i.media_asset_id = ?",
        [$assetFilter]
    );
    if ($stuck === null) {
        fwrite(STDERR, "No marketing_gift_card_images row for media_asset_id={$assetFilter}.\n");
        exit(1);
    }
} else {
    $stuck = $db->fetchOne(
        "SELECT i.id AS library_id, i.media_asset_id, ma.status AS asset_status
         FROM marketing_gift_card_images i
         INNER JOIN media_assets ma ON ma.id = i.media_asset_id
         WHERE i.deleted_at IS NULL AND ma.status IN ('pending','processing')
         ORDER BY i.id DESC
         LIMIT 1"
    );
}

if ($stuck === null) {
    echo "stuck_gift_card_image_row=none (no pending/processing gift-card assets)\n";
    $latest = $db->fetchOne(
        "SELECT i.id AS library_id, i.media_asset_id, ma.status AS asset_status
         FROM marketing_gift_card_images i
         INNER JOIN media_assets ma ON ma.id = i.media_asset_id
         WHERE i.deleted_at IS NULL
         ORDER BY i.id DESC
         LIMIT 1"
    );
    if ($latest !== null) {
        echo 'latest_gift_card_library_row=' . json_encode($latest, JSON_UNESCAPED_UNICODE) . "\n";
        $stuck = $latest;
    }
} else {
    echo 'stuck_gift_card_library_row=' . json_encode($stuck, JSON_UNESCAPED_UNICODE) . "\n";
}

if ($stuck === null) {
    exit(0);
}

$mediaAssetId = (int) $stuck['media_asset_id'];
$job = $db->fetchOne(
    'SELECT id, media_asset_id, status, job_type, attempts, error_message, available_at, locked_at, created_at, updated_at
     FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1',
    [$mediaAssetId]
);

echo 'latest_media_job_for_asset=' . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";

$jobId = $job !== null ? (int) $job['id'] : 0;
$pendingAhead = null;
if ($jobId > 0 && ($job['status'] ?? '') === 'pending') {
    $older = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM media_jobs
         WHERE status = 'pending' AND job_type = ? AND id < ?",
        [$jobType, $jobId]
    );
    $pendingAhead = (int) ($older['c'] ?? 0);
    echo "older_pending_jobs_before_this_job_id_f={$pendingAhead}\n";
    echo "fifo_delays_this_asset_if_older_count_gt_0=" . ($pendingAhead > 0 ? 'yes' : 'no') . "\n";
} elseif ($jobId > 0) {
    echo "older_pending_jobs_before_this_job_id_f=n/a (job not pending)\n";
    echo "fifo_delays_this_asset_if_older_count_gt_0=n/a\n";
} else {
    echo "older_pending_jobs_before_this_job_id_f=n/a (no job row)\n";
}

$first10 = $db->fetchAll(
    "SELECT id, media_asset_id, status, attempts, error_message, available_at, locked_at
     FROM media_jobs
     WHERE status = 'pending' AND job_type = ?
     ORDER BY id ASC
     LIMIT 10",
    [$jobType]
);
echo 'first_10_pending_jobs_id_asc=' . json_encode($first10, JSON_UNESCAPED_UNICODE) . "\n";

$pendingTotal = $db->fetchOne(
    "SELECT COUNT(*) AS c FROM media_jobs WHERE status = 'pending' AND job_type = ?",
    [$jobType]
);
echo 'total_pending_process_photo_variants_v1=' . (int) ($pendingTotal['c'] ?? 0) . "\n";

$probe = getenv('MEDIA_QUEUE_AUDIT_PROBE_PROCESSES');
$probeOn = $probe !== '0' && $probe !== 'false';
$workerRunning = 'unknown';
if ($probeOn) {
    $workerRunning = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();
}

echo "\n=== OBSERVABILITY_SUMMARY ===\n";
echo 'current_marketing_gift_card_images_id=' . (int) ($stuck['library_id'] ?? 0) . "\n";
echo 'current_media_asset_id=' . $mediaAssetId . "\n";
echo 'current_media_job_id=' . ($jobId > 0 ? (string) $jobId : 'none') . "\n";
echo 'pending_jobs_ahead_of_current_job_fifo=' . ($pendingAhead !== null ? (string) $pendingAhead : 'n/a') . "\n";
echo 'worker_running_yes_no=' . $workerRunning . "\n";
echo 'job_attempts=' . ($job !== null ? (string) ($job['attempts'] ?? '') : 'n/a') . "\n";
echo 'job_error_message=' . ($job !== null && isset($job['error_message']) ? json_encode((string) $job['error_message'], JSON_UNESCAPED_UNICODE) : 'null') . "\n";
echo 'job_available_at=' . ($job !== null && isset($job['available_at']) ? (string) $job['available_at'] : 'n/a') . "\n";
echo 'job_locked_at=' . ($job !== null && array_key_exists('locked_at', $job) ? ($job['locked_at'] !== null ? (string) $job['locked_at'] : 'null') : 'n/a') . "\n";
echo 'media_asset_status=' . (string) ($stuck['asset_status'] ?? '') . "\n";
echo 'job_status=' . ($job !== null ? (string) ($job['status'] ?? '') : 'n/a') . "\n";

echo "\n=== worker_runtime_hint ===\n";
echo "HTTP app does not embed the Node worker. Default local loop: php scripts/dev-only/run_media_image_worker_loop.php (second terminal).\n";
echo "APP_ENV=local spawns background drain after each upload (see MediaUploadWorkerDevTrigger); set MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD=0 to disable.\n";

echo "\n=== ops_note ===\n";
echo "Manual drain: php scripts/dev-only/drain_media_queue_until_asset.php --asset-id={$mediaAssetId}\n";
echo "One-shot diagnosis: php scripts/read-only/gift_card_image_pipeline_diagnose.php\n";
