<?php

declare(strict_types=1);

namespace Core\Runtime\Redis;

use Core\Contracts\DistributedLockInterface;
use Redis;

/**
 * Redis-backed distributed lock using the standard SET NX PX pattern.
 *
 * Key format: {prefix}:lock:{key}
 * Value:      random token (ensures only the owner can release; prevents thundering-herd on stale key)
 *
 * Fail-closed contract: any Redis exception returns false from tryAcquire so callers
 * skip the protected work rather than proceeding unguarded.
 *
 * NOT a Redlock multi-server implementation. Suitable for single-primary Redis deployments.
 * For multi-primary HA with Redis Sentinel or Cluster, extend to Redlock algorithm.
 */
final class RedisDistributedLock implements DistributedLockInterface
{
    /** @var array<string, string> token keyed by lock name */
    private array $tokens = [];

    public function __construct(
        private readonly Redis $redis,
        private readonly string $keyPrefix = 'spa'
    ) {
    }

    public function tryAcquire(string $key, int $ttlSeconds = 30): bool
    {
        if ($key === '') {
            return false;
        }
        $redisKey = $this->redisKey($key);
        $token = bin2hex(random_bytes(16));
        try {
            $result = $this->redis->set(
                $redisKey,
                $token,
                ['NX', 'PX' => $ttlSeconds * 1000]
            );
            if ($result === true) {
                $this->tokens[$key] = $token;
                return true;
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function release(string $key): void
    {
        if ($key === '' || !isset($this->tokens[$key])) {
            return;
        }
        $redisKey = $this->redisKey($key);
        $token = $this->tokens[$key];
        unset($this->tokens[$key]);

        try {
            // Atomic check-and-delete via Lua: only delete if we still own the key.
            $lua = <<<'LUA'
                if redis.call("GET", KEYS[1]) == ARGV[1] then
                    return redis.call("DEL", KEYS[1])
                else
                    return 0
                end
                LUA;
            $this->redis->eval($lua, [$redisKey, $token], 1);
        } catch (\Throwable) {
            // Best-effort release — suppress all errors.
        }
    }

    private function redisKey(string $key): string
    {
        return $this->keyPrefix . ':lock:' . $key;
    }
}
