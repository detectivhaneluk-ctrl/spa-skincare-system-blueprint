<?php

declare(strict_types=1);

/**
 * FOUNDATION-DISTRIBUTED-RUNTIME-JOB-CONSUMERS-MEDIA-NOTIFY-03 — readonly: media / outbound / merge bridged into runtime_async_jobs.
 * FOUNDATION-RUNTIME-ASYNC-COALESCE-AND-TRANSACT-04 — transactional paired enqueue + outbound drain coalescing.
 *
 *   php system/scripts/read-only/verify_runtime_async_workload_bridge_readonly_03.php
 */

$root = dirname(__DIR__, 2);
$repoRoot = dirname($root);
$fail = [];

$workload = $root . '/core/Runtime/Queue/RuntimeAsyncJobWorkload.php';
if (!is_readable($workload)) {
    $fail[] = 'Missing RuntimeAsyncJobWorkload.php';
} else {
    $w = (string) file_get_contents($workload);
    foreach (['JOB_MEDIA_IMAGE_PIPELINE', 'JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH', 'JOB_CLIENTS_MERGE_EXECUTE', 'QUEUE_MEDIA', 'QUEUE_NOTIFICATIONS', 'PAYLOAD_SCHEMA', 'PAYLOAD_KEY_DRAIN_COALESCE', 'notificationOutboundDrainCoalesceKey'] as $n) {
        if (!str_contains($w, $n)) {
            $fail[] = "RuntimeAsyncJobWorkload should define {$n}";
        }
    }
}

$asyncRepo = $root . '/core/Runtime/Queue/RuntimeAsyncJobRepository.php';
if (!is_readable($asyncRepo)) {
    $fail[] = 'Missing RuntimeAsyncJobRepository';
} else {
    $ar = (string) file_get_contents($asyncRepo);
    foreach (['enqueueNotificationsOutboundDrainIfAbsent', 'JSON_EXTRACT', 'FOR UPDATE', 'PAYLOAD_KEY_DRAIN_COALESCE'] as $n) {
        if (!str_contains($ar, $n)) {
            $fail[] = "RuntimeAsyncJobRepository should reference {$n} (coalesced outbound drain)";
        }
    }
}

$runner = $root . '/core/Runtime/Queue/RuntimeMediaImagePipelineCliRunner.php';
if (!is_readable($runner) || !str_contains((string) file_get_contents($runner), 'IMAGE_PIPELINE_FORCE_MEDIA_JOB_ID')) {
    $fail[] = 'RuntimeMediaImagePipelineCliRunner must set IMAGE_PIPELINE_FORCE_MEDIA_JOB_ID when media_job_id is present';
}

$mediaRepo = $root . '/modules/media/repositories/MediaJobRepository.php';
if (!is_readable($mediaRepo)) {
    $fail[] = 'Missing MediaJobRepository';
} else {
    $m = (string) file_get_contents($mediaRepo);
    foreach (['RuntimeAsyncJobRepository', 'JOB_MEDIA_IMAGE_PIPELINE', 'QUEUE_MEDIA', 'media_job_id', '->transaction('] as $n) {
        if (!str_contains($m, $n)) {
            $fail[] = "MediaJobRepository should reference {$n}";
        }
    }
}

$outRepo = $root . '/modules/notifications/repositories/OutboundNotificationMessageRepository.php';
if (!is_readable($outRepo)) {
    $fail[] = 'Missing OutboundNotificationMessageRepository';
} else {
    $o = (string) file_get_contents($outRepo);
    foreach (['RuntimeAsyncJobRepository', 'JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH', 'outbound_notification_message_id', 'enqueueNotificationsOutboundDrainIfAbsent', 'PAYLOAD_KEY_DRAIN_COALESCE', '->transaction('] as $n) {
        if (!str_contains($o, $n)) {
            $fail[] = "OutboundNotificationMessageRepository should reference {$n}";
        }
    }
}

