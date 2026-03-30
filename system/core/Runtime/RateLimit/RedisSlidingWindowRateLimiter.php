<?php

declare(strict_types=1);

namespace Core\Runtime\RateLimit;

use Core\Contracts\SlidingWindowRateLimiterInterface;
use Redis;

/**
 * Atomic sliding window via Redis sorted set + Lua (no MySQL row per hit).
 */
final class RedisSlidingWindowRateLimiter implements SlidingWindowRateLimiterInterface
{
    private const LUA = <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
local n = redis.call('ZCARD', KEYS[1])
if n >= tonumber(ARGV[3]) then
  local r = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
  local oldest = tonumber(r[2])
  if oldest == nil then oldest = tonumber(ARGV[2]) end
  local retry = math.max(1, math.floor(oldest + tonumber(ARGV[4]) - tonumber(ARGV[2])))
  return {0, retry}
end
redis.call('ZADD', KEYS[1], ARGV[2], ARGV[5])
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[6]))
return {1, 0}
LUA;

    public function __construct(
        private Redis $redis,
        private string $keyPrefix
    ) {
    }

    public function tryConsume(string $namespace, string $bucket, string $throttleKey, int $maxRequests, int $windowSeconds): array
    {
        $normalizedKey = hash('sha256', $bucket . "\0" . $throttleKey);
        $p = trim($this->keyPrefix, ':');
        $redisKey = $p . ':rl:v1:' . $namespace . ':' . $bucket . ':' . $normalizedKey;
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;
        $member = sprintf('%.6f:%s', $now, bin2hex(random_bytes(8)));
        $expireSec = $windowSeconds + 120;
        $result = $this->redis->eval(
            self::LUA,
            [$redisKey, (string) $windowStart, (string) $now, (string) $maxRequests, (string) $windowSeconds, $member, (string) $expireSec],
            1
        );
        if (!is_array($result) || count($result) < 2) {
            return ['ok' => false, 'retry_after' => 1];
        }
        if ((int) $result[0] === 1) {
            return ['ok' => true];
        }

        return ['ok' => false, 'retry_after' => max(1, (int) $result[1])];
    }
}
