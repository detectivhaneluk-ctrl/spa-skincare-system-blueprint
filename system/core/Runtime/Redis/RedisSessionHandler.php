<?php

declare(strict_types=1);

namespace Core\Runtime\Redis;

use SessionHandlerInterface;

/**
 * Redis-backed PHP session handler.
 *
 * Stores each session as a Redis string with a sliding TTL:
 *   Key:   {prefix}:sess:{session_id}
 *   Value: raw serialized session data (same bytes PHP would write to a file)
 *   TTL:   $lifetimeSeconds (from PHP session.gc_maxlifetime or config)
 *
 * Usage: call {@see registerIfAvailable} BEFORE the first session_start().
 * Handles fail-closed automatically: if the handler cannot write/read, PHP falls
 * back to its default error handling (session_write_close / warnings).
 *
 * Thread-safety: Redis string SET is atomic. No separate lock is used here because
 * {@see \Core\Middleware\SessionEarlyReleaseMiddleware} already limits the session write window.
 * For write-heavy concurrent sessions, add a per-session lock (WAVE-03+).
 */
final class RedisSessionHandler implements SessionHandlerInterface
{
    private int $lifetime;

    public function __construct(
        private readonly \Redis $redis,
        private readonly string $keyPrefix,
        int $lifetimeSeconds = 7200
    ) {
        $this->lifetime = max(1, $lifetimeSeconds);
    }

    /**
     * Register this handler as the active PHP session save handler, if Redis is available.
     * Must be called before session_start().
     *
     * @param RedisConnectionProvider $provider  Shared Redis connection
     * @param string                  $keyPrefix  Key prefix (e.g. "spa")
     * @param int                     $lifetimeSeconds Session lifetime in seconds
     */
    public static function registerIfAvailable(
        RedisConnectionProvider $provider,
        string $keyPrefix,
        int $lifetimeSeconds = 7200
    ): void {
        if (!$provider->isConnected()) {
            return;
        }
        $handler = new self($provider->redis(), $keyPrefix, $lifetimeSeconds);
        session_set_save_handler($handler, true);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $data = $this->redis->get($this->key($id));
            if ($data === false || $data === null) {
                return '';
            }
            return (string) $data;
        } catch (\Throwable) {
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->redis->setex($this->key($id), $this->lifetime, $data);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del($this->key($id));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis TTL handles expiry natively — no garbage collection needed.
        return 0;
    }

    private function key(string $id): string
    {
        return $this->keyPrefix . ':sess:' . $id;
    }
}
