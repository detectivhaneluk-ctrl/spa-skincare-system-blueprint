<?php

declare(strict_types=1);

namespace Core\Runtime\RateLimit;

use Core\Contracts\SlidingWindowRateLimiterInterface;

/**
 * Named sliding-window gates for abuse-sensitive HTTP paths (FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02).
 * Backed by {@see SlidingWindowRateLimiterInterface} (Redis when configured, else DB via public_booking_abuse_hits).
 */
final class RuntimeProtectedPathRateLimiter
{
    public const NAMESPACE_V1 = 'rt_prot_v1';

    public const BUCKET_LOGIN_POST = 'login_post';

    public const BUCKET_PLATFORM_MANAGE_POST = 'platform_manage_post';

    /**
     * Booking submission rate bucket — WAVE-04.
     * Limits online booking form submissions per client IP to prevent booking spam/DoS.
     */
    public const BUCKET_BOOKING_SUBMIT = 'booking_submit';

    /**
     * Booking availability read rate bucket — WAVE-04.
     * Limits availability slot reads per IP to prevent slot-polling scraping.
     */
    public const BUCKET_BOOKING_AVAILABILITY_READ = 'booking_avail_read';

    public function __construct(private SlidingWindowRateLimiterInterface $inner)
    {
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    public function tryConsumeLoginPost(string $clientIp): array
    {
        $ip = trim($clientIp);
        if ($ip === '') {
            $ip = '0.0.0.0';
        }

        return $this->inner->tryConsume(self::NAMESPACE_V1, self::BUCKET_LOGIN_POST, $ip, 60, 300);
    }

    /**
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    public function tryConsumePlatformManagePost(int $userId): array
    {
        if ($userId <= 0) {
            return ['ok' => true];
        }

        return $this->inner->tryConsume(self::NAMESPACE_V1, self::BUCKET_PLATFORM_MANAGE_POST, (string) $userId, 180, 60);
    }

    /**
     * Consume one token for an online booking submission from the given client IP.
     *
     * Limits: 20 submissions per IP per 10 minutes (600 seconds).
     * Prevents booking-form spam and bot-driven slot exhaustion.
     *
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    public function tryConsumeBookingSubmit(string $clientIp): array
    {
        $ip = trim($clientIp);
        if ($ip === '') {
            $ip = '0.0.0.0';
        }

        return $this->inner->tryConsume(self::NAMESPACE_V1, self::BUCKET_BOOKING_SUBMIT, $ip, 20, 600);
    }

    /**
     * Consume one token for a booking availability read from the given client IP.
     *
     * Limits: 120 reads per IP per 60 seconds.
     * Allows normal calendar browsing while blocking high-frequency slot-polling scrapers.
     *
     * @return array{ok: true}|array{ok: false, retry_after: int}
     */
    public function tryConsumeBookingAvailabilityRead(string $clientIp): array
    {
        $ip = trim($clientIp);
        if ($ip === '') {
            $ip = '0.0.0.0';
        }

        return $this->inner->tryConsume(self::NAMESPACE_V1, self::BUCKET_BOOKING_AVAILABILITY_READ, $ip, 120, 60);
    }
}
