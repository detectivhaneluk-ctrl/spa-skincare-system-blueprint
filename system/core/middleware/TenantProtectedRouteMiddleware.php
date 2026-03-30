<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Auth\PrincipalPlaneResolver;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;

/**
 * WAVE-01 — PRINCIPAL-PLANE-SEAL: canonical route-level boundary for tenant-internal modules.
 *
 * Requires: authenticated user, non-platform principal, {@see BranchContext} with positive branch id,
 * {@see OrganizationContext} with positive organization id, and resolution mode
 * {@see OrganizationContext::MODE_BRANCH_DERIVED}.
 *
 * Stack: {@see AuthMiddleware} → this → {@see PermissionMiddleware} (when RBAC applies).
 * Do not register on public routes, login/logout/account/password, {@see TenantPrincipalMiddleware} entry paths
 * ({@code /tenant-entry}, {@code POST /account/branch-context}), or platform-only routes
 * ({@see PlatformPrincipalMiddleware}).
 */
final class TenantProtectedRouteMiddleware implements MiddlewareInterface
{
    private const JSON_PLATFORM_CODE = 'TENANT_ROUTE_PLATFORM_PRINCIPAL_FORBIDDEN';

    private const JSON_PLATFORM_MESSAGE = 'This route is not available to platform principals.';

    private const JSON_CONTEXT_CODE = 'TENANT_CONTEXT_REQUIRED';

    private const JSON_CONTEXT_MESSAGE = 'Tenant branch and organization context are required before continuing.';

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
        $plane = $principal->resolveForUserId($userId);
        if ($plane === PrincipalPlaneResolver::CONTROL_PLANE) {
            $this->denyPlatformPrincipal();

            return;
        }
        if ($plane !== PrincipalPlaneResolver::TENANT_PLANE) {
            $this->denyTenantContextRequired();

            return;
        }

        $branchContext = Application::container()->get(BranchContext::class);
        $organizationContext = Application::container()->get(OrganizationContext::class);
        $branchId = $branchContext->getCurrentBranchId();
        $orgId = $organizationContext->getCurrentOrganizationId();
        $mode = $organizationContext->getResolutionMode();

        if ($branchId !== null && $branchId > 0
            && $orgId !== null && $orgId > 0
            && $mode === OrganizationContext::MODE_BRANCH_DERIVED) {
            $next();

            return;
        }

        $this->denyTenantContextRequired();
    }

    private function denyUnauthenticated(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /login');
        exit;
    }

    private function denyPlatformPrincipal(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => self::JSON_PLATFORM_CODE,
                    'message' => self::JSON_PLATFORM_MESSAGE,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
        exit;
    }

    private function denyTenantContextRequired(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => self::JSON_CONTEXT_CODE,
                    'message' => self::JSON_CONTEXT_MESSAGE,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /tenant-entry');
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
