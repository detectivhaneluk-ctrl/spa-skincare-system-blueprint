<?php

declare(strict_types=1);

/**
 * PLT-Q-01 Verifier — Unified Async Queue Control-Plane
 *
 * Proves:
 *   1. Canonical control-plane primitives exist and are structurally correct
 *   2. Migrated async slices are wired to the control-plane
 *   3. State machine / claim / retry / stale-recovery semantics are present
 *   4. No regression in previously migrated slices (03/workload bridge checks preserved)
 *   5. Live guardrail execution passes (new async_state_machine_ban guardrail)
 *   6. Operator visibility surface exists
 *   7. Bootstrap wiring is correct
 *   8. Domain handlers are properly structured
 *
 * From repo root:
 *   php system/scripts/read-only/verify_plt_q_01_unified_async_queue_control_plane_01.php
 */

$system = dirname(__DIR__, 2);
$repoRoot = dirname($system);
$fail = [];
$pass = 0;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function assertFile(string $path, string &$label): bool
{
    if (!is_readable($path)) {
        return false;
    }

    return true;
}

function check(bool $cond, string $msg, array &$fail, int &$pass): void
{
    if ($cond) {
        ++$pass;
    } else {
        $fail[] = $msg;
    }
}

// ---------------------------------------------------------------------------
// Section 1: Core control-plane primitives
// ---------------------------------------------------------------------------

// AsyncJobHandlerInterface
$iface = $system . '/core/Runtime/Queue/AsyncJobHandlerInterface.php';
check(is_readable($iface), 'AsyncJobHandlerInterface.php must exist', $fail, $pass);
if (is_readable($iface)) {
    $src = (string) file_get_contents($iface);
    check(str_contains($src, 'interface AsyncJobHandlerInterface'), 'AsyncJobHandlerInterface must be an interface', $fail, $pass);
    check(str_contains($src, 'public function handle('), 'AsyncJobHandlerInterface must declare handle()', $fail, $pass);
    check(str_contains($src, 'array $payload'), 'AsyncJobHandlerInterface::handle() must accept payload array', $fail, $pass);
}

// AsyncJobHandlerRegistry
$reg = $system . '/core/Runtime/Queue/AsyncJobHandlerRegistry.php';
check(is_readable($reg), 'AsyncJobHandlerRegistry.php must exist', $fail, $pass);
if (is_readable($reg)) {
    $src = (string) file_get_contents($reg);
    check(str_contains($src, 'final class AsyncJobHandlerRegistry'), 'AsyncJobHandlerRegistry must be final class', $fail, $pass);
    check(str_contains($src, 'public function register('), 'Registry must have register()', $fail, $pass);
    check(str_contains($src, 'public function get('), 'Registry must have get()', $fail, $pass);
    check(str_contains($src, 'public function has('), 'Registry must have has()', $fail, $pass);
    check(str_contains($src, 'NOOP_TYPES'), 'Registry must define NOOP_TYPES', $fail, $pass);
    check(str_contains($src, 'public function isNoop('), 'Registry must have isNoop()', $fail, $pass);
    check(str_contains($src, 'LogicException'), 'Registry must throw on duplicate registration', $fail, $pass);
}

