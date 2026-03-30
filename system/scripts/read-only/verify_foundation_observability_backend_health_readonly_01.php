<?php

declare(strict_types=1);

/**
 * FOUNDATION-OBSERVABILITY-AND-ALERTING-01 — static anchors for consolidated backend health.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_foundation_observability_backend_health_readonly_01.php
 */

$root = realpath(dirname(__DIR__, 3));
if ($root === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(1);
}

$failed = false;

$bootstrap = (string) file_get_contents($root . '/system/modules/bootstrap.php');
if (!str_contains($bootstrap, "'register_observability.php'")) {
    fwrite(STDERR, "FAIL: register_observability.php not in modules/bootstrap.php\n");
    $failed = true;
}

$report = (string) file_get_contents($root . '/system/scripts/read-only/report_backend_health_critical_readonly_01.php');
foreach (['BackendHealthCollector', 'BackendHealthStatus', '--json', 'exit($report->exitCode)', 'observability.backend_health.issue_v1'] as $needle) {
    if (!str_contains($report, $needle)) {
        fwrite(STDERR, "FAIL: report_backend_health_critical_readonly_01.php missing anchor: {$needle}\n");
        $failed = true;
    }
}

$collectorSrc = (string) file_get_contents($root . '/system/core/Observability/BackendHealthCollector.php');
foreach (['probeSession', 'probeStorage', 'probeRuntimeRegistry', 'probeImagePipeline', 'probeSharedCache'] as $m) {
    if (!str_contains($collectorSrc, 'function ' . $m)) {
        fwrite(STDERR, "FAIL: BackendHealthCollector missing {$m}\n");
        $failed = true;
    }
}

$statusSrc = (string) file_get_contents($root . '/system/core/Observability/BackendHealthStatus.php');
if (!str_contains($statusSrc, 'HEALTHY') || !str_contains($statusSrc, 'DEGRADED') || !str_contains($statusSrc, 'FAILED')) {
    fwrite(STDERR, "FAIL: BackendHealthStatus must define HEALTHY/DEGRADED/FAILED\n");
    $failed = true;
}

$readme = (string) file_get_contents($root . '/system/scripts/read-only/report_operational_readiness_summary_readonly_01.php');
if (!str_contains($readme, 'report_backend_health_critical_readonly_01.php')) {
    fwrite(STDERR, "FAIL: operational readiness summary must invoke backend health report\n");
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "verify_foundation_observability_backend_health_readonly_01: OK\n";
exit(0);
