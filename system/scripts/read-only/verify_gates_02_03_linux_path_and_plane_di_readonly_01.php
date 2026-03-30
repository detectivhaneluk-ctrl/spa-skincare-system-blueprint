<?php

declare(strict_types=1);

/**
 * FOUNDATION-CI-LINUX-PATH-FIX-AND-DI-FRAGILITY-KILL-01 — readonly: gate 02/03 path case + critical plane DI bindings.
 *
 *   php system/scripts/read-only/verify_gates_02_03_linux_path_and_plane_di_readonly_01.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

$mustExist = [
    'bootstrap.php',
    'core/auth/SessionAuth.php',
    'core/auth/AuthService.php',
    'core/auth/UserSessionEpochRepository.php',
    'core/auth/SessionEpochCoordinator.php',
    'core/middleware/PlatformManagePostRateLimitMiddleware.php',
    'core/Runtime/RateLimit/RuntimeProtectedPathRateLimiter.php',
    'core/Runtime/Queue/RuntimeAsyncJobRepository.php',
    'core/Storage/StorageProviderFactory.php',
    'core/Storage/S3CompatibleObjectStorageProvider.php',
    'core/Storage/S3/S3SigV4Signer.php',
    'core/audit/AuditService.php',
    'modules/auth/controllers/LoginController.php',
    'modules/appointments/services/AppointmentService.php',
    'modules/sales/services/PaymentService.php',
    'modules/media/services/MediaAssetUploadService.php',
    'modules/organizations/services/FounderSafeActionGuardrailService.php',
    'scripts/worker_runtime_async_jobs_cli_02.php',
    'scripts/invalidate_user_sessions_cli_02.php',
    'scripts/dev-only/db_hot_query_timing_proof_03.php',
    'scripts/run_runtime_distributed_planes_proof_gate_02.php',
    'scripts/run_db_truth_observability_proof_gate_03.php',
];

foreach ($mustExist as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        $fail[] = "Missing or wrong-case path (Linux): {$rel}";
    }
}

$gate02 = (string) file_get_contents($root . '/scripts/run_runtime_distributed_planes_proof_gate_02.php');
if (str_contains($gate02, '/core/Middleware/')) {
    $fail[] = 'run_runtime_distributed_planes_proof_gate_02.php must not reference /core/Middleware/ (use core/middleware/)';
}

$gate03 = (string) file_get_contents($root . '/scripts/run_db_truth_observability_proof_gate_03.php');
if (str_contains($gate03, '/core/Audit/')) {
    $fail[] = 'run_db_truth_observability_proof_gate_03.php must not reference /core/Audit/ (use core/audit/)';
}

$auditRo = (string) file_get_contents($root . '/scripts/read-only/verify_audit_logs_structured_columns_readonly_03.php');
if (str_contains($auditRo, '/core/Audit/')) {
    $fail[] = 'verify_audit_logs_structured_columns_readonly_03.php must not reference /core/Audit/';
}

$boot = (string) file_get_contents($root . '/bootstrap.php');
$bootNeedles = [
    '$container->singleton(\\Core\\Middleware\\PlatformManagePostRateLimitMiddleware::class' => 'bootstrap must register PlatformManagePostRateLimitMiddleware (platform.manage pipeline / A-002)',
    '$container->singleton(\\Core\\Runtime\\Queue\\RuntimeAsyncJobRepository::class' => 'bootstrap must register RuntimeAsyncJobRepository',
    '$container->singleton(\\Core\\Auth\\UserSessionEpochRepository::class' => 'bootstrap must register UserSessionEpochRepository',
    '$container->singleton(\\Core\\App\\Database::class' => 'bootstrap must register Database',
];
foreach ($bootNeedles as $needle => $msg) {
    if (!str_contains($boot, $needle)) {
        $fail[] = $msg;
    }
}

$contracts = (string) file_get_contents($root . '/modules/bootstrap/register_appointments_online_contracts.php');
if (!str_contains($contracts, '$container->singleton(\\Core\\Runtime\\RateLimit\\RuntimeProtectedPathRateLimiter::class')) {
    $fail[] = 'register_appointments_online_contracts.php must register RuntimeProtectedPathRateLimiter (login + platform.manage rate limits)';
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL gates 02/03 linux path + plane DI readonly 01:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_gates_02_03_linux_path_and_plane_di_readonly_01\n";
