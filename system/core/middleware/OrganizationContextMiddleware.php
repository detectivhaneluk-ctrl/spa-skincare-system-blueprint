<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Core\Organization\OrganizationContextResolver;

/**
 * Resolves {@see OrganizationContext} immediately after {@see BranchContextMiddleware}.
 *
 * Organization is derived from the active branch row when branch context is set; otherwise **F-46** may resolve via
 * exactly one active {@code user_organization_memberships} row for the authenticated user, else the legacy single-active-org
 * deployment fallback (FOUNDATION-09). No request/session org selector.
 */
final class OrganizationContextMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        $resolver = Application::container()->get(OrganizationContextResolver::class);
        $branchContext = Application::container()->get(BranchContext::class);
        $organizationContext = Application::container()->get(OrganizationContext::class);

        $resolver->resolveForHttpRequest($branchContext, $organizationContext);

        $next();
    }
}