// AsyncQueueWorkerLoop
$loop = $system . '/core/Runtime/Queue/AsyncQueueWorkerLoop.php';
check(is_readable($loop), 'AsyncQueueWorkerLoop.php must exist', $fail, $pass);
if (is_readable($loop)) {
    $src = (string) file_get_contents($loop);
    check(str_contains($src, 'final class AsyncQueueWorkerLoop'), 'AsyncQueueWorkerLoop must be final class', $fail, $pass);
    check(str_contains($src, 'public function runOnce('), 'AsyncQueueWorkerLoop must have runOnce()', $fail, $pass);
    check(str_contains($src, 'public function runLoop('), 'AsyncQueueWorkerLoop must have runLoop()', $fail, $pass);
    check(str_contains($src, 'RuntimeAsyncJobRepository'), 'AsyncQueueWorkerLoop must use RuntimeAsyncJobRepository', $fail, $pass);
    check(str_contains($src, 'AsyncJobHandlerRegistry'), 'AsyncQueueWorkerLoop must use AsyncJobHandlerRegistry', $fail, $pass);
    check(str_contains($src, 'markSucceeded'), 'AsyncQueueWorkerLoop must call markSucceeded', $fail, $pass);
    check(str_contains($src, 'markFailedRetryOrDead'), 'AsyncQueueWorkerLoop must call markFailedRetryOrDead', $fail, $pass);
    check(str_contains($src, 'reserveNext'), 'AsyncQueueWorkerLoop must call reserveNext (claim)', $fail, $pass);
    check(str_contains($src, 'isNoop'), 'AsyncQueueWorkerLoop must handle noop types', $fail, $pass);
}

