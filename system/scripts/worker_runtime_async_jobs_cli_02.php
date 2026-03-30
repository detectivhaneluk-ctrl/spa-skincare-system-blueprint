<?php

declare(strict_types=1);

/**
 * Drains {@see \Core\Runtime\Queue\RuntimeAsyncJobRepository} for a single queue (ops worker).
 *
 * Queues in use (FOUNDATION-DISTRIBUTED-RUNTIME-JOB-CONSUMERS-MEDIA-NOTIFY-03):
 *   default — client merge (`clients.merge_execute`)
 *   media — image pipeline bridge (`media.image_pipeline`)
 *   notifications — outbound drain (`notifications.outbound_drain_batch`)
 *
 * Example (from repo root, after bootstrap env):
 *   php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=default --once
 */

$systemRoot = dirname(__DIR__);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Application;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;
use Core\Runtime\Queue\RuntimeAsyncJobWorkload;
use Core\Runtime\Queue\RuntimeMediaImagePipelineCliRunner;
use Modules\Clients\Services\ClientMergeJobService;
use Modules\Notifications\Services\OutboundNotificationDispatchService;

$queue = 'default';
$once = false;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--queue=')) {
        $queue = trim(substr($arg, strlen('--queue=')));
    }
    if ($arg === '--once') {
        $once = true;
    }
}
if ($queue === '') {
    fwrite(STDERR, "queue required\n");
    exit(1);
}

$repo = Application::container()->get(RuntimeAsyncJobRepository::class);

$process = static function (array $job) use ($repo, $systemRoot): void {
    $id = (int) ($job['id'] ?? 0);
    $type = (string) ($job['job_type'] ?? '');
    $payloadRaw = $job['payload_json'] ?? '{}';
    $payload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : [];
    if (!is_array($payload)) {
        $payload = [];
    }
    try {
        match ($type) {
            'noop', 'media.ping', 'docs.ping', 'notify.ping' => null,
            RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH => (static function () use ($payload): void {
                $limit = (int) ($payload['limit'] ?? 25);
                $limit = max(1, min(200, $limit));
                Application::container()->get(OutboundNotificationDispatchService::class)->runBatch($limit);
            })(),
            RuntimeAsyncJobWorkload::JOB_CLIENTS_MERGE_EXECUTE => (static function () use ($payload): void {
                $mergeId = (int) ($payload['client_merge_job_id'] ?? 0);
                if ($mergeId <= 0) {
                    throw new \RuntimeException('clients.merge_execute requires positive client_merge_job_id');
                }
                Application::container()->get(ClientMergeJobService::class)->claimAndExecuteMergeJobByRuntimeId($mergeId);
            })(),
            RuntimeAsyncJobWorkload::JOB_MEDIA_IMAGE_PIPELINE => (static function () use ($payload, $systemRoot): void {
                $mediaJobId = isset($payload['media_job_id']) ? (int) $payload['media_job_id'] : 0;
                RuntimeMediaImagePipelineCliRunner::runOnce($systemRoot, $mediaJobId > 0 ? $mediaJobId : null);
            })(),
            default => throw new \RuntimeException('Unknown job_type: ' . $type),
        };
        $repo->markSucceeded($id);
        fwrite(STDOUT, "job {$id} {$type} ok\n");
        \slog('info', 'critical_path.queue', 'runtime_job_succeeded', ['job_id' => $id, 'job_type' => $type]);
    } catch (\Throwable $e) {
        $repo->markFailedRetryOrDead($id, $e->getMessage(), 30);
        fwrite(STDERR, "job {$id} {$type} fail: " . $e->getMessage() . "\n");
        \slog('warning', 'critical_path.queue', 'runtime_job_failed', [
            'job_id' => $id,
            'job_type' => $type,
            'error' => $e->getMessage(),
        ]);
    }
};

if ($once) {
    try {
        $job = $repo->reserveNext($queue);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'runtime_async_jobs')) {
            fwrite(STDERR, "runtime_async_jobs table missing; apply migration 124.\n");
            exit(2);
        }
        throw $e;
    }
    if ($job !== null) {
        $process($job);
    }
    exit(0);
}

while (true) {
    try {
        $job = $repo->reserveNext($queue);
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'runtime_async_jobs')) {
            fwrite(STDERR, "runtime_async_jobs table missing; apply migration 124.\n");
            exit(2);
        }
        throw $e;
    }
    if ($job === null) {
        sleep(2);
        continue;
    }
    $process($job);
}
