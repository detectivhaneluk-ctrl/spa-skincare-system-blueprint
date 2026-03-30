<?php

declare(strict_types=1);

/**
 * Claims queued client_merge_jobs rows and executes merges under stored org/branch scope.
 * Each claim pass reconciles stale {@code running} jobs first (worker-crash recovery); see ClientMergeJobService.
 * Configure cron or run manually after operators queue merges from the staff UI.
 *
 * Usage (from system/):
 *   php scripts/dev-only/run_client_merge_jobs_once.php
 *   php scripts/dev-only/run_client_merge_jobs_once.php 5
 *
 * The optional argument is how many jobs to attempt in sequence (default 1).
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$max = isset($argv[1]) ? max(1, (int) $argv[1]) : 1;

/** @var \Modules\Clients\Services\ClientMergeJobService $svc */
$svc = app(\Modules\Clients\Services\ClientMergeJobService::class);

$done = 0;
for ($i = 0; $i < $max; $i++) {
    if (!$svc->claimAndExecuteNextMergeJob()) {
        break;
    }
    $done++;
}

fwrite(STDOUT, 'client_merge_jobs_processed=' . $done . PHP_EOL);
exit(0);
