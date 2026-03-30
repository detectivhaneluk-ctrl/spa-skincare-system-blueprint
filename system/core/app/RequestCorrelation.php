<?php

declare(strict_types=1);

namespace Core\App;

/**
 * Per-request correlation id for structured logs (reset at {@see \Core\Router\Dispatcher::dispatch} entry).
 */
final class RequestCorrelation
{
    private static ?string $id = null;

    public static function reset(): void
    {
        self::$id = null;
    }

    public static function id(): string
    {
        if (self::$id === null) {
            self::$id = bin2hex(random_bytes(16));
        }

        return self::$id;
    }
}
