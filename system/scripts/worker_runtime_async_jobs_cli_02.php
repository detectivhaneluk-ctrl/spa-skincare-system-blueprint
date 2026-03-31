<?php

declare(strict_types=1);

/**
 * Unified async queue worker — canonical control-plane drain script (PLT-Q-01).
 *
 * Drains {@see \Core\Runtime\Queue\RuntimeAsyncJobRepository} for a single queue
 * using the canonical {@see \Core\Runtime\Queue\AsyncQueueWorkerLoop} and the
 * registered handler registry ({@see \Core\Runtime\Queue\AsyncJobHandlerRegistry}).
 *
 * Queues in use:
 *   default       — client merge (`clients.merge_execute`)
 *   media         — image pipeline bridge (`media.image_pipeline`)
 *   notifications — outbound drain (`notifications.outbound_drain_batch`)
 *
 * All job_type dispatch is handled by the registry; no hard-coded match table here.
 * Adding a new job_type requires only registering a handler in register_async_queue.php.
 *
 * Usage (from repo root, after bootstrap env):
 *   php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=default --once
 *   php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=media
 *   php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=notifications --once
 */

$systemRoot = dirname(__DIR__);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Application;
use Core\Runtime\Queue\AsyncQueueWorkerLoop;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;

$queue = 'default';
$once = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--queue=')) {
        $queue = trim(substr($arg, strlen('--queue=')));
    }
    if ($arg === '--once') {
        $once = true;
    }
}
if ($queue === '') {
    fwrite(STDERR, "queue required\n");
    exit(1);
}

/** @var AsyncQueueWorkerLoop $workerLoop */
$workerLoop = Application::container()->get(AsyncQueueWorkerLoop::class);

if ($once) {
    try {
        $workerLoop->runOnce($queue);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'runtime_async_jobs')) {
            fwrite(STDERR, "runtime_async_jobs table missing; apply migration 124.\n");
            exit(2);
        }
        throw $e;
    }
    exit(0);
}

while (true) {
    try {
        $processed = $workerLoop->runOnce($queue);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'runtime_async_jobs')) {
            fwrite(STDERR, "runtime_async_jobs table missing; apply migration 124.\n");
            exit(2);
        }
        throw $e;
    }
    if (!$processed) {
        sleep(2);
    }
}
