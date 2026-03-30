<?php

declare(strict_types=1);

namespace Core\Observability;

/**
 * Unified rollup vocabulary for backend readiness probes (FOUNDATION-OBSERVABILITY-AND-ALERTING-01).
 *
 * Exit code discipline (consolidated report script):
 * - 0 = {@see self::HEALTHY}
 * - 1 = {@see self::DEGRADED} (alert-worthy; not necessarily broken config)
 * - 2 = {@see self::FAILED} (misconfiguration or missing critical schema)
 */
final class BackendHealthStatus
{
    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const FAILED = 'failed';

    /**
     * @return self::HEALTHY|self::DEGRADED|self::FAILED
     */
    public static function worst(string $a, string $b): string
    {
        $rank = [self::HEALTHY => 0, self::DEGRADED => 1, self::FAILED => 2];

        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    public static function exitCodeForOverall(string $overall): int
    {
        return match ($overall) {
            self::FAILED => 2,
            self::DEGRADED => 1,
            default => 0,
        };
    }
}
