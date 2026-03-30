<?php

declare(strict_types=1);

/**
 * Read-only proof: deleted marketing images with live media queue rows.
 *
 * Usage (from system/):
 *   php scripts/read-only/marketing_deleted_image_queue_blocking_truth.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;

$db = app(\Core\App\Database::class);
$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;

$rows = $db->fetchAll(
    "SELECT i.id AS image_id,
            i.media_asset_id,
            i.deleted_at,
            j.id AS job_id,
            j.status AS job_status,
            j.attempts,
            j.locked_at,
            j.error_message,
            a.status AS asset_status
     FROM marketing_gift_card_images i
     INNER JOIN media_jobs j ON j.media_asset_id = i.media_asset_id
     INNER JOIN media_assets a ON a.id = i.media_asset_id
     WHERE i.deleted_at IS NOT NULL
       AND j.job_type = ?
       AND j.status IN ('pending','processing')
     ORDER BY j.id ASC",
    [$jobType]
);

$blocking = [];
foreach ($rows as $r) {
    $jobId = (int) ($r['job_id'] ?? 0);
    $jobStatus = (string) ($r['job_status'] ?? '');
    $blocking[] = [
        'image_id' => (int) ($r['image_id'] ?? 0),
        'media_asset_id' => (int) ($r['media_asset_id'] ?? 0),
        'job_id' => $jobId,
        'job_status' => $jobStatus,
        'attempts' => (int) ($r['attempts'] ?? 0),
        'locked_at' => $r['locked_at'] ?? null,
        'asset_status' => (string) ($r['asset_status'] ?? ''),
        'blocks_fifo' => $jobStatus === 'pending' ? 'yes' : 'no',
    ];
}

echo "=== marketing_deleted_image_queue_blocking_truth ===\n";
echo "deleted_images_with_pending_or_processing_jobs=" . count($blocking) . "\n";
echo 'rows=' . json_encode($blocking, JSON_UNESCAPED_UNICODE) . "\n";
echo 'BLOCKING-DELETED-JOBS: ' . (count($blocking) > 0 ? 'YES' : 'NO') . "\n";

