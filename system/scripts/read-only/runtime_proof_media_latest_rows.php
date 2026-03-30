<?php

declare(strict_types=1);

/**
 * Read-only: latest media_assets row and its newest media_jobs row (runtime proof closure).
 */
require dirname(__DIR__, 2) . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$a = $pdo->query('SELECT id, status, stored_basename FROM media_assets ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo 'latest_media_assets=' . json_encode($a, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$assetId = (int) ($a['id'] ?? 0);
if ($assetId > 0) {
    $j = $pdo->prepare('SELECT id, media_asset_id, status FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1');
    $j->execute([$assetId]);
    $job = $j->fetch(PDO::FETCH_ASSOC);
    echo 'latest_media_jobs_for_asset=' . json_encode($job, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
