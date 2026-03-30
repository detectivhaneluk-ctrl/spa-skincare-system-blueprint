<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;

/**
 * Releases the PHP session write lock early ({@see session_write_close()}) on explicitly opted-in routes.
 *
 * FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01: reduces lock contention on file/redis session backends when the
 * remaining controller work is read-only with respect to session persistence.
 *
 * **Safe only when** the handler (and anything after this middleware) does not call {@see flash()},
 * {@see \Core\Auth\SessionAuth::csrfToken()} (first call may mint a token), {@see \Core\Auth\SessionAuth::login()},
 * support-entry mutators, or any direct {@code $_SESSION} write that must survive the request.
 *
 * **Intentionally not applied** to HTML form routes, login/logout/support-entry, or POST/PUT/PATCH/DELETE.
 * Register this middleware **after** {@see AuthMiddleware}, {@see TenantProtectedRouteMiddleware}, and
 * {@see PermissionMiddleware} so all session writes from those layers are flushed first.
 *
 * Enable per route: {@code ['session_early_release' => true]} in {@see \Core\Router\Router::add} options, and append
 * this class to that route’s middleware list.
 */
final class SessionEarlyReleaseMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        if (!Application::isMatchedRouteSessionEarlyRelease()) {
            $next();

            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $next();

            return;
        }
        if (!(bool) config('session.early_release.enabled', true)) {
            $next();

            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $next();
    }
}