$mergeSvc = $root . '/modules/clients/services/ClientMergeJobService.php';
if (!is_readable($mergeSvc)) {
    $fail[] = 'Missing ClientMergeJobService';
} else {
    $c = (string) file_get_contents($mergeSvc);
    foreach (['claimAndExecuteMergeJobByRuntimeId', 'JOB_CLIENTS_MERGE_EXECUTE', 'QUEUE_DEFAULT', 'RuntimeAsyncJobRepository'] as $n) {
        if (!str_contains($c, $n)) {
            $fail[] = "ClientMergeJobService should reference {$n}";
        }
    }
}

$worker = $root . '/scripts/worker_runtime_async_jobs_cli_02.php';
if (!is_readable($worker)) {
    $fail[] = 'Missing worker_runtime_async_jobs_cli_02.php';
} else {
    $wk = (string) file_get_contents($worker);
    foreach (['JOB_MEDIA_IMAGE_PIPELINE', 'JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH', 'JOB_CLIENTS_MERGE_EXECUTE', 'RuntimeMediaImagePipelineCliRunner'] as $n) {
        if (!str_contains($wk, $n)) {
            $fail[] = "worker_runtime_async_jobs_cli_02.php should reference {$n}";
        }
    }
    if (str_contains($wk, 'unset($payload)')) {
        $fail[] = 'worker_runtime_async_jobs_cli_02.php must not discard payload (unset)';
    }
}

$proc = $repoRoot . '/workers/image-pipeline/src/processor.mjs';
if (!is_readable($proc) || !str_contains((string) file_get_contents($proc), 'RUNTIME_ASYNC_GOVERNANCE_JOB_TYPE')) {
    $fail[] = 'processor.mjs must export RUNTIME_ASYNC_GOVERNANCE_JOB_TYPE';
}
if (!is_readable($proc) || !str_contains((string) file_get_contents($proc), 'forceMediaJobId')) {
    $fail[] = 'processor.mjs claimNextJob must honor forceMediaJobId / IMAGE_PIPELINE bridge';
}

$regMedia = $root . '/modules/bootstrap/register_media.php';
if (!is_readable($regMedia) || !str_contains((string) file_get_contents($regMedia), 'RuntimeAsyncJobRepository::class')) {
    $fail[] = 'register_media.php must inject RuntimeAsyncJobRepository into MediaJobRepository';
}

$regNotes = $root . '/modules/bootstrap/register_appointments_documents_notifications.php';
$regNotesTxt = is_readable($regNotes) ? (string) file_get_contents($regNotes) : '';
if (
    $regNotesTxt === ''
    || !str_contains($regNotesTxt, 'OutboundNotificationMessageRepository')
    || !str_contains($regNotesTxt, 'RuntimeAsyncJobRepository')
    || !preg_match('/OutboundNotificationMessageRepository.*RuntimeAsyncJobRepository::class/s', $regNotesTxt)
) {
    $fail[] = 'register_appointments_documents_notifications.php must inject RuntimeAsyncJobRepository into OutboundNotificationMessageRepository';
}

$regClients = $root . '/modules/bootstrap/register_clients.php';
if (!is_readable($regClients) || !str_contains((string) file_get_contents($regClients), 'ClientMergeJobService') || !str_contains((string) file_get_contents($regClients), 'RuntimeAsyncJobRepository::class')) {
    $fail[] = 'register_clients.php must inject RuntimeAsyncJobRepository into ClientMergeJobService';
}

$node = getenv('NODE_BINARY') ?: (PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node');
foreach (['workers/image-pipeline/src/worker.mjs', 'workers/image-pipeline/src/processor.mjs'] as $rel) {
    $p = $repoRoot . '/' . $rel;
    if (!is_file($p)) {
        $fail[] = "Missing JS file: {$rel}";
        continue;
    }
    $cmd = escapeshellarg($node) . ' --check ' . escapeshellarg($p);
    passthru($cmd, $code);
    if ($code !== 0) {
        $fail[] = "node --check failed ({$rel}) exit {$code}";
    }
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL runtime async workload bridge readonly 03:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_runtime_async_workload_bridge_readonly_03\n";
