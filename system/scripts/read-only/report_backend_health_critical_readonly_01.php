<?php

declare(strict_types=1);

/**
 * FOUNDATION-OBSERVABILITY-AND-ALERTING-01 — consolidated backend health (read-only probes).
 *
 * Surfaces session, storage provider, runtime_execution_registry, image pipeline queue/heartbeat,
 * and shared cache effectiveness in one machine-readable report with honest exit codes.
 *
 * From repository root:
 *   php system/scripts/read-only/report_backend_health_critical_readonly_01.php
 *   php system/scripts/read-only/report_backend_health_critical_readonly_01.php --json
 *   php system/scripts/read-only/report_backend_health_critical_readonly_01.php --quiet
 *   php system/scripts/read-only/report_backend_health_critical_readonly_01.php --no-structured-log
 *
 * Exit: 0 healthy, 1 degraded, 2 failed (see {@see \Core\Observability\BackendHealthStatus}).
 */

use Core\App\Application;
use Core\App\StructuredLogger;
use Core\Observability\BackendHealthReasonCodes;
use Core\Observability\BackendHealthCollector;

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(2);
}

$systemPath = $repoRoot . DIRECTORY_SEPARATOR . 'system';
$argvRest = array_slice($argv, 1);
$jsonOnly = in_array('--json', $argvRest, true);
$quiet = in_array('--quiet', $argvRest, true);
$noStructuredLog = in_array('--no-structured-log', $argvRest, true);

try {
    require $systemPath . '/bootstrap.php';
    require $systemPath . '/modules/bootstrap.php';
} catch (Throwable $e) {
    fwrite(STDERR, 'Bootstrap failed: ' . $e->getMessage() . "\n");
    exit(2);
}

try {
    /** @var BackendHealthCollector $collector */
    $collector = Application::container()->get(BackendHealthCollector::class);
    $report = $collector->collectAll();
} catch (Throwable $e) {
    fwrite(STDERR, 'Health collection failed: ' . $e->getMessage() . "\n");
    exit(2);
}

if (!$noStructuredLog && $report->exitCode !== 0 && Application::container()->has(StructuredLogger::class)) {
    $compact = [];
    foreach ($report->layers as $layer) {
        $compact[] = [
            'layer' => $layer->layer,
            'status' => $layer->status,
            'reason_codes' => $layer->reasonCodes,
        ];
    }
    Application::container()->get(StructuredLogger::class)->log(
        $report->exitCode >= 2 ? 'error' : 'warning',
        BackendHealthReasonCodes::LOG_EVENT_BACKEND_HEALTH_ISSUE,
        $report->overallSummary,
        [
            'overall_status' => $report->overallStatus,
            'exit_code' => $report->exitCode,
            'layers_compact' => $compact,
        ]
    );
}

$jsonPayload = json_encode($report->toJsonArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonPayload === false) {
    fwrite(STDERR, "JSON encode failed.\n");
    exit(2);
}

if ($jsonOnly) {
    echo $jsonPayload . "\n";
    exit($report->exitCode);
}

if (!$quiet) {
    echo "=== BACKEND HEALTH (FOUNDATION-OBSERVABILITY-AND-ALERTING-01) ===\n";
    echo 'overall_status=' . $report->overallStatus . ' exit_code=' . $report->exitCode . "\n";
    echo 'overall_summary=' . $report->overallSummary . "\n";
    foreach ($report->layers as $layer) {
        echo sprintf(
            "layer=%s status=%s reasons=%s summary=%s\n",
            $layer->layer,
            $layer->status,
            $layer->reasonCodes === [] ? '-' : implode(',', $layer->reasonCodes),
            $layer->summary
        );
    }
    echo 'health_json=' . $jsonPayload . "\n";
    echo "report_backend_health_critical_readonly_01: complete\n";
}

exit($report->exitCode);
