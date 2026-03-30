<?php

declare(strict_types=1);

namespace Core\Runtime\RateLimit;

use Core\Contracts\SlidingWindowRateLimiterInterface;
use Modules\OnlineBooking\Repositories\PublicBookingAbuseGuardRepository;

/**
 * MySQL + GET_LOCK sliding window (legacy {@code public_booking_abuse_hits}). Namespace is ignored for storage compatibility.
 */
final class DatabaseSlidingWindowRateLimiter implements SlidingWindowRateLimiterInterface
{
    private const PRUNE_RETENTION_FLOOR_SECONDS = 3600;

    private const THROTTLE_LOCK_PREFIX = 'pbab';

    private const THROTTLE_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(private PublicBookingAbuseGuardRepository $repo)
    {
    }

    public function tryConsume(string $namespace, string $bucket, string $throttleKey, int $maxRequests, int $windowSeconds): array
    {
        unset($namespace);
        $normalizedKey = hash('sha256', $bucket . "\0" . $throttleKey);
        $lockName = self::THROTTLE_LOCK_PREFIX . md5($bucket . "\0" . $normalizedKey);
        if (!$this->repo->acquireThrottleLock($lockName, self::THROTTLE_LOCK_TIMEOUT_SECONDS)) {
            return ['ok' => false, 'retry_after' => 1];
        }
        try {
            $this->repo->pruneExpired(max($windowSeconds, self::PRUNE_RETENTION_FLOOR_SECONDS));
            $stats = $this->repo->getWindowStats($bucket, $normalizedKey, $windowSeconds);

            if ($stats['count'] >= $maxRequests) {
                $now = time();
                $oldest = $stats['oldest_unix'] ?? $now;
                $retryAfter = max(1, ($oldest + $windowSeconds) - $now);

                return ['ok' => false, 'retry_after' => $retryAfter];
            }

            $this->repo->addHit($bucket, $normalizedKey);

            return ['ok' => true];
        } finally {
            $this->repo->releaseThrottleLock($lockName);
        }
    }
}
