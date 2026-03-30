<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Lifecycle for {@see \Modules\Clients\Repositories\ClientMergeJobRepository} rows.
 */
final class ClientMergeJobStatuses
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';
}
