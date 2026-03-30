<?php

declare(strict_types=1);

namespace Core\Runtime\Cache;

use Core\Contracts\SharedCacheInterface;
use Redis;

final class RedisSharedCache implements SharedCacheInterface
{
    public function __construct(
        private Redis $redis,
        private string $keyPrefix
    ) {
    }

    public function get(string $key): ?string
    {
        $k = $this->namespaced($key);
        $v = $this->redis->get($k);

        return $v === false ? null : (string) $v;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $k = $this->namespaced($key);
        $ttlSeconds = max(1, $ttlSeconds);
        $this->redis->setex($k, $ttlSeconds, $value);
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->namespaced($key));
    }

    private function namespaced(string $key): string
    {
        $p = trim($this->keyPrefix, ':');

        return $p . ':sc:v1:' . $key;
    }
}
