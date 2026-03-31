<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

/**
 * Canonical worker loop for the unified async queue control-plane (PLT-Q-01).
 *
 * Drains a single named queue from {@see RuntimeAsyncJobRepository} using the
 * registered handlers in {@see AsyncJobHandlerRegistry}.
 *
 * Lifecycle per job:
 *   1. reserveNext() — atomic claim (FOR UPDATE SKIP LOCKED)
 *   2. Handler::handle() — domain execution
 *   3. markSucceeded() on success  |  markFailedRetryOrDead() on exception
 *
 * Stale reclaim (stuck processing rows) is NOT run per-poll. Use the dedicated
 * cron script {@see run_queue_stale_reclaim_cron.php} / {@see RuntimeAsyncJobRepository::reclaimStaleJobs}.
 *
 * No-op smoke types (noop, media.ping, etc.) are silently succeeded.
 * Unknown job_types fail the job so the operator can inspect via dead-letter.
 *
 * @see RuntimeAsyncJobRepository
 * @see AsyncJobHandlerRegistry
 * @see AsyncJobHandlerInterface
 */
final class AsyncQueueWorkerLoop
{
    /**
     * Base backoff in seconds for failed jobs before retry.
     * RuntimeAsyncJobRepository multiplies this by attempt count (linear growth capped at 24 h).
     */
    private const DEFAULT_BASE_BACKOFF_SECONDS = 30;

    public function __construct(
        private RuntimeAsyncJobRepository $repo,
        private AsyncJobHandlerRegistry $registry,
        private int $baseBackoffSeconds = self::DEFAULT_BASE_BACKOFF_SECONDS,
    ) {
    }

    /**
     * Claim and process one job from the queue.
     * Returns true if a job was processed (success or failure), false if the queue is idle.
     */
    public function runOnce(string $queue): bool
    {
        $job = $this->repo->reserveNext($queue);
        if ($job === null) {
            return false;
        }

        $this->dispatch($job);

        return true;
    }

    /**
     * Drain the queue in a loop.
     *
     * When $once is true: process at most one job then return.
     * When $once is false: run forever, sleeping $sleepSeconds between idle polls.
     *
     * @param int $idleSleepSeconds Seconds to sleep when queue is empty (ignored when $once=true)
     */
    public function runLoop(string $queue, bool $once = false, int $idleSleepSeconds = 2): void
    {
        if ($once) {
            $this->runOnce($queue);

            return;
        }

        while (true) {
            $processed = $this->runOnce($queue);
            if (!$processed) {
                sleep(max(1, min(60, $idleSleepSeconds)));
            }
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function dispatch(array $job): void
    {
        $id = (int) ($job['id'] ?? 0);
        $type = (string) ($job['job_type'] ?? '');
        $payloadRaw = $job['payload_json'] ?? '{}';
        $payload = is_string($payloadRaw) ? (json_decode($payloadRaw, true) ?? []) : [];
        if (!is_array($payload)) {
            $payload = [];
        }

        try {
            if ($this->registry->isNoop($type)) {
                $this->repo->markSucceeded($id);
                $this->logInfo($id, $type, 'noop');

                return;
            }

            $handler = $this->registry->get($type);
            if ($handler === null) {
                throw new \RuntimeException('No handler registered for job_type: ' . $type);
            }

            $handler->handle($id, $type, $payload);
            $this->repo->markSucceeded($id);
            $this->logInfo($id, $type, 'succeeded');
        } catch (\Throwable $e) {
            $this->repo->markFailedRetryOrDead($id, $e->getMessage(), $this->baseBackoffSeconds);
            $this->logWarning($id, $type, $e->getMessage());
        }
    }

    private function logInfo(int $jobId, string $jobType, string $outcome): void
    {
        if (function_exists('slog')) {
            \slog('info', 'critical_path.queue', 'runtime_job_' . $outcome, [
                'job_id' => $jobId,
                'job_type' => $jobType,
            ]);
        }
        fwrite(STDOUT, "job {$jobId} {$jobType} {$outcome}\n");
    }

    private function logWarning(int $jobId, string $jobType, string $error): void
    {
        if (function_exists('slog')) {
            \slog('warning', 'critical_path.queue', 'runtime_job_failed', [
                'job_id' => $jobId,
                'job_type' => $jobType,
                'error' => $error,
            ]);
        }
        fwrite(STDERR, "job {$jobId} {$jobType} fail: {$error}\n");
    }
}
