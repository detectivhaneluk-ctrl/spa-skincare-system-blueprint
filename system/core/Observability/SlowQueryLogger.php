<?php

declare(strict_types=1);

namespace Core\Observability;

use Core\Kernel\RequestContextHolder;

/**
 * Slow query logger — WAVE-03 tenant-aware observability.
 *
 * Emits a structured log entry (via slog) when a database query exceeds
 * {@see $thresholdMs}. The entry includes tenant context (organization_id,
 * branch_id, actor_id) when a resolved TenantContext is available, enabling
 * per-tenant slow query correlation.
 *
 * Usage: call {@see Database::setSlowQueryLogger()} at bootstrap or in the
 * request context after TenantContext is resolved.
 *
 * Threshold: configurable via constructor. Default 500 ms (production tunable).
 * Set to 0 to log ALL queries (only for development profiling).
 */
final class SlowQueryLogger
{
    public function __construct(
        private readonly float $thresholdMs,
        private readonly ?RequestContextHolder $contextHolder = null
    ) {
    }

    /**
     * @param array<mixed> $params
     */
    public function observe(string $sql, array $params, float $elapsedMs): void
    {
        if ($elapsedMs < $this->thresholdMs) {
            return;
        }

        if (!function_exists('slog')) {
            return;
        }

        $context = [
            'elapsed_ms' => round($elapsedMs, 2),
            'threshold_ms' => $this->thresholdMs,
            'sql_prefix' => substr(trim($sql), 0, 200),
        ];

        // Enrich with tenant context if available.
        if ($this->contextHolder !== null) {
            try {
                $ctx = $this->contextHolder->get();
                if ($ctx !== null) {
                    $context['organization_id'] = $ctx->organizationId;
                    $context['branch_id'] = $ctx->branchId;
                    $context['actor_id'] = $ctx->actorId;
                }
            } catch (\Throwable) {
                // Best-effort — never suppress the original query work.
            }
        }

        \slog('warning', 'slow_query', 'query_exceeded_threshold', $context);
    }
}
