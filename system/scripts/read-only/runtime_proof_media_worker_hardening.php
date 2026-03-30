<?php

declare(strict_types=1);

/**
 * Read-only: job + asset + quarantine + variant dir truth for worker hardening proof.
 *
 * Usage (from system/): php scripts/read-only/runtime_proof_media_worker_hardening.php [job_id] [asset_id]
 * If omitted, uses latest job by id and its media_asset_id.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$system = dirname(__DIR__, 2);

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
$assetId = isset($argv[2]) ? (int) $argv[2] : 0;

if ($jobId <= 0) {
    $row = $pdo->query('SELECT id, media_asset_id FROM media_jobs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "abort=no_jobs\n";
        exit(1);
    }
    $jobId = (int) $row['id'];
    $assetId = (int) $row['media_asset_id'];
}

if ($assetId <= 0) {
    $stmt = $pdo->prepare('SELECT media_asset_id FROM media_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $assetId = (int) ($row['media_asset_id'] ?? 0);
}

echo 'proof_job_id=' . $jobId . PHP_EOL;
echo 'proof_asset_id=' . $assetId . PHP_EOL;

$j = $pdo->prepare(
    'SELECT id, media_asset_id, status, job_type, attempts, locked_at, error_message, available_at FROM media_jobs WHERE id = ?'
);
$j->execute([$jobId]);
echo 'media_jobs=' . json_encode($j->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . PHP_EOL;

$a = $pdo->prepare(
    'SELECT id, organization_id, branch_id, status, stored_basename FROM media_assets WHERE id = ?'
);
$a->execute([$assetId]);
$asset = $a->fetch(PDO::FETCH_ASSOC);
echo 'media_assets=' . json_encode($asset, JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($asset) {
    $org = (int) $asset['organization_id'];
    $br = (int) $asset['branch_id'];
    $base = (string) $asset['stored_basename'];
    $qPath = $system . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine'
        . DIRECTORY_SEPARATOR . $org . DIRECTORY_SEPARATOR . $br . DIRECTORY_SEPARATOR . $base;
    echo 'quarantine_path=' . $qPath . PHP_EOL;
    echo 'quarantine_exists=' . (is_file($qPath) ? 'yes' : 'no') . PHP_EOL;

    $procRel = 'media' . DIRECTORY_SEPARATOR . 'processed' . DIRECTORY_SEPARATOR . $org . DIRECTORY_SEPARATOR . $br . DIRECTORY_SEPARATOR . $assetId;
    $procAbs = $system . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $procRel);
    echo 'processed_dir=' . $procAbs . PHP_EOL;
    echo 'processed_dir_exists=' . (is_dir($procAbs) ? 'yes' : 'no') . PHP_EOL;
    $n = 0;
    if (is_dir($procAbs)) {
        foreach (scandir($procAbs) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && is_file($procAbs . DIRECTORY_SEPARATOR . $f)) {
                $n++;
            }
        }
    }
    echo 'processed_file_count=' . $n . PHP_EOL;
}

$c = $pdo->prepare('SELECT COUNT(*) FROM media_asset_variants WHERE media_asset_id = ?');
$c->execute([$assetId]);
echo 'variant_row_count=' . (int) $c->fetchColumn() . PHP_EOL;
