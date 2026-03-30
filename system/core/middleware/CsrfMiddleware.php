<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD', 'OPTIONS'], true)) {
            $next();
            return;
        }
        if ($this->isMatchedRouteCsrfExemptPost()) {
            $next();
            return;
        }
        $token = $_POST[config('app.csrf_token_name', 'csrf_token')] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $isLoginPost = $this->isLoginPost();
        $valid = $session->validateCsrf($token);
        if ($isLoginPost) {
            $this->logLoginCsrfDebug('login_post_csrf_validation', [
                'post_csrf_token_present' => $token !== '',
                'session_csrf_token_present' => isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '',
                'csrf_valid' => $valid,
            ]);
        }
        if (!$valid) {
            if ($isLoginPost) {
                $this->logLoginCsrfDebug('login_post_419', [
                    'post_csrf_token_present' => $token !== '',
                    'session_csrf_token_present' => isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '',
                    'csrf_valid' => false,
                ]);
            }
            $protocol = isset($_SERVER['SERVER_PROTOCOL']) && $_SERVER['SERVER_PROTOCOL'] !== ''
                ? $_SERVER['SERVER_PROTOCOL']
                : 'HTTP/1.1';
            header($protocol . ' 419 Page Expired');
            http_response_code(419);
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderError();
            exit;
        }
        $next();
    }

    /**
     * POST routes registered with `csrf_exempt` => true (see {@see \Core\Router\Router::post} options).
     * Set on the matched route by {@see \Core\Router\Dispatcher} before this middleware runs.
     */
    private function isMatchedRouteCsrfExemptPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        return Application::isMatchedRouteCsrfExempt();
    }

    private function renderError(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>419</title></head><body><h1>419 Page Expired</h1><p>Please refresh and try again.</p></body></html>';
    }

    private function isLoginPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path = $path !== null && $path !== '' ? $path : '/';
        return $path === '/login';
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
}
