<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Kernel\RequestContextHolder;

/**
 * Request latency logger — WAVE-03 observability foundation.
 *
 * Wraps every HTTP request in a timer. Emits a structured slog entry
 * (channel: `endpoint_latency`) when the end-to-end request duration
 * exceeds {@see $thresholdMs}.
 *
 * Includes tenant context (organization_id, branch_id, actor_id) when a
 * resolved TenantContext is available, enabling per-tenant latency correlation.
 *
 * Registration: add to the global middleware pipeline BEFORE ErrorHandlerMiddleware
 * so it captures the full round-trip including error handling.
 *
 * Note: does NOT buffer or aggregate metrics — emits one log line per slow request.
 * For aggregated metrics export (Prometheus, DataDog, etc.) wire an APM agent on top
 * of these log lines (WAVE-03 groundwork; full APM is a separate WAVE-03+ task).
 */
final class RequestLatencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContextHolder $contextHolder,
        private readonly float $thresholdMs = 1000.0
    ) {
    }

    public function handle(callable $next): void
    {
        $start = microtime(true);

        $next();

        $elapsedMs = (microtime(true) - $start) * 1000.0;

        if ($elapsedMs < $this->thresholdMs) {
            return;
        }

        if (!function_exists('slog')) {
            return;
        }

        $context = [
            'elapsed_ms' => round($elapsedMs, 2),
            'threshold_ms' => $this->thresholdMs,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 200) : 'UNKNOWN',
        ];

        try {
            $ctx = $this->contextHolder->get();
            if ($ctx !== null) {
                $context['organization_id'] = $ctx->organizationId;
                $context['branch_id'] = $ctx->branchId;
                $context['actor_id'] = $ctx->actorId;
            }
        } catch (\Throwable) {
            // Best-effort tenant enrichment.
        }

        \slog('warning', 'endpoint_latency', 'request_exceeded_threshold', $context);
    }
}
