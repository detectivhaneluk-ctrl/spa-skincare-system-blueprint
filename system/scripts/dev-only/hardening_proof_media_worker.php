<?php

declare(strict_types=1);

/**
 * Dev-only: drive worker hardening proof scenarios (stale reclaim, transient, terminal, exhausted, duplicate prep).
 *
 * Usage (from system/):
 *   php scripts/dev-only/hardening_proof_media_worker.php stale-lock --job-id=N
 *   php scripts/dev-only/hardening_proof_media_worker.php node-reclaim
 *   php scripts/dev-only/hardening_proof_media_worker.php node-once [--extra-env KEY=VAL ...]
 *   php scripts/dev-only/hardening_proof_media_worker.php corrupt-quarantine --job-id=N
 *   php scripts/dev-only/hardening_proof_media_worker.php reset-for-reprocess --asset-id=N --job-id=J
 *
 * reset-for-reprocess: copies a minimal PNG into quarantine (by asset row), deletes variant rows, sets asset+job pending.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$db = app(\Core\App\Database::class);
$pdo = $db->connection();
$system = dirname(__DIR__, 2);

$workerDir = realpath($system . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline');
if ($workerDir === false) {
    fwrite(STDERR, "Worker dir missing.\n");
    exit(1);
}

function applyDbEnvFromApp(): void
{
    putenv('MEDIA_SYSTEM_ROOT=' . dirname(__DIR__, 2));
    putenv('DB_HOST=' . (string) env('DB_HOST', '127.0.0.1'));
    putenv('DB_PORT=' . (string) env('DB_PORT', '3306'));
    putenv('DB_DATABASE=' . (string) env('DB_DATABASE', ''));
    putenv('DB_USERNAME=' . (string) env('DB_USERNAME', ''));
    putenv('DB_PASSWORD=' . (string) env('DB_PASSWORD', ''));
    foreach (['IMAGE_JOB_STALE_LOCK_MINUTES', 'IMAGE_JOB_MAX_ATTEMPTS'] as $k) {
        $v = env($k, null);
        if ($v !== null && $v !== '') {
            putenv($k . '=' . (string) $v);
        }
    }
}

function runNodeWorker(string $workerDir, array $envOverrides): int
{
    applyDbEnvFromApp();
    foreach ($envOverrides as $k => $v) {
        putenv($k . '=' . $v);
    }
    $node = getenv('NODE_BINARY') ?: 'node';
    $prev = getcwd();
    chdir($workerDir);
    $exitCode = 0;
    passthru(escapeshellarg($node) . ' src/worker.mjs', $exitCode);
    chdir($prev);

    return $exitCode;
}

function parseJobId(array $argv): int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--job-id=')) {
            return (int) substr($arg, 9);
        }
    }

    return 0;
}

function parseAssetId(array $argv): int
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--asset-id=')) {
            return (int) substr($arg, 11);
        }
    }

    return 0;
}

$sub = $argv[1] ?? '';
$rest = array_slice($argv, 2);

if ($sub === 'stale-lock') {
    $jobId = parseJobId($rest);
    if ($jobId <= 0) {
        fwrite(STDERR, "Usage: ... stale-lock --job-id=N\n");
        exit(1);
    }
    $pdo->prepare(
        'UPDATE media_jobs SET status = \'processing\', locked_at = DATE_SUB(NOW(), INTERVAL 120 MINUTE), updated_at = NOW() WHERE id = ?'
    )->execute([$jobId]);
    $jr = $db->fetchOne('SELECT media_asset_id FROM media_jobs WHERE id = ?', [$jobId]);
    if ($jr === null) {
        fwrite(STDERR, "Job not found.\n");
        exit(1);
    }
    $aid = (int) $jr['media_asset_id'];
    $pdo->prepare('UPDATE media_assets SET status = \'processing\', updated_at = NOW() WHERE id = ?')->execute([$aid]);
    echo "stale_lock_applied job_id={$jobId} asset_id={$aid}\n";
    exit(0);
}

if ($sub === 'node-reclaim') {
    $code = runNodeWorker($workerDir, [
        'WORKER_ONLY_RECLAIM' => '1',
    ]);
    exit($code);
}

if ($sub === 'node-once') {
    $env = [
        'WORKER_ONCE' => '1',
        'WORKER_MAX_JOBS' => '1',
    ];
    foreach ($rest as $arg) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $arg, $m)) {
            $env[$m[1]] = $m[2];
        }
    }
    $code = runNodeWorker($workerDir, $env);
    exit($code);
}

if ($sub === 'corrupt-quarantine') {
    $jobId = parseJobId($rest);
    if ($jobId <= 0) {
        fwrite(STDERR, "Usage: ... corrupt-quarantine --job-id=N\n");
        exit(1);
    }
    $row = $db->fetchOne(
        'SELECT a.id AS asset_id, a.organization_id, a.branch_id, a.stored_basename
         FROM media_jobs j INNER JOIN media_assets a ON a.id = j.media_asset_id
         WHERE j.id = ?',
        [$jobId]
    );
    if ($row === null) {
        fwrite(STDERR, "Job not found.\n");
        exit(1);
    }
    $aid = (int) $row['asset_id'];
    $pdo->prepare(
        'UPDATE media_jobs SET status = \'pending\', locked_at = NULL, error_message = NULL, attempts = 0, available_at = NOW(), updated_at = NOW() WHERE id = ?'
    )->execute([$jobId]);
    $pdo->prepare('UPDATE media_assets SET status = \'pending\', updated_at = NOW() WHERE id = ?')->execute([$aid]);
    $path = $system . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine'
        . DIRECTORY_SEPARATOR . (int) $row['organization_id'] . DIRECTORY_SEPARATOR . (int) $row['branch_id']
        . DIRECTORY_SEPARATOR . $row['stored_basename'];
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed mkdir quarantine.\n");
        exit(1);
    }
    file_put_contents($path, "not-a-valid-image-binary\x00\x01");
    echo "corrupt_quarantine_written path={$path} job_pending=yes asset_pending=yes\n";
    exit(0);
}

if ($sub === 'reset-for-reprocess') {
    $assetId = parseAssetId($rest);
    $jobId = parseJobId($rest);
    if ($assetId <= 0 || $jobId <= 0) {
        fwrite(STDERR, "Usage: ... reset-for-reprocess --asset-id=N --job-id=J\n");
        exit(1);
    }
    $row = $db->fetchOne(
        'SELECT organization_id, branch_id, stored_basename FROM media_assets WHERE id = ?',
        [$assetId]
    );
    if ($row === null) {
        fwrite(STDERR, "Asset not found.\n");
        exit(1);
    }
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    );
    $path = $system . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine'
        . DIRECTORY_SEPARATOR . (int) $row['organization_id'] . DIRECTORY_SEPARATOR . (int) $row['branch_id']
        . DIRECTORY_SEPARATOR . $row['stored_basename'];
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed mkdir quarantine.\n");
        exit(1);
    }
    file_put_contents($path, $png);
    $pdo->prepare('DELETE FROM media_asset_variants WHERE media_asset_id = ?')->execute([$assetId]);
    $pdo->prepare(
        'UPDATE media_jobs SET status = \'pending\', locked_at = NULL, error_message = NULL, attempts = 0, available_at = NOW(), updated_at = NOW() WHERE id = ?'
    )->execute([$jobId]);
    $pdo->prepare('UPDATE media_assets SET status = \'pending\', updated_at = NOW() WHERE id = ?')->execute([$assetId]);
    echo "reset_for_reprocess asset_id={$assetId} job_id={$jobId} quarantine_restored=yes variants_deleted=yes\n";
    exit(0);
}

fwrite(STDERR, "Unknown subcommand. See file docblock.\n");
exit(1);
