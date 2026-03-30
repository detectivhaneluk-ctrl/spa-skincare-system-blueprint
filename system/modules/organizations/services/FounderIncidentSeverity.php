<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Founder Incident Center: severity labels derived from real impact (not decoration).
 * FOUNDER-OPS-INCIDENT-CENTER-FOUNDATION-01.
 */
final class FounderIncidentSeverity
{
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];
    }

    /**
     * @param list<string> $levels
     */
    public static function maxOf(array $levels): string
    {
        $order = [
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        ];
        $best = self::LOW;
        $bestRank = 0;
        foreach ($levels as $l) {
            $r = $order[$l] ?? 0;
            if ($r > $bestRank) {
                $bestRank = $r;
                $best = $l;
            }
        }

        return $best;
    }
}
