<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;

/**
 * RBAC check for one permission. Expects an authenticated user (normally {@see AuthMiddleware} runs first).
 * Unauthenticated HTML requests redirect to `/login` (same as {@see AuthMiddleware}); JSON returns 401.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public static function for(string $permission): self
    {
        return new self($permission);
    }

    public function __construct(private string $permission)
    {
    }

    public function handle(callable $next): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        $user = $auth->user();
        if (!$user) {
            $this->denyUnauthenticated();
            return;
        }

        $perms = Application::container()->get(\Core\Permissions\PermissionService::class);
        if (!$perms->has((int) $user['id'], $this->permission)) {
            $this->deny(403);
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
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required',
                ],
            ]);
            exit;
        }
        header('Location: /login');
        exit;
    }

    private function deny(int $code): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            http_response_code($code);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => $code === 403 ? 'FORBIDDEN' : 'UNAUTHORIZED',
                    'message' => $code === 403 ? 'Insufficient permissions' : 'Authentication required',
                ],
            ]);
            exit;
        }
        $handler = Application::container()->get(\Core\Errors\HttpErrorHandler::class);
        $handler->handle($code);
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
