<?php

declare(strict_types=1);

/**
 * Dev-only: one worker pass (WORKER_ONCE=1, WORKER_MAX_JOBS=1) with DB + MEDIA_SYSTEM_ROOT from app env.
 * Completes at most one job (oldest pending media_jobs.id / FIFO). For backlog on a specific asset,
 * use scripts/dev-only/drain_media_queue_until_asset.php or run_media_image_worker_loop.php.
 *
 * Usage (from system/): php scripts/dev-only/run_media_image_worker_once.php
 *
 * Optional: set NODE_BINARY if `node` is not on PATH.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$system = dirname(__DIR__, 2);
$workerDir = realpath($system . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline');
if ($workerDir === false || !is_file($workerDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'worker.mjs')) {
    fwrite(STDERR, "Worker directory not found.\n");
    exit(1);
}

$node = getenv('NODE_BINARY') ?: 'node';

putenv('MEDIA_SYSTEM_ROOT=' . $system);
putenv('WORKER_ONCE=1');
putenv('WORKER_MAX_JOBS=1');
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

$prev = getcwd();
chdir($workerDir);
$cmd = escapeshellarg($node) . ' src/worker.mjs';
$exitCode = 0;
passthru($cmd, $exitCode);
chdir($prev);

exit($exitCode);
