<?php

declare(strict_types=1);

namespace Modules\OnlineBooking\Services;

use Core\Contracts\SlidingWindowRateLimiterInterface;

/**
 * Sliding-window abuse guard for public booking, commerce, and intake. Backend: {@see SlidingWindowRateLimiterInterface}
 * (MySQL `public_booking_abuse_hits` when Redis is unset; Redis sorted-set + Lua when {@code REDIS_URL} + ext-redis).
 */
final class PublicBookingAbuseGuardService
{
    private const RATE_LIMIT_NAMESPACE = 'public_abuse_v1';

    public function __construct(private SlidingWindowRateLimiterInterface $rateLimiter)
    {
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    public function consume(string $bucket, string $throttleKey, int $maxRequests, int $windowSeconds): array
    {
        return $this->rateLimiter->tryConsume(self::RATE_LIMIT_NAMESPACE, $bucket, $throttleKey, $maxRequests, $windowSeconds);
    }
}
