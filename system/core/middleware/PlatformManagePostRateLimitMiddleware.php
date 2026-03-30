<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Runtime\RateLimit\RuntimeProtectedPathRateLimiter;

/**
 * Centralized rate limit for platform.manage mutating POSTs (FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02).
 */
final class PlatformManagePostRateLimitMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $next();

            return;
        }
        $auth = Application::container()->get(AuthService::class);
        $user = $auth->user();
        $uid = $user !== null ? (int) ($user['id'] ?? 0) : 0;
        $lim = Application::container()->get(RuntimeProtectedPathRateLimiter::class);
        $r = $lim->tryConsumePlatformManagePost($uid);
        if ($r['ok']) {
            $next();

            return;
        }
        $this->deny((int) $r['retry_after']);
    }

    private function deny(int $retryAfter): void
    {
        if ($this->wantsJson()) {
            header('Content-Type: application/json');
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too many requests. Retry after ' . $retryAfter . ' seconds.',
                    'retry_after' => $retryAfter,
                ],
            ]);
            exit;
        }
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Too many requests. Retry after ' . $retryAfter . " seconds.\n";
        exit;
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
