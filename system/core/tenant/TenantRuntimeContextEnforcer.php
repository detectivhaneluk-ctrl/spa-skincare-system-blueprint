<?php

declare(strict_types=1);

namespace Core\Tenant;

use Core\App\Application;
use Core\Auth\PrincipalPlaneResolver;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationLifecycleGate;

final class TenantRuntimeContextEnforcer
{
    private const JSON_ERROR_CODE = 'TENANT_CONTEXT_REQUIRED';
    private const MESSAGE = 'Tenant branch and organization context are required before continuing.';
    private const JSON_SUSPENDED_ERROR_CODE = 'TENANT_ORGANIZATION_SUSPENDED';
    private const SUSPENDED_MESSAGE = 'Tenant access is blocked because the organization is suspended.';

    private const JSON_ACTOR_INACTIVE_CODE = 'TENANT_ACTOR_INACTIVE';
    private const ACTOR_INACTIVE_MESSAGE = 'This account is not permitted to operate for this location.';

    public function __construct(
        private PrincipalPlaneResolver $principalPlane,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private OrganizationLifecycleGate $lifecycleGate
    ) {
    }

    public function enforceForAuthenticatedUser(int $userId): void
    {
        if ($userId <= 0 || $this->principalPlane->isControlPlane($userId)) {
            return;
        }
        if ($this->isExemptRequestPath()) {
            return;
        }

        $branchId = $this->branchContext->getCurrentBranchId();
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        $mode = $this->organizationContext->getResolutionMode();
        if ($branchId !== null && $branchId > 0 && $orgId !== null && $orgId > 0
            && $mode === OrganizationContext::MODE_BRANCH_DERIVED) {
            if (!$this->lifecycleGate->isOrganizationActive($orgId)) {
                $this->denySuspended();
            }
            if ($this->lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId)) {
                $this->denySuspended();
            }
            if ($this->lifecycleGate->isTenantUserInactiveStaffAtBranch($userId, $branchId)) {
                $this->denyInactiveActor();
            }

            return;
        }
        if ($orgId !== null && $orgId > 0 && !$this->lifecycleGate->isOrganizationActive($orgId)) {
            $this->denySuspended();
        }
        $this->deny();
    }

    private function isExemptRequestPath(): bool
    {
        $path = $this->normalizedRequestPath();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($path === '/logout' && $method === 'POST') {
            return true;
        }
        if ($path === '/account/password' && ($method === 'GET' || $method === 'POST')) {
            return true;
        }
        if ($path === '/account/branch-context' && $method === 'POST') {
            return true;
        }
        if ($path === '/tenant-entry' && $method === 'GET') {
            return true;
        }
        if ($path === '/support-entry/stop' && $method === 'POST') {
            return true;
        }

        return false;
    }

    private function normalizedRequestPath(): string
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        return rtrim($path, '/') ?: '/';
    }

    private function deny(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => self::JSON_ERROR_CODE,
                    'message' => self::MESSAGE,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: /tenant-entry');
        exit;
    }

    private function denySuspended(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => self::JSON_SUSPENDED_ERROR_CODE,
                    'message' => self::SUSPENDED_MESSAGE,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require base_path('modules/auth/views/tenant-suspended.php');
        exit;
    }

    private function denyInactiveActor(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => self::JSON_ACTOR_INACTIVE_CODE,
                    'message' => self::ACTOR_INACTIVE_MESSAGE,
                ],
            ], JSON_UNESCAPED_UNICODE);
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
