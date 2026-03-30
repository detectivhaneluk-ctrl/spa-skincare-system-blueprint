<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Core\Organization\StaffMultiOrgOrganizationResolutionGate;
use Core\Tenant\TenantRuntimeContextEnforcer;

/**
 * Requires authenticated session user; enforces {@see \Core\App\SettingsService::getSecuritySettings(null)} — **organization default** only
 * (same scope as {@see \Modules\Settings\Controllers\SettingsController} security section; A-005). Branch-level `security.*` rows are not honored here.
 * Applies inactivity timeout and optional 90-day password expiry (exempt: GET/POST /account/password, POST /logout). Does not check RBAC — add
 * {@see PermissionMiddleware} on the route when a permission is required.
 *
 * FOUNDATION-25: after successful auth, {@see StaffMultiOrgOrganizationResolutionGate} blocks multi-org staff when organization context is unresolved.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        if (!$auth->check()) {
            $this->deny();
            return;
        }
        $settings = Application::container()->get(SettingsService::class);
        $timeoutMinutes = $settings->getSecuritySettings(null)['inactivity_timeout_minutes'];
        $session = Application::container()->get(SessionAuth::class);
        if (!$session->touchActivityWithinInactivityLimit($timeoutMinutes)) {
            $auth->logout();
            $this->deny();
            return;
        }
        $user = $auth->user();
        if ($user !== null && $this->isPasswordExpiredForUser($user, $settings)) {
            $path = $this->normalizedRequestPath();
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($this->isPasswordExpirationExemptPath($path, $method)) {
                $next();
                return;
            }
            $this->auditPasswordExpiredBlockOnce($user, $path);
            $this->denyPasswordExpired();
            return;
        }
        Application::container()->get(StaffMultiOrgOrganizationResolutionGate::class)->enforceForAuthenticatedStaff();
        Application::container()->get(TenantRuntimeContextEnforcer::class)->enforceForAuthenticatedUser((int) ($user['id'] ?? 0));
        $next();
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isPasswordExpiredForUser(array $user, SettingsService $settings): bool
    {
        $policy = $settings->getSecuritySettings(null)['password_expiration'];
        if ($policy !== '90_days') {
            return false;
        }
        $raw = $user['password_changed_at'] ?? null;
        if ($raw === null || $raw === '') {
            $raw = $user['created_at'] ?? null;
        }
        if ($raw === null || $raw === '') {
            return false;
        }
        $ts = strtotime((string) $raw);
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) >= (90 * 86400);
    }

    private function normalizedRequestPath(): string
    {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $path = rtrim($path, '/') ?: '/';

        return $path;
    }

    private function isPasswordExpirationExemptPath(string $path, string $method): bool
    {
        if ($path === '/logout' && $method === 'POST') {
            return true;
        }
        if ($path === '/account/password' && ($method === 'GET' || $method === 'POST')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function auditPasswordExpiredBlockOnce(array $user, string $path): void
    {
        if (!empty($_SESSION[SessionAuth::SESSION_PASSWORD_EXPIRY_BLOCK_AUDIT])) {
            return;
        }
        $audit = Application::container()->get(AuditService::class);
        $audit->log(
            'password_expired_blocked',
            'user',
            (int) $user['id'],
            (int) $user['id'],
            null,
            ['path' => $path]
        );
        $_SESSION[SessionAuth::SESSION_PASSWORD_EXPIRY_BLOCK_AUDIT] = true;
    }

    private function denyPasswordExpired(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => ['code' => 'PASSWORD_EXPIRED', 'message' => 'Password must be changed'],
            ]);
            exit;
        }
        flash('error', 'Your password has expired. Please set a new password.');
        header('Location: /account/password');
        exit;
    }

    private function deny(): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required']]);
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
