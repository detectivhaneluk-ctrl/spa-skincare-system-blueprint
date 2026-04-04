<?php

declare(strict_types=1);

namespace Core\Runtime\Redis;

use InvalidArgumentException;
use Redis;

final class RedisFactory
{
    /**
     * @throws InvalidArgumentException|\RedisException
     */
    public static function connect(string $url): Redis
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Redis URL is empty.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('Redis URL must include a host.');
        }
        $host = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : 6379;
        $timeout = 2.0;
        $redis = new Redis();
        $redis->connect($host, $port, $timeout);
        // Avoid hung HTTP requests when Redis stops responding mid-command (SharedCache, sessions, locks).
        try {
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        } catch (\Throwable) {
            // Older phpredis builds may omit OPT_READ_TIMEOUT support.
        }
        $pass = $parts['pass'] ?? '';
        if ($pass !== '') {
            $redis->auth($pass);
        }
        $db = 0;
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $db = (int) ltrim((string) $parts['path'], '/');
            if ($db >= 0 && $db <= 15) {
                $redis->select($db);
            }
        }

        return $redis;
    }
}
