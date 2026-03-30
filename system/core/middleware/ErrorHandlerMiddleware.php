<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Throwable;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        try {
            $next();
        } catch (Throwable $e) {
            $handler = Application::container()->get(\Core\Errors\HttpErrorHandler::class);
            $handler->handleException($e);
        }
    }
}
