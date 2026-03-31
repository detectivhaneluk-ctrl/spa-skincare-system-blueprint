<?php

declare(strict_types=1);

/**
 * Observability configuration — WAVE-03.
 *
 * Controls slow query logging and slow request latency logging thresholds.
 * Both are slog-emitting only (no external metrics sink required at this stage).
 *
 * To integrate with an APM (DataDog, OpenTelemetry, etc.), configure an slog
 * output transport that forwards to the APM API instead of (or in addition to) STDERR.
 */
return [
    /**
     * Queries executing slower than this threshold (in milliseconds) are logged
     * as slog 'warning' / channel 'slow_query' with tenant context.
     * Set to 0 to log ALL queries (development profiling only — never use in production).
     */
    'slow_query_threshold_ms' => (float) env('SLOW_QUERY_THRESHOLD_MS', 500),

    /**
     * HTTP requests taking longer than this threshold (in milliseconds) are logged
     * as slog 'warning' / channel 'endpoint_latency' with method, URI, and tenant context.
     */
    'slow_request_threshold_ms' => (float) env('SLOW_REQUEST_THRESHOLD_MS', 1000),
];
