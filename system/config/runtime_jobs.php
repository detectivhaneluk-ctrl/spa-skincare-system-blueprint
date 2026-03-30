<?php

declare(strict_types=1);

/**
 * Stale thresholds (minutes) for {@see \Core\Runtime\Jobs\RuntimeExecutionRegistry} exclusive runs and worker heartbeat checks.
 * "Stale" means no heartbeat/start activity newer than this window — next run may clear the active slot and record an honest summary.
 */
$defaults = [
    'php:outbound_notifications_dispatch' => max(1, (int) env('RUNTIME_STALE_OUTBOUND_MINUTES', 45)),
    'php:memberships_cron' => max(1, (int) env('RUNTIME_STALE_MEMBERSHIPS_MINUTES', 180)),
    'php:marketing_automations' => max(1, (int) env('RUNTIME_STALE_MARKETING_AUTOMATIONS_MINUTES', 120)),
    'worker:image_pipeline' => max(1, (int) env('RUNTIME_STALE_IMAGE_WORKER_MINUTES', 15)),
];

return [
    'default_stale_minutes' => max(1, (int) env('RUNTIME_EXECUTION_DEFAULT_STALE_MINUTES', 120)),
    /** Prefix {@code php:marketing_automations:} + automation key uses the {@code php:marketing_automations} entry above. */
    'stale_minutes_by_key' => $defaults,
    /**
     * Verifier: warn when pending media_jobs exist but worker heartbeat is older than this (minutes).
     * Should exceed typical WORKER_POLL_MS idle interval.
     */
    'image_worker_backlog_heartbeat_warn_minutes' => max(1, (int) env('RUNTIME_IMAGE_BACKLOG_HEARTBEAT_WARN_MINUTES', 20)),
];
