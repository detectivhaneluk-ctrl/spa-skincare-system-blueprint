<?php

declare(strict_types=1);

/**
 * Dev-only: run the Node image-pipeline worker continuously until Ctrl+C.
 *
 * Forwards DB_* and MEDIA_SYSTEM_ROOT from the app env (same as run_media_image_worker_once.php),
 * so pending media_jobs (e.g. gift-card uploads) are processed without manual env export.
 *
 * Usage (from system/):
 *   php scripts/dev-only/run_media_image_worker_loop.php
 *
 * Optional env (before running PHP, or in .env):
 *   NODE_BINARY — path to node if not on PATH
 *   WORKER_POLL_MS — idle sleep when no jobs (default in worker: 8000)
 *   IMAGE_JOB_STALE_LOCK_MINUTES, IMAGE_JOB_MAX_ATTEMPTS — forwarded to worker
 *
 * This is not a production supervisor; use systemd, pm2, or your platform scheduler in deployed envs.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Modules\Media\Services\MediaWorkerLocalRuntimeProbe;

$system = dirname(__DIR__, 2);
$workerDir = realpath($system . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline');
if ($workerDir === false || !is_file($workerDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'worker.mjs')) {
    fwrite(STDERR, "Worker directory not found.\n");
    exit(1);
}

$nodeResolved = MediaWorkerLocalRuntimeProbe::resolveNodeBinaryDetailed();
$node = is_string($nodeResolved['path'] ?? null) && $nodeResolved['path'] !== '' ? $nodeResolved['path'] : null;
if ($node === null) {
    fwrite(STDERR, "Node binary unresolved (" . (string) ($nodeResolved['detail'] ?? 'unknown') . ").\n");
    exit(1);
}

putenv('MEDIA_SYSTEM_ROOT=' . $system);
// Do not set WORKER_ONCE or WORKER_MAX_JOBS — worker runs until stopped.
putenv('DB_HOST=' . (string) env('DB_HOST', '127.0.0.1'));
putenv('DB_PORT=' . (string) env('DB_PORT', '3306'));
putenv('DB_DATABASE=' . (string) env('DB_DATABASE', ''));
putenv('DB_USERNAME=' . (string) env('DB_USERNAME', ''));
putenv('DB_PASSWORD=' . (string) env('DB_PASSWORD', ''));
foreach (['IMAGE_JOB_STALE_LOCK_MINUTES', 'IMAGE_JOB_MAX_ATTEMPTS', 'WORKER_POLL_MS'] as $k) {
    $v = env($k, null);
    if ($v !== null && $v !== '') {
        putenv($k . '=' . (string) $v);
    }
}

$prev = getcwd();
chdir($workerDir);
$cmd = escapeshellarg($node) . ' src/worker.mjs';
passthru($cmd, $exitCode);
chdir($prev);

exit($exitCode);
