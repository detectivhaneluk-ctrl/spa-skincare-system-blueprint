<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Operational policy for {@see ClientMergeJobService::reconcileStaleRunningJobs}:
 * a row in {@code running} with {@code started_at} older than this window and no {@code finished_at} is eligible for reconciliation.
 */
final class ClientMergeJobStalePolicy
{
    /** Minutes after {@code started_at} before a running job is treated as stale (worker crash / lost update). */
    public const RUNNING_STALE_MINUTES = 15;
}
