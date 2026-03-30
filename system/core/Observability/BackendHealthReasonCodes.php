<?php

declare(strict_types=1);

namespace Core\Observability;

/**
 * Stable machine-readable reason codes for alerting and dashboards.
 * Prefix by layer in docs; codes are unique where possible.
 */
final class BackendHealthReasonCodes
{
    public const SESSION_REDIS_MISCONFIGURED = 'SESSION_REDIS_MISCONFIGURED';

    public const SESSION_REDIS_UNREACHABLE = 'SESSION_REDIS_UNREACHABLE';

    public const STORAGE_PROVIDER_INIT_FAILED = 'STORAGE_PROVIDER_INIT_FAILED';

    public const REGISTRY_TABLE_MISSING = 'REGISTRY_TABLE_MISSING';

    public const REGISTRY_EXCLUSIVE_SLOT_STALE = 'REGISTRY_EXCLUSIVE_SLOT_STALE';

    public const REGISTRY_RECENT_FAILURE = 'REGISTRY_RECENT_FAILURE';

    public const IMAGE_MEDIA_TABLES_MISSING = 'IMAGE_MEDIA_TABLES_MISSING';

    public const IMAGE_STALE_PROCESSING_JOBS = 'IMAGE_STALE_PROCESSING_JOBS';

    public const IMAGE_BACKLOG_STALE_WORKER_HEARTBEAT = 'IMAGE_BACKLOG_STALE_WORKER_HEARTBEAT';

    public const SHARED_CACHE_REDIS_CONFIGURED_NOT_EFFECTIVE = 'SHARED_CACHE_REDIS_CONFIGURED_NOT_EFFECTIVE';

    public const SHARED_CACHE_PRODUCTION_REDIS_REQUIRED = 'SHARED_CACHE_PRODUCTION_REDIS_REQUIRED';

    /** Structured log {@see StructuredLogger} event when consolidated health is not healthy. */
    public const LOG_EVENT_BACKEND_HEALTH_ISSUE = 'observability.backend_health.issue_v1';
}
