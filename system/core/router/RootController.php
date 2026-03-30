<?php

declare(strict_types=1);

namespace Core\Router;

use Core\App\Application;

/**
 * Root / redirect: guest → /login; authenticated → tenant entry resolver or platform control plane ({@see AuthenticatedHomePathResolver}).
 */
final class RootController
{
    public function handle(): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        if (!$auth->check()) {
            header('Location: /login');
            exit;
        }
        $user = $auth->user();
        $home = Application::container()->get(\Core\Auth\AuthenticatedHomePathResolver::class)
            ->homePathForUserId((int) ($user['id'] ?? 0));
        header('Location: ' . $home);
        exit;
    }
}
