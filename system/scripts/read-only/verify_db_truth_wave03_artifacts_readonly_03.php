<?php

declare(strict_types=1);

/**
 * FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03 — docs + migrations + critical_path slog hooks.
 *
 *   php system/scripts/read-only/verify_db_truth_wave03_artifacts_readonly_03.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

foreach (
    [
        '/docs/DB-TRUTH-MAP-HIGH-RISK-DOMAINS-03.md',
        '/docs/SCALE-PROOF-AND-BOTTLENECK-MAP-03.md',
        '/data/migrations/126_runtime_async_jobs_ops_lag_index.sql',
        '/scripts/dev-only/db_hot_query_timing_proof_03.php',
    ] as $rel
) {
    if (!is_readable($root . $rel)) {
        $fail[] = 'Missing ' . $rel;
    }
}

$m126 = (string) file_get_contents($root . '/data/migrations/126_runtime_async_jobs_ops_lag_index.sql');
if (!str_contains($m126, 'idx_runtime_async_jobs_queue_status_updated')) {
    $fail[] = 'Migration 126 must add idx_runtime_async_jobs_queue_status_updated';
}

foreach (
    [
        ['/modules/auth/controllers/LoginController.php', 'critical_path.auth'],
        ['/modules/appointments/services/AppointmentService.php', 'critical_path.booking'],
        ['/modules/sales/services/PaymentService.php', 'critical_path.payment'],
        ['/modules/media/services/MediaAssetUploadService.php', 'critical_path.media'],
        ['/scripts/worker_runtime_async_jobs_cli_02.php', 'critical_path.queue'],
        ['/modules/organizations/services/FounderSafeActionGuardrailService.php', 'critical_path.auth'],
    ] as [$rel, $needle]
) {
    $p = $root . $rel;
    if (!is_readable($p) || !str_contains((string) file_get_contents($p), $needle)) {
        $fail[] = "{$rel} must contain {$needle}";
    }
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL db truth wave03 artifacts:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_db_truth_wave03_artifacts_readonly_03\n";
