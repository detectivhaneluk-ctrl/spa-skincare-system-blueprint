<?php

declare(strict_types=1);

namespace Core\Kernel;

/**
 * Mutable per-request holder for the resolved TenantContext.
 *
 * Registered as a singleton in the DI container. The TenantContextMiddleware calls
 * reset() + set() once per request to install the immutable context snapshot.
 *
 * Consumers call get() or requireContext() — never set() directly.
 * Only TenantContextMiddleware is permitted to call set().
 *
 * Why a holder instead of a request-scoped factory:
 * The container is a singleton container. The holder pattern gives us per-request
 * lifecycle management without requiring a full request-scoped DI overhaul.
 * All registered singletons (BranchContext, OrganizationContext) already use this pattern.
 */
final class RequestContextHolder
{
    private ?TenantContext $context = null;

    /**
     * Install the resolved TenantContext for this request.
     * Called ONLY by TenantContextMiddleware.
     */
    public function set(TenantContext $ctx): void
    {
        $this->context = $ctx;
    }

    /**
     * Returns the resolved TenantContext, or null if middleware has not run yet.
     */
    public function get(): ?TenantContext
    {
        return $this->context;
    }

    /**
     * Returns the resolved TenantContext or throws if TenantContextMiddleware never ran.
     *
     * Use this in service/repository code that runs after the global middleware pipeline.
     *
     * @throws \RuntimeException if called before the middleware pipeline resolves context
     */
    public function requireContext(): TenantContext
    {
        if ($this->context === null) {
            throw new \RuntimeException(
                'TenantContext has not been resolved for this request. '
                . 'Ensure TenantContextMiddleware is present in the global pipeline '
                . '(after OrganizationContextMiddleware). FOUNDATION-A1.'
            );
        }

        return $this->context;
    }

    /**
     * Reset for a new request. Called by TenantContextMiddleware before resolution.
     */
    public function reset(): void
    {
        $this->context = null;
    }
}
