<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Auth\PrincipalPlaneResolver;

/**
 * FOUNDATION-100: tenant **entry shell** only — {@code /tenant-entry} and {@code POST /account/branch-context}.
 * Redirects platform principals to {@code /platform-admin}. Tenant **module** boundaries use
 * {@see TenantProtectedRouteMiddleware} after {@see AuthMiddleware} (WAVE-01).
 */
final class TenantPrincipalMiddleware implements MiddlewareInterface
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
        if ($principal->isControlPlane($userId)) {
            header('Location: /platform-admin');
            exit;
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

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
