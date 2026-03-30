<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Sliding-window rate limit primitive (public abuse, API throttles). Namespace isolates Redis keys; DB backends may ignore it.
 *
 * @return array{ok: true}|array{ok: false, retry_after: int}
 */
interface SlidingWindowRateLimiterInterface
{
    public function tryConsume(string $namespace, string $bucket, string $throttleKey, int $maxRequests, int $windowSeconds): array;
}
