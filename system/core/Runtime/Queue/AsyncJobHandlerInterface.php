<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

/**
 * Canonical contract for all async job handlers (PLT-Q-01).
 *
 * A handler receives a claimed job and executes its work to completion.
 * The worker loop calls handle() only after a successful claim; it is responsible
 * for calling markSucceeded() or markFailedRetryOrDead() on the repository.
 *
 * Implementations MUST be idempotent when the same job_id is presented twice
 * (e.g. after a stale-reclaim + re-queue cycle).
 *
 * Implementations MUST throw on unrecoverable failure so the worker loop
 * can invoke the correct retry/dead-letter path.
 *
 * @see AsyncJobHandlerRegistry
 * @see AsyncQueueWorkerLoop
 */
interface AsyncJobHandlerInterface
{
    /**
     * Execute the job.
     *
     * @param int                  $jobId   Row id from runtime_async_jobs
     * @param string               $jobType Registered job_type string
     * @param array<string, mixed> $payload Decoded JSON payload
     *
     * @throws \Throwable on any execution failure; worker loop handles retry/dead-letter
     */
    public function handle(int $jobId, string $jobType, array $payload): void;
}
