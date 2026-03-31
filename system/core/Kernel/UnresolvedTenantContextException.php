<?php

declare(strict_types=1);

namespace Core\Kernel;

/**
 * Thrown by fail-closed operations when a protected tenant-owned operation requires
 * a resolved TenantContext but the context is absent, unresolved, or incomplete.
 *
 * This is the kernel-level fail-closed signal. Callers (controllers, middleware)
 * are expected to translate this to an appropriate HTTP 403 / redirect response.
 *
 * Do NOT catch this silently. Either the request must be rejected or the call site
 * must be moved outside the protected scope.
 */
final class UnresolvedTenantContextException extends \RuntimeException
{
    public function __construct(string $reason = 'Tenant context is required but was not resolved.')
    {
        parent::__construct($reason);
    }
}
