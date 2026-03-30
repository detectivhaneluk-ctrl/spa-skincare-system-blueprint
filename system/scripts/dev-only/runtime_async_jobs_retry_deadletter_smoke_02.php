<?php

declare(strict_types=1);

/**
 * Runtime smoke: enqueue a job that always fails until max_attempts, expect terminal `dead`.
 *
 *   php system/scripts/dev-only/runtime_async_jobs_retry_deadletter_smoke_02.php
 *
 * Requires DB with migration 124 applied.
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\Database;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;

$repo = Application::container()->get(RuntimeAsyncJobRepository::class);
$db = Application::container()->get(Database::class);

$queue = 'smoke_dlq_02';
$type = 'smoke_always_fail_02';

try {
    $db->query('DELETE FROM runtime_async_jobs WHERE queue = ? AND job_type = ?', [$queue, $type]);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB/runtime_async_jobs unavailable: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$id = $repo->enqueue($queue, $type, ['n' => 1], 3);

$worker = static function () use ($repo, $queue): void {
    $job = $repo->reserveNext($queue);
    if ($job === null) {
        return;
    }
    $jid = (int) ($job['id'] ?? 0);
    $repo->markFailedRetryOrDead($jid, 'intentional smoke failure', 1);
};

for ($i = 0; $i < 8; $i++) {
    $worker();
    $row = $db->fetchOne('SELECT status, attempts FROM runtime_async_jobs WHERE id = ?', [$id]);
    if ($row !== null && ($row['status'] ?? '') === RuntimeAsyncJobRepository::STATUS_DEAD) {
        fwrite(STDOUT, "OK job {$id} reached dead after attempts=" . ($row['attempts'] ?? '?') . PHP_EOL);
        exit(0);
    }
    usleep(100_000);
}

fwrite(STDERR, "FAIL job {$id} did not reach dead status in time.\n");
exit(1);
