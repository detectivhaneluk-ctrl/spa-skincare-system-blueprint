<?php

declare(strict_types=1);

/**
 * Read-only: asset + latest job + variant rows and on-disk files for worker proof closure.
 *
 * Usage (from system/): php scripts/read-only/runtime_proof_media_worker_truth.php [asset_id]
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$system = dirname(__DIR__, 2);

$assetId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($assetId <= 0) {
    $row = $pdo->query('SELECT id FROM media_assets ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assetId = (int) ($row['id'] ?? 0);
}

echo 'proof_asset_id=' . $assetId . PHP_EOL;
if ($assetId <= 0) {
    echo 'abort=no_assets' . PHP_EOL;
    exit(1);
}

$st = $pdo->prepare('SELECT id, status, stored_basename, organization_id, branch_id FROM media_assets WHERE id = ?');
$st->execute([$assetId]);
$asset = $st->fetch(PDO::FETCH_ASSOC);
echo 'media_assets=' . json_encode($asset, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$jst = $pdo->prepare(
    'SELECT id, media_asset_id, status, job_type, error_message, attempts FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1'
);
$jst->execute([$assetId]);
$job = $jst->fetch(PDO::FETCH_ASSOC);
echo 'media_jobs=' . json_encode($job, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$cst = $pdo->prepare('SELECT COUNT(*) FROM media_asset_variants WHERE media_asset_id = ?');
$cst->execute([$assetId]);
echo 'variant_count=' . (int) $cst->fetchColumn() . PHP_EOL;

$pst = $pdo->prepare(
    'SELECT relative_path, format, width, height, variant_kind FROM media_asset_variants WHERE media_asset_id = ? ORDER BY variant_kind, format, width'
);
$pst->execute([$assetId]);
$verified = 0;
foreach ($pst->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $rp = (string) $p['relative_path'];
    $abs = $system . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rp);
    $ok = is_file($abs);
    if ($ok) {
        $verified++;
    }
    echo 'variant_path=' . $rp . ' file_exists=' . ($ok ? 'yes' : 'no') . PHP_EOL;
}
echo 'variant_files_verified=' . $verified . PHP_EOL;