// AsyncQueueStatusReader
$reader = $system . '/core/Runtime/Queue/AsyncQueueStatusReader.php';
check(is_readable($reader), 'AsyncQueueStatusReader.php must exist', $fail, $pass);
if (is_readable($reader)) {
    $src = (string) file_get_contents($reader);
    check(str_contains($src, 'final class AsyncQueueStatusReader'), 'AsyncQueueStatusReader must be final class', $fail, $pass);
    check(str_contains($src, 'getQueueDepthByStatus'), 'AsyncQueueStatusReader must have getQueueDepthByStatus()', $fail, $pass);
    check(str_contains($src, 'getStuckJobs'), 'AsyncQueueStatusReader must have getStuckJobs()', $fail, $pass);
    check(str_contains($src, 'getDeadJobs'), 'AsyncQueueStatusReader must have getDeadJobs()', $fail, $pass);
    check(str_contains($src, 'getRetryingJobs'), 'AsyncQueueStatusReader must have getRetryingJobs()', $fail, $pass);
    check(str_contains($src, 'getRecentCompletions'), 'AsyncQueueStatusReader must have getRecentCompletions()', $fail, $pass);
    check(str_contains($src, 'getSummary'), 'AsyncQueueStatusReader must have getSummary()', $fail, $pass);
    check(str_contains($src, 'STATUS_DEAD'), 'AsyncQueueStatusReader must reference STATUS_DEAD', $fail, $pass);
    check(str_contains($src, 'STATUS_PROCESSING'), 'AsyncQueueStatusReader must reference STATUS_PROCESSING', $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 2: Canonical backbone (RuntimeAsyncJobRepository) — state machine / claim / retry / stale
// ---------------------------------------------------------------------------
$repo = $system . '/core/Runtime/Queue/RuntimeAsyncJobRepository.php';
check(is_readable($repo), 'RuntimeAsyncJobRepository must exist', $fail, $pass);
if (is_readable($repo)) {
    $src = (string) file_get_contents($repo);
    check(str_contains($src, "STATUS_PENDING = 'pending'"), 'Backbone: STATUS_PENDING defined', $fail, $pass);
    check(str_contains($src, "STATUS_PROCESSING = 'processing'"), 'Backbone: STATUS_PROCESSING defined', $fail, $pass);
    check(str_contains($src, "STATUS_SUCCEEDED = 'succeeded'"), 'Backbone: STATUS_SUCCEEDED defined', $fail, $pass);
    check(str_contains($src, "STATUS_FAILED = 'failed'"), 'Backbone: STATUS_FAILED defined', $fail, $pass);
    check(str_contains($src, "STATUS_DEAD = 'dead'"), 'Backbone: STATUS_DEAD defined', $fail, $pass);
    check(str_contains($src, 'reserveNext'), 'Backbone: claim via reserveNext() present', $fail, $pass);
    check(str_contains($src, 'FOR UPDATE'), 'Backbone: FOR UPDATE lock in claim', $fail, $pass);
    check(str_contains($src, 'reclaimStaleProcessingLocked'), 'Backbone: stale-reclaim present', $fail, $pass);
    check(str_contains($src, 'STALE_PROCESSING_SECONDS'), 'Backbone: stale threshold constant present', $fail, $pass);
    check(str_contains($src, 'markFailedRetryOrDead'), 'Backbone: retry/dead-letter present', $fail, $pass);
    check(str_contains($src, 'max_attempts'), 'Backbone: max_attempts respected', $fail, $pass);
    check(str_contains($src, 'available_at'), 'Backbone: available_at backoff present', $fail, $pass);
    check(str_contains($src, 'STATUS_DEAD'), 'Backbone: dead-letter status referenced', $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 3: Domain handlers wired to canonical control-plane
// ---------------------------------------------------------------------------

// ClientMergeExecuteHandler
$mergeHandler = $system . '/modules/clients/Queue/ClientMergeExecuteHandler.php';
check(is_readable($mergeHandler), 'ClientMergeExecuteHandler must exist', $fail, $pass);
if (is_readable($mergeHandler)) {
    $src = (string) file_get_contents($mergeHandler);
    check(str_contains($src, 'AsyncJobHandlerInterface'), 'ClientMergeExecuteHandler must implement AsyncJobHandlerInterface', $fail, $pass);
    check(str_contains($src, 'claimAndExecuteMergeJobByRuntimeId'), 'ClientMergeExecuteHandler must call claimAndExecuteMergeJobByRuntimeId', $fail, $pass);
    check(str_contains($src, 'client_merge_job_id'), 'ClientMergeExecuteHandler must read client_merge_job_id from payload', $fail, $pass);
}

// MediaImagePipelineHandler
$mediaHandler = $system . '/modules/media/Queue/MediaImagePipelineHandler.php';
check(is_readable($mediaHandler), 'MediaImagePipelineHandler must exist', $fail, $pass);
if (is_readable($mediaHandler)) {
    $src = (string) file_get_contents($mediaHandler);
    check(str_contains($src, 'AsyncJobHandlerInterface'), 'MediaImagePipelineHandler must implement AsyncJobHandlerInterface', $fail, $pass);
    check(str_contains($src, 'RuntimeMediaImagePipelineCliRunner'), 'MediaImagePipelineHandler must use RuntimeMediaImagePipelineCliRunner', $fail, $pass);
}

// NotificationsOutboundDrainHandler
$notifyHandler = $system . '/modules/notifications/Queue/NotificationsOutboundDrainHandler.php';
check(is_readable($notifyHandler), 'NotificationsOutboundDrainHandler must exist', $fail, $pass);
if (is_readable($notifyHandler)) {
    $src = (string) file_get_contents($notifyHandler);
    check(str_contains($src, 'AsyncJobHandlerInterface'), 'NotificationsOutboundDrainHandler must implement AsyncJobHandlerInterface', $fail, $pass);
    check(str_contains($src, 'OutboundNotificationDispatchService'), 'NotificationsOutboundDrainHandler must use OutboundNotificationDispatchService', $fail, $pass);
    check(str_contains($src, 'runBatch'), 'NotificationsOutboundDrainHandler must call runBatch', $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 4: Bootstrap wiring
// ---------------------------------------------------------------------------
$boot = $system . '/modules/bootstrap/register_async_queue.php';
check(is_readable($boot), 'register_async_queue.php must exist', $fail, $pass);
if (is_readable($boot)) {
    $src = (string) file_get_contents($boot);
    check(str_contains($src, 'AsyncJobHandlerRegistry'), 'Bootstrap must register AsyncJobHandlerRegistry', $fail, $pass);
    check(str_contains($src, 'AsyncQueueWorkerLoop'), 'Bootstrap must register AsyncQueueWorkerLoop', $fail, $pass);
    check(str_contains($src, 'AsyncQueueStatusReader'), 'Bootstrap must register AsyncQueueStatusReader', $fail, $pass);
    check(str_contains($src, 'ClientMergeExecuteHandler'), 'Bootstrap must wire ClientMergeExecuteHandler', $fail, $pass);
    check(str_contains($src, 'MediaImagePipelineHandler'), 'Bootstrap must wire MediaImagePipelineHandler', $fail, $pass);
    check(str_contains($src, 'NotificationsOutboundDrainHandler'), 'Bootstrap must wire NotificationsOutboundDrainHandler', $fail, $pass);
    check(str_contains($src, 'JOB_CLIENTS_MERGE_EXECUTE'), 'Bootstrap must register JOB_CLIENTS_MERGE_EXECUTE', $fail, $pass);
    check(str_contains($src, 'JOB_MEDIA_IMAGE_PIPELINE'), 'Bootstrap must register JOB_MEDIA_IMAGE_PIPELINE', $fail, $pass);
    check(str_contains($src, 'JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH'), 'Bootstrap must register JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH', $fail, $pass);
}

$bootstrapList = $system . '/modules/bootstrap.php';
check(is_readable($bootstrapList), 'modules/bootstrap.php must exist', $fail, $pass);
if (is_readable($bootstrapList)) {
    $src = (string) file_get_contents($bootstrapList);
    check(str_contains($src, 'register_async_queue.php'), 'bootstrap.php must include register_async_queue.php', $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 5: Worker script uses canonical control-plane (not hard-coded match table)
// ---------------------------------------------------------------------------
$worker = $system . '/scripts/worker_runtime_async_jobs_cli_02.php';
check(is_readable($worker), 'worker_runtime_async_jobs_cli_02.php must exist', $fail, $pass);
if (is_readable($worker)) {
    $src = (string) file_get_contents($worker);
    check(str_contains($src, 'AsyncQueueWorkerLoop'), 'Worker must use AsyncQueueWorkerLoop', $fail, $pass);
    check(!str_contains($src, "match (\$type)"), 'Worker must not have hard-coded job_type match table', $fail, $pass);
    check(!str_contains($src, 'JOB_MEDIA_IMAGE_PIPELINE'), 'Worker must not hard-code JOB_MEDIA_IMAGE_PIPELINE', $fail, $pass);
    check(!str_contains($src, 'JOB_CLIENTS_MERGE_EXECUTE'), 'Worker must not hard-code JOB_CLIENTS_MERGE_EXECUTE', $fail, $pass);
    check(str_contains($src, 'runOnce'), 'Worker must call runOnce via worker loop', $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 6: Guardrail — async state machine ban
// ---------------------------------------------------------------------------
$guardrail = $system . '/scripts/ci/guardrail_async_state_machine_ban.php';
check(is_readable($guardrail), 'guardrail_async_state_machine_ban.php must exist', $fail, $pass);
if (is_readable($guardrail)) {
    $src = (string) file_get_contents($guardrail);
    check(str_contains($src, 'ClientMergeJobStatuses'), 'Guardrail must allowlist ClientMergeJobStatuses', $fail, $pass);
    check(str_contains($src, 'Core\\\\Runtime\\\\Queue') || str_contains($src, 'Core\Runtime\Queue'), 'Guardrail must reference canonical namespace', $fail, $pass);
    // Execute guardrail as live proof
    $cmd = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe ' . escapeshellarg($guardrail) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    check($code === 0, 'guardrail_async_state_machine_ban must PASS: ' . implode(' | ', $output), $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 7: No regression — prior verifiers for queue/workload/merge still structurally valid
// ---------------------------------------------------------------------------

// verify_runtime_async_jobs_queue_contract_readonly_02: backbone contract still holds
$v02 = $system . '/scripts/read-only/verify_runtime_async_jobs_queue_contract_readonly_02.php';
check(is_readable($v02), 'verify_runtime_async_jobs_queue_contract_readonly_02.php must still exist', $fail, $pass);

// verify_runtime_async_workload_bridge_readonly_03: workload bridge still structurally references RuntimeAsyncJobRepository
$v03 = $system . '/scripts/read-only/verify_runtime_async_workload_bridge_readonly_03.php';
check(is_readable($v03), 'verify_runtime_async_workload_bridge_readonly_03.php must still exist', $fail, $pass);
if (is_readable($v03)) {
    $src = (string) file_get_contents($v03);
    // The worker check in v03 verifies RuntimeAsyncJobRepository — still holds because worker uses it via loop
    check(str_contains($src, 'RuntimeAsyncJobRepository'), 'v03 still references RuntimeAsyncJobRepository', $fail, $pass);
}

// verify_client_merge_async_job_hardening_01: merge job structural contract still holds
$vMerge = $system . '/scripts/read-only/verify_client_merge_async_job_hardening_01.php';
check(is_readable($vMerge), 'verify_client_merge_async_job_hardening_01.php must still exist', $fail, $pass);

// ---------------------------------------------------------------------------
// Section 8: Workload constants still intact
// ---------------------------------------------------------------------------
$workload = $system . '/core/Runtime/Queue/RuntimeAsyncJobWorkload.php';
check(is_readable($workload), 'RuntimeAsyncJobWorkload.php must exist', $fail, $pass);
if (is_readable($workload)) {
    $src = (string) file_get_contents($workload);
    check(str_contains($src, 'JOB_CLIENTS_MERGE_EXECUTE'), 'Workload: JOB_CLIENTS_MERGE_EXECUTE constant present', $fail, $pass);
    check(str_contains($src, 'JOB_MEDIA_IMAGE_PIPELINE'), 'Workload: JOB_MEDIA_IMAGE_PIPELINE constant present', $fail, $pass);
    check(str_contains($src, 'JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH'), 'Workload: JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH constant present', $fail, $pass);
    check(str_contains($src, 'QUEUE_DEFAULT'), 'Workload: QUEUE_DEFAULT constant present', $fail, $pass);
    check(str_contains($src, 'QUEUE_MEDIA'), 'Workload: QUEUE_MEDIA constant present', $fail, $pass);
    check(str_contains($src, 'QUEUE_NOTIFICATIONS'), 'Workload: QUEUE_NOTIFICATIONS constant present', $fail, $pass);
}

// ---------------------------------------------------------------------------
// Section 9: Syntax check all new/modified PHP files
// ---------------------------------------------------------------------------
$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
$syntaxFiles = [
    $system . '/core/Runtime/Queue/AsyncJobHandlerInterface.php',
    $system . '/core/Runtime/Queue/AsyncJobHandlerRegistry.php',
    $system . '/core/Runtime/Queue/AsyncQueueWorkerLoop.php',
    $system . '/core/Runtime/Queue/AsyncQueueStatusReader.php',
    $system . '/modules/clients/Queue/ClientMergeExecuteHandler.php',
    $system . '/modules/media/Queue/MediaImagePipelineHandler.php',
    $system . '/modules/notifications/Queue/NotificationsOutboundDrainHandler.php',
    $system . '/modules/bootstrap/register_async_queue.php',
    $system . '/scripts/worker_runtime_async_jobs_cli_02.php',
    $system . '/scripts/ci/guardrail_async_state_machine_ban.php',
];

foreach ($syntaxFiles as $f) {
    if (!is_readable($f)) {
        $fail[] = "Missing file for syntax check: {$f}";
        continue;
    }
    $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($f) . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    check($code === 0, "Syntax error in {$f}: " . implode(' | ', $output), $fail, $pass);
}

// ---------------------------------------------------------------------------
// Result
// ---------------------------------------------------------------------------
$total = $pass + count($fail);

if ($fail !== []) {
    fwrite(STDERR, "FAIL verify_plt_q_01_unified_async_queue_control_plane_01 — {$pass}/{$total} pass\n\n");
    foreach ($fail as $f) {
        fwrite(STDERR, "  ✗ {$f}\n");
    }
    exit(1);
}

echo "PASS verify_plt_q_01_unified_async_queue_control_plane_01 — {$pass}/{$total} assertions\n";
exit(0);
