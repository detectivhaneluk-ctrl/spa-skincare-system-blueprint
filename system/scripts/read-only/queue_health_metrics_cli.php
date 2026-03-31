<?php

declare(strict_types=1);

/**
 * Queue health metrics CLI — WAVE-02.
 *
 * Emits per-queue depth, stale processing count, dead letter count, and an
 * overall health signal. Use in monitoring scripts, health-check endpoints, or
 * ops dashboards.
 *
 * Usage:
 *   php system/scripts/read-only/queue_health_metrics_cli.php [--queue=<name>] [--json]
 *
 * Exit codes:
 *   0 — all queues healthy (no stale or dead rows, or --json mode)
 *   2 — warning: stale processing rows detected (workers may be stuck)
 *   3 — critical: dead letter jobs exist (jobs exhausted all retries)
 */

$systemRoot = dirname(dirname(__DIR__));
require $systemRoot . '/system/bootstrap.php';

use Core\App\Application;
use Core\App\Config;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;

$queueFilter = '';
$jsonMode = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--queue=')) {
        $queueFilter = trim(substr($arg, strlen('--queue=')));
    }
    if ($arg === '--json') {
        $jsonMode = true;
    }
}

/** @var RuntimeAsyncJobRepository $repo */
$repo = Application::container()->get(RuntimeAsyncJobRepository::class);
/** @var Config $config */
$config = Application::container()->get(Config::class);

// Discover queues from config or use a fixed list.
$knownQueues = ['default', 'media', 'notifications'];
if ($queueFilter !== '') {
    $knownQueues = [$queueFilter];
}

$results = [];
$worstSeverity = 0; // 0 = ok, 2 = warning, 3 = critical

foreach ($knownQueues as $queue) {
    $metrics = $repo->getQueueDepthMetrics($queue);
    $severity = 0;
    $signals = [];
    if ($metrics['stale_processing'] > 0) {
        $severity = max($severity, 2);
        $signals[] = 'stale_processing=' . $metrics['stale_processing'];
    }
    if ($metrics['dead'] > 0) {
        $severity = max($severity, 3);
        $signals[] = 'dead=' . $metrics['dead'];
    }
    $worstSeverity = max($worstSeverity, $severity);
    $results[$queue] = array_merge($metrics, [
        'severity' => match ($severity) {
            3 => 'critical',
            2 => 'warning',
            default => 'ok',
        },
        'signals' => $signals,
    ]);
}

if ($jsonMode) {
    echo json_encode([
        'ts' => date('Y-m-d\TH:i:s\Z'),
        'queues' => $results,
        'overall' => match ($worstSeverity) {
            3 => 'critical',
            2 => 'warning',
            default => 'ok',
        },
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Human-readable output
$ts = date('Y-m-d\TH:i:sZ');
echo "=== Queue Health Metrics [{$ts}] ===\n";
foreach ($results as $queue => $m) {
    $sev = strtoupper((string) $m['severity']);
    $signals = !empty($m['signals']) ? ' [' . implode(', ', $m['signals']) . ']' : '';
    echo sprintf(
        "  %-20s  pending=%-5d processing=%-5d succeeded=%-6d dead=%-4d stale=%-4d  %s%s\n",
        $queue . ':',
        $m['pending'],
        $m['processing'],
        $m['succeeded'],
        $m['dead'],
        $m['stale_processing'],
        $sev,
        $signals
    );
}

$overall = match ($worstSeverity) {
    3 => 'CRITICAL',
    2 => 'WARNING',
    default => 'OK',
};
echo "Overall: {$overall}\n";

exit($worstSeverity === 3 ? 3 : ($worstSeverity === 2 ? 2 : 0));
