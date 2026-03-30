<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use Core\App\Application;
use Core\App\ClientIp;
use Core\Audit\AuditService;
use Core\Auth\AuthService;
use Core\Auth\LoginThrottleService;
use Core\Auth\PrincipalPlaneResolver;
use Core\Auth\SessionAuth;
use Core\Organization\OrganizationLifecycleGate;
use Core\Runtime\RateLimit\RuntimeProtectedPathRateLimiter;
use Modules\Organizations\Services\FounderImpersonationAuditService;

final class LoginController
{
    public function show(): void
    {
        $error = flash('error');
        $success = flash('success');
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $this->logLoginCsrfDebug('login_get_token_issued', [
            'post_csrf_token_present' => false,
            'session_csrf_token_present' => isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '',
            'csrf_valid' => false,
        ]);
        require base_path('modules/auth/views/login.php');
    }

    public function attempt(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            flash('error', 'Email and password are required.');
            header('Location: /login');
            exit;
        }

        $auth = Application::container()->get(AuthService::class);
        $audit = Application::container()->get(AuditService::class);
        $identifier = strtolower($email);

        $waitSecs = $auth->remainingLockoutSeconds($identifier);
        if ($waitSecs > 0) {
            flash('error', $this->throttleWaitMessage((int) $waitSecs));
            header('Location: /login');
            exit;
        }

        $ipRl = Application::container()->get(RuntimeProtectedPathRateLimiter::class)->tryConsumeLoginPost(ClientIp::forRequest());
        if (!$ipRl['ok']) {
            flash('error', 'Too many login attempts from this network. Try again in ' . (int) $ipRl['retry_after'] . ' seconds.');
            header('Location: /login');
            exit;
        }

        if ($auth->attempt($email, $password)) {
            $user = $auth->user();
            $userId = (int) ($user['id'] ?? 0);
            $principalPlane = Application::container()->get(PrincipalPlaneResolver::class);
            $lifecycleGate = Application::container()->get(OrganizationLifecycleGate::class);
            if (
                !$principalPlane->isControlPlane($userId)
                && $lifecycleGate->isTenantUserBoundToSuspendedOrganization($userId)
            ) {
                $audit->log('login_denied_tenant_suspended', 'user', $userId, $userId, null, null, 'denied', 'auth');
                $auth->logout();
                flash('error', 'Tenant access is suspended for this organization.');
                header('Location: /login');
                exit;
            }
            $audit->log('login_success', 'user', (int) $user['id'], (int) $user['id'], null, null, 'success', 'auth');
            \slog('info', 'critical_path.auth', 'login_success', ['user_id' => (int) $user['id']]);
            $home = Application::container()->get(\Core\Auth\AuthenticatedHomePathResolver::class)
                ->homePathForUserId((int) ($user['id'] ?? 0));
            header('Location: ' . $home);
            exit;
        }

        $audit->log('login_failure', 'user', null, null, null, ['email' => $identifier], 'failure', 'auth');
        \slog('info', 'critical_path.auth', 'login_failure', ['email' => $identifier]);
        flash('error', 'Invalid email or password.');
        header('Location: /login');
        exit;
    }

    public function logout(): void
    {
        $auth = Application::container()->get(AuthService::class);
        $audit = Application::container()->get(AuditService::class);
        $session = Application::container()->get(SessionAuth::class);
        $user = $auth->user();
        if ($session->isSupportEntryActive()) {
            $actor = (int) $session->supportActorUserId();
            $tenant = (int) $session->id();
            Application::container()->get(FounderImpersonationAuditService::class)
                ->logSupportSessionEnd($actor, $tenant, $session->supportSessionCorrelationId());
        }
        $effectiveId = $user ? (int) $user['id'] : null;
        $audit->log('logout', 'user', $effectiveId, $session->auditActorUserId(), null, null, 'success', 'auth');
        $auth->logout();
        header('Location: /login');
        exit;
    }

    private function logLoginCsrfDebug(string $event, array $extra = []): void
    {
        if (!$this->shouldLogLoginCsrfDebug()) {
            return;
        }

        $sessionCookieName = (string) config('session.cookie_name', 'spa_session');
        $appUrlHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        $record = array_merge([
            'event' => $event,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'path' => (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
            'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'session_status' => session_status(),
            'session_name' => session_name(),
            'session_id_present' => session_id() !== '',
            'configured_session_cookie_name' => $sessionCookieName,
            'session_cookie_received' => isset($_COOKIE[$sessionCookieName]),
            'resolved_session_secure' => (bool) config('session.secure', false),
            'session_domain' => (string) config('session.domain', ''),
            'app_url_host' => is_string($appUrlHost) ? $appUrlHost : '',
            'headers_sent' => headers_sent(),
        ], $extra);

        $line = '[' . date('c') . '] ' . json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $logPath = base_path('storage/logs/login-csrf-debug.log');
        @file_put_contents($logPath, $line, FILE_APPEND);
    }

    private function shouldLogLoginCsrfDebug(): bool
    {
        $debug = (bool) config('app.debug', false);
        $env = strtolower((string) config('app.env', 'production'));
        return $debug || $env === 'local';
    }

    private function throttleWaitMessage(int $seconds): string
    {
        $seconds = max(1, min($seconds, LoginThrottleService::MAX_REMAINING_LOCKOUT_SEC));
        if ($seconds >= 120) {
            $minutes = (int) max(1, ceil($seconds / 60));

            return "Too many attempts. Please wait {$minutes} minutes and try again.";
        }

        return "Too many attempts. Please wait {$seconds} seconds and try again.";
    }
}
