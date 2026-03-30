<?php

declare(strict_types=1);

/**
 * FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03 — readonly verifiers + php -l on touched PHP.
 */

$systemRoot = dirname(__DIR__);

$readonly = [
    $systemRoot . '/scripts/read-only/verify_audit_logs_structured_columns_readonly_03.php',
    $systemRoot . '/scripts/read-only/verify_db_truth_wave03_artifacts_readonly_03.php',
];

$phpFiles = [
    $systemRoot . '/core/audit/AuditService.php',
    $systemRoot . '/modules/auth/controllers/LoginController.php',
    $systemRoot . '/modules/appointments/services/AppointmentService.php',
    $systemRoot . '/modules/sales/services/PaymentService.php',
    $systemRoot . '/modules/media/services/MediaAssetUploadService.php',
    $systemRoot . '/modules/organizations/services/FounderSafeActionGuardrailService.php',
    $systemRoot . '/core/auth/AuthService.php',
    $systemRoot . '/scripts/worker_runtime_async_jobs_cli_02.php',
    $systemRoot . '/scripts/dev-only/db_hot_query_timing_proof_03.php',
];

$php = PHP_BINARY;

foreach (array_merge($readonly, $phpFiles) as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "FAIL missing: {$f}\n");
        exit(1);
    }
}

foreach ($readonly as $script) {
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($script), $code);
    if ($code !== 0) {
        exit($code);
    }
}

foreach ($phpFiles as $f) {
    passthru(escapeshellarg($php) . ' -l ' . escapeshellarg($f), $code);
    if ($code !== 0) {
        exit($code);
    }
}

echo "DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03 gate: passed.\n";
