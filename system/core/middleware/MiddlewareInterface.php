<?php

declare(strict_types=1);

namespace Core\Middleware;

interface MiddlewareInterface
{
    public function handle(callable $next): void;
}
