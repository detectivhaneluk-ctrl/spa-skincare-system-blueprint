<?php

declare(strict_types=1);

namespace Core\Contracts;

/**
 * Distributed advisory lock contract.
 *
 * Implementations MUST be fail-closed:
 *  - {@see tryAcquire} returns false on any error — the caller must treat a false return
 *    as "lock held by someone else" and skip the work rather than proceeding unguarded.
 *  - {@see release} is a best-effort cleanup; partial failures must not throw.
 *
 * Callers must always call release() in a finally block after a successful acquire.
 */
interface DistributedLockInterface
{
    /**
     * Attempt to acquire an exclusive lock on $key with the given TTL.
     *
     * @param string $key       Logical lock name (implementations prefix as needed)
     * @param int    $ttlSeconds Maximum time the lock can be held before auto-expiry
     * @return bool             true = lock acquired; false = lock unavailable or error
     */
    public function tryAcquire(string $key, int $ttlSeconds = 30): bool;

    /**
     * Release a previously acquired lock. Best-effort; must not throw.
     *
     * @param string $key Same key passed to {@see tryAcquire}
     */
    public function release(string $key): void;
}
