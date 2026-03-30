<?php

declare(strict_types=1);

namespace Core\Runtime\Queue;

/**
 * Canonical queue names + job_type strings for workloads bridged into {@see RuntimeAsyncJobRepository}
 * (FOUNDATION-DISTRIBUTED-RUNTIME-JOB-CONSUMERS-MEDIA-NOTIFY-03).
 */
final class RuntimeAsyncJobWorkload
{
    public const QUEUE_MEDIA = 'media';

    public const QUEUE_NOTIFICATIONS = 'notifications';

    public const QUEUE_DEFAULT = 'default';

    public const JOB_MEDIA_IMAGE_PIPELINE = 'media.image_pipeline';

    public const JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH = 'notifications.outbound_drain_batch';

    public const JOB_CLIENTS_MERGE_EXECUTE = 'clients.merge_execute';

    /** Payload correlation tag for ops / readonly proof (not a security boundary). */
    public const PAYLOAD_SCHEMA = 'FOUNDATION-DISTRIBUTED-RUNTIME-JOB-CONSUMERS-MEDIA-NOTIFY-03';

    /**
     * JSON payload key used to coalesce {@see self::JOB_NOTIFICATIONS_OUTBOUND_DRAIN_BATCH} pending jobs
     * (FOUNDATION-RUNTIME-ASYNC-COALESCE-AND-TRANSACT-04): at most one pending drain job per coalesce key.
     */
    public const PAYLOAD_KEY_DRAIN_COALESCE = 'drain_coalesce_key';

    /**
     * Stable coalesce key: one pending runtime drain job per outbound branch bucket (NULL/invalid branch → "none").
     */
    public static function notificationOutboundDrainCoalesceKey(?int $branchId): string
    {
        if ($branchId !== null && $branchId > 0) {
            return 'outbound_drain:branch:' . $branchId;
        }

        return 'outbound_drain:branch:none';
    }
}
