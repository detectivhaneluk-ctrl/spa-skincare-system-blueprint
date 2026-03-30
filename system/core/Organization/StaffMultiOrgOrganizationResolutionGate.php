<?php

declare(strict_types=1);

namespace Core\Organization;

/**
 * FOUNDATION-25 — post-auth gate: multi-org deployments cannot continue staff HTTP work without resolved organization.
 *
 * FOUNDATION-43 / F-44: narrow exemptions for platform org registry routes (see {@see isPlatformOrganizationRegistryReadPath},
 * {@see isPlatformOrganizationRegistryManagePath}). **FOUNDATION-97:** **`/platform-admin`** prefix exempt (platform control plane home).
 * RBAC remains on {@see \Core\Middleware\PermissionMiddleware}.
 *
 * @see STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-FOUNDATION-25-OPS.md
 */
final class StaffMultiOrgOrganizationResolutionGate
{
    private const JSON_ERROR_CODE = 'ORGANIZATION_CONTEXT_REQUIRED';

    private const MESSAGE = 'Organization context is required before continuing. Select a branch or contact an administrator.';

    public function __construct(
        private OrganizationContext $organizationContext,
        private OrganizationContextResolver $resolver,
    ) {
    }

    /**
     * Call only after successful authentication (e.g. end of {@see \Core\Middleware\AuthMiddleware} checks).
     */
    public function enforceForAuthenticatedStaff(): void
    {
        if ($this->isExemptRequestPath()) {
            return;
        }

        if ($this->resolver->countActiveOrganizations() <= 1) {
            return;
        }

        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return;
        }

        $this->denyUnresolvedOrganization();
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
        if ($path === '/tenant-entry' && $method === 'GET') {
            return true;
        }
        if ($path === '/support-entry/stop' && $method === 'POST') {
            return true;
        }

        // FOUNDATION-97: platform control plane home — same RBAC as registry; org context not required.
        if (str_starts_with($path, '/platform-admin')) {
            return true;
        }

        // FOUNDATION-43: platform org registry HTTP read (GET only) — platform.organizations.view.
        if ($method === 'GET' && $this->isPlatformOrganizationRegistryReadPath($path)) {
            return true;
        }

        // FOUNDATION-44: platform org registry HTTP manage (GET create/edit + POST mutations) — platform.organizations.manage.
        if ($this->isPlatformOrganizationRegistryManagePath($path, $method)) {
            return true;
        }

        return false;
    }

    private function normalizedRequestPath(): string
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        return rtrim($path, '/') ?: '/';
    }

    /**
     * GET list/show paths — {@see system/routes/web/register_platform_organization_registry.php}.
     */
    private function isPlatformOrganizationRegistryReadPath(string $normalizedPath): bool
    {
        if ($normalizedPath === '/platform/organizations') {
            return true;
        }

        return (bool) preg_match('#^/platform/organizations/\d+$#', $normalizedPath);
    }

    /**
     * Create/edit forms (GET) and mutation POSTs — same registrar; must stay in sync with new routes.
     */
    private function isPlatformOrganizationRegistryManagePath(string $normalizedPath, string $method): bool
    {
        if ($method === 'GET' && $normalizedPath === '/platform/organizations/create') {
            return true;
        }
        if ($method === 'GET' && (bool) preg_match('#^/platform/organizations/\d+/edit$#', $normalizedPath)) {
            return true;
        }
        if ($method !== 'POST') {
            return false;
        }
        if ($normalizedPath === '/platform/organizations') {
            return true;
        }
        if ((bool) preg_match('#^/platform/organizations/\d+$#', $normalizedPath)) {
            return true;
        }
        if ((bool) preg_match('#^/platform/organizations/\d+/suspend$#', $normalizedPath)) {
            return true;
        }
        if ((bool) preg_match('#^/platform/organizations/\d+/reactivate$#', $normalizedPath)) {
            return true;
        }

        return false;
    }

    private function denyUnresolvedOrganization(): void
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

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
