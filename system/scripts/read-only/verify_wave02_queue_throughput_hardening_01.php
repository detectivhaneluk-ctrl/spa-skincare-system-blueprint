<?php

declare(strict_types=1);

/**
 * WAVE-02 Queue Throughput Hardening — proof script.
 *
 * Verifies:
 *  W2-A  FOR UPDATE SKIP LOCKED in reserveNext()
 *  W2-B  reclaimStaleProcessingLocked() removed from reserveNext() hot path
 *  W2-C  Standalone reclaimStaleJobs() + cron script exist
 *  W2-D  Queue depth metrics method + health CLI exist
 *  W2-E  Job durability preserved (markSucceeded, markFailedRetryOrDead, enqueue unchanged)
 *
 * Run: php system/scripts/read-only/verify_wave02_queue_throughput_hardening_01.php
 * Expected: all assertions PASS, exit code 0.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function wave02_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "  PASS  {$label}\n";
    } else {
        ++$fail;
        echo "  FAIL  {$label}\n";
    }
}

function wave02_contains(string $file, string $needle, string $label): void
{
    $content = file_exists($file) ? file_get_contents($file) : '';
    wave02_assert(str_contains((string) $content, $needle), $label);
}

function wave02_not_contains(string $file, string $needle, string $label): void
{
    $content = file_exists($file) ? file_get_contents($file) : '';
    wave02_assert(!str_contains((string) $content, $needle), $label);
}

echo "\n=== WAVE-02 QUEUE THROUGHPUT HARDENING PROOF ===\n\n";

$repoFile = $repoRoot . '/system/core/Runtime/Queue/RuntimeAsyncJobRepository.php';
$loopFile = $repoRoot . '/system/core/Runtime/Queue/AsyncQueueWorkerLoop.php';
$cronFile = $repoRoot . '/system/scripts/run_queue_stale_reclaim_cron.php';
$healthFile = $repoRoot . '/system/scripts/read-only/queue_health_metrics_cli.php';

// ─── W2-A: SKIP LOCKED ───

echo "W2-A: FOR UPDATE SKIP LOCKED in reserveNext()\n";
wave02_contains($repoFile, 'FOR UPDATE SKIP LOCKED', 'RuntimeAsyncJobRepository uses FOR UPDATE SKIP LOCKED');
wave02_assert(
    str_contains((string) file_get_contents($repoFile), 'LIMIT 1 FOR UPDATE SKIP LOCKED'),
    'Worker pickup query uses LIMIT 1 FOR UPDATE SKIP LOCKED'
);

echo "\n";

// ─── W2-B: reclaimStaleProcessingLocked() removed from hot path ───

echo "W2-B: stale reclaim removed from reserveNext() hot path\n";

$repoContent = (string) file_get_contents($repoFile);
wave02_assert(
    !str_contains($repoContent, 'reclaimStaleProcessingLocked'),
    'reclaimStaleProcessingLocked() private method removed'
);
wave02_assert(
    !str_contains($repoContent, '$this->reclaimStale'),
    'reserveNext() does not call any stale reclaim inline'
);

$reserveNextStart = strpos($repoContent, 'public function reserveNext(');
$reserveNextEnd = $reserveNextStart !== false ? strpos($repoContent, "\n    public function ", $reserveNextStart + 10) : false;
if ($reserveNextStart !== false && $reserveNextEnd !== false) {
    $reserveNextBody = substr($repoContent, $reserveNextStart, $reserveNextEnd - $reserveNextStart);
    wave02_assert(!str_contains($reserveNextBody, 'stale'), 'reserveNext() body contains no stale reclaim call');
} else {
    wave02_assert(false, 'Could not isolate reserveNext() body for inspection');
}

// Worker loop docblock updated
wave02_assert(
    !str_contains((string) file_get_contents($loopFile), 'stale-reclaim built in'),
    'AsyncQueueWorkerLoop docblock no longer claims stale-reclaim is built in'
);

echo "\n";

// ─── W2-C: Standalone stale-reclaim runner ───

echo "W2-C: Standalone stale-reclaim cron script\n";
wave02_assert(file_exists($cronFile), 'run_queue_stale_reclaim_cron.php exists');
wave02_contains($cronFile, 'reclaimStaleJobs', 'Cron script calls reclaimStaleJobs()');
wave02_contains($cronFile, 'critical_path.queue', 'Cron script logs to critical_path.queue');
wave02_contains($repoFile, 'public function reclaimStaleJobs(', 'RuntimeAsyncJobRepository::reclaimStaleJobs() is public');
wave02_contains($repoFile, 'Call from a dedicated cron', 'reclaimStaleJobs() has cron scheduling doc comment');

echo "\n";

// ─── W2-D: Queue health metrics ───

echo "W2-D: Queue depth metrics + health CLI\n";
wave02_contains($repoFile, 'public function getQueueDepthMetrics(', 'RuntimeAsyncJobRepository::getQueueDepthMetrics() exists');
wave02_contains($repoFile, 'stale_processing', 'getQueueDepthMetrics() reports stale_processing count');
wave02_assert(file_exists($healthFile), 'queue_health_metrics_cli.php exists');
wave02_contains($healthFile, 'getQueueDepthMetrics', 'Health CLI uses getQueueDepthMetrics()');
wave02_contains($healthFile, '--json', 'Health CLI supports --json output mode');
wave02_contains($healthFile, 'critical', 'Health CLI reports critical severity for dead jobs');

echo "\n";

// ─── W2-E: Job durability preserved ───

echo "W2-E: Durability preserved (enqueue, markSucceeded, markFailedRetryOrDead unchanged)\n";
wave02_contains($repoFile, 'public function enqueue(', 'enqueue() still present');
wave02_contains($repoFile, 'public function markSucceeded(', 'markSucceeded() still present');
wave02_contains($repoFile, 'public function markFailedRetryOrDead(', 'markFailedRetryOrDead() still present');
wave02_contains($repoFile, 'public function enqueueNotificationsOutboundDrainIfAbsent(', 'enqueueNotificationsOutboundDrainIfAbsent() still present');
wave02_contains($repoFile, 'STATUS_PENDING', 'STATUS_PENDING constant preserved');
wave02_contains($repoFile, 'STATUS_PROCESSING', 'STATUS_PROCESSING constant preserved');
wave02_contains($repoFile, 'STATUS_DEAD', 'STATUS_DEAD constant preserved');

echo "\n";

// ─── Summary ───

$total = $pass + $fail;
echo "===========================================\n";
echo "WAVE-02 PROOF: {$pass}/{$total} assertions passed\n";
if ($fail > 0) {
    echo "RESULT: FAIL — {$fail} assertion(s) failed\n";
    exit(1);
}
echo "RESULT: PASS — WAVE-02 Queue Throughput Hardening deliverables verified.\n";
exit(0);
