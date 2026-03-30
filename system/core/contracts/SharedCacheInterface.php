<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Optional cross-request shared cache (Redis when configured; {@see \Core\Runtime\Cache\NoopSharedCache} default).
 * String values only; callers serialize structured data.
 */
interface SharedCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
