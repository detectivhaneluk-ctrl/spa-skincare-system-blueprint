<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use Core\App\Application;
use Core\App\Response;
use Core\Branch\TenantBranchAccessService;
use Core\Http\SafeInternalRedirectPath;
use Core\Organization\OrganizationLifecycleGate;

/**
 * TENANT-BOUNDARY-HARDENING-01:
 * Explicit branch context switch endpoint (POST only).
 */
final class BranchContextController
{
    private const SESSION_KEY = 'branch_id';

    public function switch(): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        $user = $auth->user();
        if (!$user) {
            $this->denyUnauthenticated();

            return;
        }
        $userId = (int) ($user['id'] ?? 0);
        $raw = trim((string) ($_POST['branch_id'] ?? ''));
        $branchId = $raw !== '' ? (int) $raw : 0;
        if ($branchId <= 0) {
            $this->denyValidationFailed('Invalid branch.');

            return;
        }
        $allowed = Application::container()->get(TenantBranchAccessService::class)->allowedBranchIdsForUser($userId);
        if (!in_array($branchId, $allowed, true)) {
            $this->denyForbidden('Branch is not allowed for this tenant principal.');

            return;
        }

        $lifecycleGate = Application::container()->get(OrganizationLifecycleGate::class);
        if ($lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId)) {
            $this->denyTenantOrganizationSuspended();

            return;
        }

        $_SESSION[self::SESSION_KEY] = $branchId;
        $redirect = SafeInternalRedirectPath::normalize($_POST['redirect_to'] ?? null);
        header('Location: ' . $redirect);
        exit;
    }

    private function denyUnauthenticated(): void
    {
        if ($this->wantsJson()) {
            Response::jsonPublicApiError(401, 'UNAUTHORIZED', 'Authentication required.');
        }
        header('Location: /login');
        exit;
    }

    private function denyValidationFailed(string $message): void
    {
        if ($this->wantsJson()) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $message);
        }
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(422);
        echo $message;
        exit;
    }

    private function denyForbidden(string $message): void
    {
        if ($this->wantsJson()) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', $message);
        }
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(403);
        echo $message;
        exit;
    }

    /**
     * Defense-in-depth on the exempt-from-{@see \Core\Tenant\TenantRuntimeContextEnforcer} POST path:
     * race or resolver drift must not persist a branch whose organization is suspended.
     */
    private function denyTenantOrganizationSuspended(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'TENANT_ORGANIZATION_SUSPENDED',
                    'message' => 'Tenant access is blocked because the organization is suspended.',
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require base_path('modules/auth/views/tenant-suspended.php');
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
