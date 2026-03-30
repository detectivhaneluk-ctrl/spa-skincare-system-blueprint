<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Auth\PrincipalPlaneResolver;

/**
 * FOUNDATION-100: fail-closed guard for platform-only routes.
 */
final class PlatformPrincipalMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        $user = $auth->user();
        if (!$user) {
            $this->denyUnauthenticated();

            return;
        }
        $userId = (int) ($user['id'] ?? 0);
        $principal = Application::container()->get(PrincipalPlaneResolver::class);
        if (!$principal->isControlPlane($userId)) {
            $this->denyForbidden();

            return;
        }

        $next();
    }

    private function denyUnauthenticated(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required'],
            ]);
            exit;
        }
        header('Location: /login');
        exit;
    }

    private function denyForbidden(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Platform principal required'],
            ]);
            exit;
        }
        Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
