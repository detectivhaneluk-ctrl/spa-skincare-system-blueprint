<?php

declare(strict_types=1);

/**
 * Standalone queue stale-job reclaim runner — WAVE-02.
 *
 * Reclaims `processing` rows whose `reserved_at` has exceeded the stale threshold
 * ({@see \Core\Runtime\Queue\RuntimeAsyncJobRepository::STALE_PROCESSING_SECONDS} = 900 s)
 * by resetting them to `pending` for re-pickup on the next worker poll.
 *
 * This runs SEPARATELY from the worker hot path. It must NOT be called from within
 * the per-poll reserveNext() cycle (which was the WAVE-02 bug to fix).
 *
 * Recommended cron schedule: every 5 minutes (generous margin above the 15-minute threshold).
 *
 * Crontab example (all queues, every 5 minutes):
 *   */5 * * * * php /path/to/repo/system/scripts/run_queue_stale_reclaim_cron.php >> /var/log/spa-queue-reclaim.log 2>&1
 *
 * Crontab example (single queue):
 *   */5 * * * * php /path/to/repo/system/scripts/run_queue_stale_reclaim_cron.php --queue=notifications
 *
 * Usage:
 *   php system/scripts/run_queue_stale_reclaim_cron.php [--queue=<name>]
 *
 * Exit codes:
 *   0 — success (0 or more rows reclaimed)
 *   1 — configuration / runtime error
 */

$systemRoot = dirname(__DIR__);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Application;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;

$queue = '';
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--queue=')) {
        $queue = trim(substr($arg, strlen('--queue=')));
    }
}

/** @var RuntimeAsyncJobRepository $repo */
$repo = Application::container()->get(RuntimeAsyncJobRepository::class);

$start = microtime(true);
$reclaimed = $repo->reclaimStaleJobs($queue);
$elapsed = round((microtime(true) - $start) * 1000);

$context = $queue !== '' ? "queue={$queue}" : 'all_queues';
$ts = date('Y-m-d\TH:i:s\Z');

if (function_exists('slog')) {
    \slog('info', 'critical_path.queue', 'stale_reclaim_ran', [
        'queue' => $queue !== '' ? $queue : '*',
        'reclaimed' => $reclaimed,
        'elapsed_ms' => $elapsed,
    ]);
}

fwrite(STDOUT, "[{$ts}] stale_reclaim {$context}: reclaimed={$reclaimed} elapsed_ms={$elapsed}\n");

exit(0);
