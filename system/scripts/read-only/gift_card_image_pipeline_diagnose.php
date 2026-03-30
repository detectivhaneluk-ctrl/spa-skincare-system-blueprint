<?php

declare(strict_types=1);

/**
 * Read-only: one-command diagnosis for gift-card marketing images stuck in the media pipeline.
 *
 * Usage (from system/):
 *   php scripts/read-only/gift_card_image_pipeline_diagnose.php
 *   php scripts/read-only/gift_card_image_pipeline_diagnose.php --asset-id=42
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaUploadWorkerDevTrigger;
use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;

$assetFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset-id=')) {
        $assetFilter = (int) substr($arg, strlen('--asset-id='));
    }
}

$db = app(\Core\App\Database::class);

echo "=== gift_card_image_pipeline_diagnose ===\n\n";

$stuck = null;
if ($assetFilter !== null && $assetFilter > 0) {
    $stuck = $db->fetchOne(
        "SELECT i.id AS library_image_id, i.media_asset_id, i.title, ma.status AS asset_status, ma.stored_basename
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
        "SELECT i.id AS library_image_id, i.media_asset_id, i.title, ma.status AS asset_status, ma.stored_basename
         FROM marketing_gift_card_images i
         INNER JOIN media_assets ma ON ma.id = i.media_asset_id
         WHERE i.deleted_at IS NULL AND ma.status IN ('pending','processing')
         ORDER BY i.id DESC
         LIMIT 1"
    );
}

if ($stuck === null) {
    echo "latest_stuck_marketing_image: none (no pending/processing gift-card media assets)\n";
    $latest = $db->fetchOne(
        "SELECT i.id AS library_image_id, i.media_asset_id, ma.status AS asset_status
         FROM marketing_gift_card_images i
         INNER JOIN media_assets ma ON ma.id = i.media_asset_id
         WHERE i.deleted_at IS NULL
         ORDER BY i.id DESC
         LIMIT 1"
    );
    if ($latest !== null) {
        echo 'latest_gift_card_library_row_any_status=' . json_encode($latest, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $cliMeta = MediaWorkerLocalRuntimeProbe::resolveCliPhpBinaryDetailed();
    $nodeMeta = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
    echo "\ncli_php_resolvable=" . (($cliMeta['path'] ?? null) !== null ? 'yes' : 'no') . "\n";
    echo 'cli_php_path=' . (($cliMeta['path'] ?? null) ?: 'null') . "\n";
    echo 'cli_php_source=' . ($cliMeta['source'] ?? 'none') . "\n";
    echo 'node_binary_resolvable=' . (($nodeMeta['path'] ?? null) !== null ? 'yes' : 'no') . "\n";
    echo 'node_binary_path=' . (($nodeMeta['path'] ?? null) ?: 'null') . "\n";
    echo 'node_binary_source=' . ($nodeMeta['source'] ?? 'none') . "\n";
    echo "worker_process_detected=" . MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess() . "\n";
    $spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
    echo 'last_spawn_diagnostics=' . json_encode($spawn, JSON_UNESCAPED_UNICODE) . "\n";
    echo "\nDIAGNOSIS: No stuck gift-card image in pending/processing; pipeline idle for this filter.\n";
    exit(0);
}

echo 'latest_stuck_marketing_image=' . json_encode($stuck, JSON_UNESCAPED_UNICODE) . "\n";

$mediaAssetId = (int) $stuck['media_asset_id'];
$assetRow = $db->fetchOne('SELECT * FROM media_assets WHERE id = ? LIMIT 1', [$mediaAssetId]);
echo 'media_assets_row=' . json_encode($assetRow, JSON_UNESCAPED_UNICODE) . "\n";

$job = $db->fetchOne(
    'SELECT id, media_asset_id, status, job_type, attempts, error_message, available_at, locked_at, created_at, updated_at
     FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1',
    [$mediaAssetId]
);
echo 'latest_media_job_row=' . json_encode($job, JSON_UNESCAPED_UNICODE) . "\n";

$jobId = $job !== null ? (int) $job['id'] : 0;
$pendingAhead = null;
if ($jobId > 0 && ($job['status'] ?? '') === 'pending') {
    $older = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM media_jobs
         WHERE status = 'pending' AND job_type = ? AND id < ?",
        [$jobType, $jobId]
    );
    $pendingAhead = (int) ($older['c'] ?? 0);
}
echo 'older_pending_jobs_ahead_fifo=' . ($pendingAhead !== null ? (string) $pendingAhead : 'n/a') . "\n";

$worker = MediaWorkerLocalRuntimeProbe::probeNodeImageWorkerProcess();
echo "worker_process_detected={$worker}\n";

$cliMeta = MediaWorkerLocalRuntimeProbe::resolveCliPhpBinaryDetailed();
echo 'cli_php_resolvable=' . (($cliMeta['path'] ?? null) !== null ? 'yes' : 'no') . "\n";
echo 'cli_php_path=' . (($cliMeta['path'] ?? null) ?: 'null') . "\n";
echo 'cli_php_source=' . ($cliMeta['source'] ?? 'none') . "\n";

$nodeMeta = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
echo 'node_binary_resolvable=' . (($nodeMeta['path'] ?? null) !== null ? 'yes' : 'no') . "\n";
echo 'node_binary_path=' . (($nodeMeta['path'] ?? null) ?: 'null') . "\n";
echo 'node_binary_source=' . ($nodeMeta['source'] ?? 'none') . "\n";

$spawn = MediaUploadWorkerDevTrigger::readLastSpawnDiagnostics();
echo 'last_spawn_diagnostics=' . json_encode($spawn, JSON_UNESCAPED_UNICODE) . "\n";

$diagnosis = 'unknown';
if ($spawn !== null && array_key_exists('ok', $spawn) && $spawn['ok'] === false) {
    $diagnosis = 'spawn_failed_or_cli_unresolved — fix MEDIA_DEV_PHP_BINARY / see storage/logs/media_dev_worker_spawn.json';
} elseif ($pendingAhead !== null && $pendingAhead > 0) {
    $diagnosis = 'fifo_backlog — this job is behind ' . $pendingAhead . ' older pending job(s); check queue truth: php scripts/read-only/media_queue_health_truth.php';
} elseif (($job['locked_at'] ?? null) !== null && (string) ($job['locked_at'] ?? '') !== '') {
    $diagnosis = 'processing — job is locked (worker may be running)';
} elseif (($job['status'] ?? '') === 'processing' || (string) ($stuck['asset_status'] ?? '') === 'processing') {
    $diagnosis = 'processing — asset or job in processing state';
} elseif ($worker === 'no' && (string) env('APP_ENV', 'production') === 'local') {
    $diagnosis = 'worker_not_running — start: php scripts/dev-only/run_media_image_worker_loop.php (from system/)';
} else {
    $diagnosis = 'pipeline_pending — check worker logs and media_jobs.error_message';
}

echo "\nDIAGNOSIS: {$diagnosis}\n";
