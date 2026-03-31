<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContextResolver;

/**
 * Materializes the immutable TenantContext for this request (FOUNDATION-A1).
 *
 * Pipeline position: AFTER BranchContextMiddleware and OrganizationContextMiddleware,
 * BEFORE per-route middleware (AuthMiddleware, TenantProtectedRouteMiddleware, etc.).
 *
 * Responsibilities:
 * - Resets the RequestContextHolder (prevents stale state across requests in long-lived processes).
 * - Calls TenantContextResolver::resolveForHttpRequest() exactly once.
 * - Stores the immutable result in RequestContextHolder for downstream consumption.
 *
 * What this middleware does NOT do:
 * - It does NOT deny requests based on context. Enforcement is the responsibility of
 *   TenantProtectedRouteMiddleware, TenantRuntimeContextEnforcer, and protected service
 *   operations that call TenantContext::requireResolvedTenant().
 * - It does NOT modify BranchContext or OrganizationContext — those are already set.
 * - It does NOT perform new DB queries — TenantContextResolver reads only from
 *   already-resolved request-scoped singletons.
 *
 * Future: When ExecutionSurface::CLI and BACKGROUND surfaces are added, their resolver
 * paths will NOT use this middleware; they will bind context via job payload at dispatch time.
 */
final class TenantContextMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        $holder = Application::container()->get(RequestContextHolder::class);
        $resolver = Application::container()->get(TenantContextResolver::class);

        $holder->reset();
        $holder->set($resolver->resolveForHttpRequest());

        $next();
    }
}
