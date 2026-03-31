<?php

declare(strict_types=1);

namespace Modules\Clients\Queue;

use Core\Runtime\Queue\AsyncJobHandlerInterface;
use Core\Runtime\Queue\RuntimeAsyncJobWorkload;
use Modules\Clients\Services\ClientMergeJobService;

/**
 * Async handler for {@see RuntimeAsyncJobWorkload::JOB_CLIENTS_MERGE_EXECUTE} (PLT-Q-01).
 *
 * Dispatches to ClientMergeJobService::claimAndExecuteMergeJobByRuntimeId(),
 * which atomically claims the domain-level job row and executes the merge.
 * Idempotent: if the row is already running/succeeded/failed the service no-ops.
 */
final class ClientMergeExecuteHandler implements AsyncJobHandlerInterface
{
    public function __construct(private ClientMergeJobService $mergeJobService)
    {
    }

    public function handle(int $jobId, string $jobType, array $payload): void
    {
        $mergeId = (int) ($payload['client_merge_job_id'] ?? 0);
        if ($mergeId <= 0) {
            throw new \RuntimeException(
                RuntimeAsyncJobWorkload::JOB_CLIENTS_MERGE_EXECUTE . ' requires positive client_merge_job_id in payload'
            );
        }
        $this->mergeJobService->claimAndExecuteMergeJobByRuntimeId($mergeId);
    }
}
