<?php

declare(strict_types=1);

namespace Core\Runtime\Redis;

use Redis;

/**
 * Holds a resolved Redis connection (or null if unavailable) and exposes the reason for any failure.
 *
 * Centralises Redis connection lifecycle so that SharedCacheInterface, DistributedLockInterface,
 * and RedisSessionHandler all share the same single connection rather than each opening their own.
 *
 * Resolution states:
 *  - 'redis'              — connected and authenticated successfully
 *  - 'noop'               — REDIS_URL not configured (intentional no-op; allowed in non-production)
 *  - 'redis_extension_missing' — REDIS_URL set but ext-redis not loaded
 *  - 'redis_connect_failed'    — REDIS_URL set and extension loaded, but connect/auth threw
 */
final class RedisConnectionProvider
{
    public function __construct(
        private readonly ?Redis $redis,
        private readonly string $backend = 'noop'
    ) {
    }

    public function redis(): ?Redis
    {
        return $this->redis;
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function isConnected(): bool
    {
        return $this->redis !== null && $this->backend === 'redis';
    }
}
