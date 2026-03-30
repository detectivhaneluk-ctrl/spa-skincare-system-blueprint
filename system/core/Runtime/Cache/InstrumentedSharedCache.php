<?php

declare(strict_types=1);

namespace Core\Runtime\Cache;

use Core\Contracts\SharedCacheInterface;

/**
 * Wraps a {@see SharedCacheInterface} and records operational metrics on {@see SharedCacheMetrics}.
 */
final class InstrumentedSharedCache implements SharedCacheInterface
{
    public function __construct(
        private SharedCacheInterface $inner,
        private SharedCacheMetrics $metrics
    ) {
    }

    public function get(string $key): ?string
    {
        $v = $this->inner->get($key);
        $this->metrics->recordGet($v);

        return $v;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->inner->set($key, $value, $ttlSeconds);
        $this->metrics->recordSet();
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
        $this->metrics->recordDelete();
    }
}
