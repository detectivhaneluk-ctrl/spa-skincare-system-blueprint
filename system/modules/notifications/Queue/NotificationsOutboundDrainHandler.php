<?php

declare(strict_types=1);

namespace Modules\Notifications\Queue;

use Core\Runtime\Queue\AsyncJobHandlerInterface;
use Core\Runtime\Queue\RuntimeAsyncJobWorkload;
use Modules\Notifications\Services\OutboundNotificationDispatchService;

/**
 * Async handler for {@see RuntimeAsyncJobWorkload::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH} (PLT-Q-01).
 *
 * Drains a bounded batch of pending outbound notification messages.
 * Payload key "limit" controls batch size (1–200, default 25).
 * Idempotent: running concurrently is safe (SKIP LOCKED claim in dispatch service).
 */
final class NotificationsOutboundDrainHandler implements AsyncJobHandlerInterface
{
    public function __construct(private OutboundNotificationDispatchService $dispatchService)
    {
    }

    public function handle(int $jobId, string $jobType, array $payload): void
    {
        $limit = (int) ($payload['limit'] ?? 25);
        $limit = max(1, min(200, $limit));
        $this->dispatchService->runBatch($limit);
    }
}
