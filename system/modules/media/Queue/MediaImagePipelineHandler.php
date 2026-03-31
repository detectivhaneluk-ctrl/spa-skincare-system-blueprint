<?php

declare(strict_types=1);

namespace Modules\Media\Queue;

use Core\Runtime\Queue\AsyncJobHandlerInterface;
use Core\Runtime\Queue\RuntimeAsyncJobWorkload;
use Core\Runtime\Queue\RuntimeMediaImagePipelineCliRunner;

/**
 * Async handler for {@see RuntimeAsyncJobWorkload::JOB_MEDIA_IMAGE_PIPELINE} (PLT-Q-01).
 *
 * Bridges the PHP job queue to the Node.js image-pipeline worker process.
 * The media_job_id payload key targets a specific media_jobs row in the Node worker;
 * without it the worker processes the oldest pending media_jobs row.
 */
final class MediaImagePipelineHandler implements AsyncJobHandlerInterface
{
    public function __construct(private string $systemRoot)
    {
    }

    public function handle(int $jobId, string $jobType, array $payload): void
    {
        $mediaJobId = isset($payload['media_job_id']) ? (int) $payload['media_job_id'] : 0;
        RuntimeMediaImagePipelineCliRunner::runOnce(
            $this->systemRoot,
            $mediaJobId > 0 ? $mediaJobId : null
        );
    }
}
