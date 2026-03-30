<?php

declare(strict_types=1);

namespace Modules\Media\Repositories;

use Core\App\Database;
use Core\Runtime\Queue\RuntimeAsyncJobRepository;
use Core\Runtime\Queue\RuntimeAsyncJobWorkload;

final class MediaJobRepository
{
    public function __construct(
        private Database $db,
        private RuntimeAsyncJobRepository $runtimeAsyncJobs,
    ) {
    }

    public function enqueue(int $mediaAssetId, string $jobType): int
    {
        return $this->db->transaction(function () use ($mediaAssetId, $jobType): int {
            $id = $this->db->insert('media_jobs', [
                'media_asset_id' => $mediaAssetId,
                'job_type' => $jobType,
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => date('Y-m-d H:i:s'),
            ]);
            $this->runtimeAsyncJobs->enqueue(
                RuntimeAsyncJobWorkload::QUEUE_MEDIA,
                RuntimeAsyncJobWorkload::JOB_MEDIA_IMAGE_PIPELINE,
                [
                    'media_job_id' => $id,
                    'media_asset_id' => $mediaAssetId,
                    'domain_job_type' => $jobType,
                    'schema' => RuntimeAsyncJobWorkload::PAYLOAD_SCHEMA,
                ],
                5
            );

            return $id;
        });
    }
}
