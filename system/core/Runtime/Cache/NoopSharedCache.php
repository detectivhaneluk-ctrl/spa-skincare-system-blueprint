<?php

declare(strict_types=1);

namespace Core\Runtime\Cache;

use Core\Contracts\SharedCacheInterface;

final class NoopSharedCache implements SharedCacheInterface
{
    public function get(string $key): ?string
    {
        unset($key);
        return null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        unset($key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        unset($key);
    }
}
