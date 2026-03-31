<?php

declare(strict_types=1);

namespace Core\Runtime\Redis;

use Core\App\Database;
use Core\Contracts\DistributedLockInterface;

/**
 * MySQL advisory lock fallback for {@see DistributedLockInterface}.
 *
 * Used ONLY in non-production environments (local dev, test) when Redis is not available.
 * Production MUST use {@see RedisDistributedLock} — the {@see \Core\Runtime\Guard\ProductionRuntimeGuard}
 * enforces this by refusing to start if Redis is unavailable in production.
 *
 * Limitation: MySQL GET_LOCK() is connection-scoped and server-scoped.
 * If the connection closes, the lock is implicitly released (safe for our use-case).
 * Does NOT work correctly across multiple MySQL servers (HA / read-replicas).
 * For dev single-server use only.
 */
final class MysqlDistributedLock implements DistributedLockInterface
{
    /** @var array<string, bool> tracks which keys are currently held */
    private array $held = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function tryAcquire(string $key, int $ttlSeconds = 30): bool
    {
        if ($key === '') {
            return false;
        }
        try {
            // Timeout 0 = non-blocking; returns 1 if acquired, 0 if not, null on error.
            $row = $this->db->fetchOne('SELECT GET_LOCK(?, 0) AS acquired', [$key]);
            $acquired = isset($row['acquired']) && (int) $row['acquired'] === 1;
            if ($acquired) {
                $this->held[$key] = true;
            }
            return $acquired;
        } catch (\Throwable) {
            return false;
        }
    }

    public function release(string $key): void
    {
        if ($key === '' || !isset($this->held[$key])) {
            return;
        }
        unset($this->held[$key]);
        try {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [$key]);
        } catch (\Throwable) {
            // Best-effort; suppress all errors.
        }
    }
}
