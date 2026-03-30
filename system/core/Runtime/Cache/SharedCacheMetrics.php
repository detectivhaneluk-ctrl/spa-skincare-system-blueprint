<?php

declare(strict_types=1);

namespace Core\Runtime\Cache;

/**
 * In-process counters for {@see InstrumentedSharedCache}. Safe under Noop/Redis; hit/miss only counted when backend is {@code redis}.
 */
final class SharedCacheMetrics
{
    private string $backend = 'noop';

    private int $getHits = 0;

    private int $getMisses = 0;

    /** Invocations when backend is not active Redis (noop, extension missing, connect failed). */
    private int $degradedGets = 0;

    private int $sets = 0;

    private int $deletes = 0;

    public function setBackend(string $backend): void
    {
        $this->backend = $backend;
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function recordGet(?string $value): void
    {
        if ($this->backend === 'redis') {
            if ($value !== null) {
                ++$this->getHits;
            } else {
                ++$this->getMisses;
            }

            return;
        }
        ++$this->degradedGets;
    }

    public function recordSet(): void
    {
        ++$this->sets;
    }

    public function recordDelete(): void
    {
        ++$this->deletes;
    }

    public function redisEffective(): bool
    {
        return $this->backend === 'redis';
    }

    /**
     * @return array{
     *   backend: string,
     *   redis_effective: bool,
     *   shared_cache_degraded: bool,
     *   get_hits: int,
     *   get_misses: int,
     *   degraded_gets: int,
     *   sets: int,
     *   deletes: int
     * }
     */
    public function snapshot(): array
    {
        $redis = $this->redisEffective();

        return [
            'backend' => $this->backend,
            'redis_effective' => $redis,
            'shared_cache_degraded' => !$redis,
            'get_hits' => $this->getHits,
            'get_misses' => $this->getMisses,
            'degraded_gets' => $this->degradedGets,
            'sets' => $this->sets,
            'deletes' => $this->deletes,
        ];
    }
}
