<?php

declare(strict_types=1);

/**
 * FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02 — aggregate gate (readonly verifiers + syntax).
 *
 *   php system/scripts/run_runtime_distributed_planes_proof_gate_02.php
 */

$systemRoot = dirname(__DIR__);
$phpFiles = [
    $systemRoot . '/bootstrap.php',
    $systemRoot . '/core/auth/SessionAuth.php',
    $systemRoot . '/core/auth/AuthService.php',
    $systemRoot . '/core/auth/UserSessionEpochRepository.php',
    $systemRoot . '/core/auth/SessionEpochCoordinator.php',
    $systemRoot . '/core/middleware/PlatformManagePostRateLimitMiddleware.php',
    $systemRoot . '/core/Runtime/RateLimit/RuntimeProtectedPathRateLimiter.php',
    $systemRoot . '/core/Runtime/Queue/RuntimeAsyncJobRepository.php',
    $systemRoot . '/core/Runtime/Queue/RuntimeAsyncJobWorkload.php',
    $systemRoot . '/core/Runtime/Queue/RuntimeMediaImagePipelineCliRunner.php',
    $systemRoot . '/modules/media/repositories/MediaJobRepository.php',
    $systemRoot . '/modules/notifications/repositories/OutboundNotificationMessageRepository.php',
    $systemRoot . '/modules/clients/services/ClientMergeJobService.php',
    $systemRoot . '/modules/notifications/services/OutboundNotificationDispatchService.php',
    $systemRoot . '/core/Storage/StorageProviderFactory.php',
    $systemRoot . '/core/Storage/S3CompatibleObjectStorageProvider.php',
    $systemRoot . '/core/Storage/S3/S3SigV4Signer.php',
    $systemRoot . '/modules/auth/controllers/LoginController.php',
    $systemRoot . '/scripts/worker_runtime_async_jobs_cli_02.php',
    $systemRoot . '/scripts/invalidate_user_sessions_cli_02.php',
];

$readonly = [
    $systemRoot . '/scripts/read-only/verify_session_backend_and_session_epoch_readonly_02.php',
    $systemRoot . '/scripts/read-only/verify_runtime_async_jobs_queue_contract_readonly_02.php',
    $systemRoot . '/scripts/read-only/verify_runtime_async_workload_bridge_readonly_03.php',
    $systemRoot . '/scripts/read-only/verify_storage_driver_factory_truth_readonly_02.php',
];

foreach (array_merge($phpFiles, $readonly) as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "FAIL missing file: {$f}\n");
        exit(1);
    }
}

$php = PHP_BINARY;
foreach ($readonly as $script) {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    passthru($cmd, $code);
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

echo "RUNTIME-DISTRIBUTED-PLANES-02 gate: all steps passed.\n";
