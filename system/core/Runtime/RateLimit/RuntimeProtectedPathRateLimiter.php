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
}
