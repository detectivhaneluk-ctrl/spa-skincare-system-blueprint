<?php

declare(strict_types=1);

/**
 * Read-only audit: marketing gift-card library rows with non-ready media_assets + jobs + quarantine file.
 * Usage (from system/): php scripts/read-only/audit_gift_card_image_pending_processing.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);

echo "=== marketing_gift_card_images with media_asset_id (newest 8) ===\n";
$rows = $db->fetchAll(
    'SELECT i.id, i.branch_id, i.media_asset_id, i.title, i.created_at,
            ma.status AS asset_status, ma.stored_basename, ma.organization_id
     FROM marketing_gift_card_images i
     INNER JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.deleted_at IS NULL
     ORDER BY i.id DESC
     LIMIT 8'
);
foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "\n=== media_jobs for those assets (newest per asset) ===\n";
foreach ($rows as $r) {
    $aid = (int) ($r['media_asset_id'] ?? 0);
    if ($aid <= 0) {
        continue;
    }
    $j = $db->fetchAll(
        'SELECT id, media_asset_id, status, job_type, attempts, error_message, locked_at
         FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 2',
        [$aid]
    );
    echo "asset_{$aid}=" . json_encode($j, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "\n=== pending media_assets linked from gift-card images ===\n";
$pend = $db->fetchAll(
    "SELECT i.id AS library_id, ma.id AS asset_id, ma.status, ma.stored_basename, ma.branch_id, ma.organization_id
     FROM marketing_gift_card_images i
     INNER JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.deleted_at IS NULL AND ma.status IN ('pending','processing')
     ORDER BY i.id DESC
     LIMIT 10"
);
foreach ($pend as $p) {
    echo json_encode($p, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $org = (int) ($p['organization_id'] ?? 0);
    $br = (int) ($p['branch_id'] ?? 0);
    $bn = (string) ($p['stored_basename'] ?? '');
    $q = $base . '/storage/media/quarantine/' . $org . '/' . $br . '/' . $bn;
    echo '  quarantine_exists=' . (is_file($q) ? 'yes' : 'no') . ' path=' . str_replace('\\', '/', $q) . PHP_EOL;
}

echo "\n=== failed media_assets linked from gift-card images (newest 5) ===\n";
$fail = $db->fetchAll(
    "SELECT i.id AS library_id, ma.id AS asset_id, ma.status,
            j.id AS job_id, j.status AS job_status, j.attempts, j.error_message
     FROM marketing_gift_card_images i
     INNER JOIN media_assets ma ON ma.id = i.media_asset_id
     LEFT JOIN media_jobs j ON j.media_asset_id = ma.id AND j.status = 'failed'
     WHERE i.deleted_at IS NULL AND ma.status = 'failed'
     ORDER BY i.id DESC
     LIMIT 5"
);
foreach ($fail as $f) {
    echo json_encode($f, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "\n=== ops_note ===\n";
echo 'If assets stay pending: run or schedule Node worker (see system/docs/IMAGE-PIPELINE-FOUNDATION-01-OPS.md): php scripts/dev-only/run_media_image_worker_once.php' . PHP_EOL;
