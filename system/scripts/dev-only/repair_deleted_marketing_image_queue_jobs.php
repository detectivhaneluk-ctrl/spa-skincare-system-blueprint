<?php

declare(strict_types=1);

/**
 * Dev-only repair: close queue rows for deleted marketing image assets.
 *
 * Usage (from system/):
 *   php scripts/dev-only/repair_deleted_marketing_image_queue_jobs.php         # dry-run
 *   php scripts/dev-only/repair_deleted_marketing_image_queue_jobs.php --apply # execute
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaAssetUploadService;

$apply = in_array('--apply', $argv, true);
$db = app(\Core\App\Database::class);
$jobType = MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO;
$reason = 'deleted_from_marketing_library';

$targets = $db->fetchAll(
    "SELECT DISTINCT j.media_asset_id
     FROM media_jobs j
     WHERE j.job_type = ?
       AND j.status IN ('pending','processing')
       AND EXISTS (
         SELECT 1 FROM marketing_gift_card_images i_del
         WHERE i_del.media_asset_id = j.media_asset_id
           AND i_del.deleted_at IS NOT NULL
       )
       AND NOT EXISTS (
         SELECT 1 FROM marketing_gift_card_images i_active
         WHERE i_active.media_asset_id = j.media_asset_id
           AND i_active.deleted_at IS NULL
       )",
    [$jobType]
);

$changes = [];
foreach ($targets as $t) {
    $assetId = (int) ($t['media_asset_id'] ?? 0);
    if ($assetId <= 0) {
        continue;
    }
    $jobs = $db->fetchAll(
        "SELECT id, status FROM media_jobs
         WHERE media_asset_id = ?
           AND job_type = ?
           AND status IN ('pending','processing')
         ORDER BY id ASC",
        [$assetId, $jobType]
    );
    if ($jobs === []) {
        continue;
    }
    $changes[] = [
        'media_asset_id' => $assetId,
        'jobs_to_fail' => array_map(static fn (array $j): int => (int) ($j['id'] ?? 0), $jobs),
    ];
    if (!$apply) {
        continue;
    }
    $db->query(
        "UPDATE media_jobs
         SET status='failed', locked_at=NULL, error_message=?, updated_at=NOW()
         WHERE media_asset_id = ?
           AND job_type = ?
           AND status IN ('pending','processing')",
        [$reason, $assetId, $jobType]
    );
    $db->query(
        "UPDATE media_assets
         SET status='failed', updated_at=NOW()
         WHERE id = ?
           AND status IN ('pending','processing')",
        [$assetId]
    );
}

echo "=== repair_deleted_marketing_image_queue_jobs ===\n";
echo "mode=" . ($apply ? 'apply' : 'dry-run') . "\n";
echo 'changes=' . json_encode($changes, JSON_UNESCAPED_UNICODE) . "\n";
echo 'changed_assets=' . count($changes) . "\n";
if (!$apply) {
    echo "note=dry-run only; re-run with --apply to execute\n";
}

