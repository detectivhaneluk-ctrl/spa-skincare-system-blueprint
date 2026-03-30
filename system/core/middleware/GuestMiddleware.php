<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;

final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        if ($auth->check()) {
            header('Location: /');
            exit;
        }
        $next();
    }
}
