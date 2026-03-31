<?php

declare(strict_types=1);

namespace Core\Kernel;

/**
 * The surface from which this request originates.
 *
 * Embedded in TenantContext to allow policies to differentiate enforcement rules
 * by execution environment (e.g. background jobs bypass HTTP-only gates).
 *
 * Future: CLI and BACKGROUND surfaces will receive their own resolver path
 * rather than using the HTTP session resolver.
 */
enum ExecutionSurface: string
{
    /**
     * Standard HTTP request on tenant-internal authenticated routes.
     * Requires resolved org + branch for protected operations.
     */
    case HTTP_TENANT = 'http_tenant';

    /**
     * HTTP request on platform / founder control-plane routes.
     * Not branch-scoped by the tenant kernel.
     */
    case HTTP_PLATFORM = 'http_platform';

    /**
     * HTTP request on public / guest routes (public booking, anonymous commerce, token self-service).
     * Tenant context may or may not be resolved depending on public flow requirements.
     */
    case HTTP_PUBLIC = 'http_public';

    /**
     * CLI script or migration runner. Context must be explicitly provided by the caller.
     * Not resolved via HTTP session resolver.
     */
    case CLI = 'cli';

    /**
     * Background job / queue worker. Context must be explicitly bound per job from job payload.
     * Not resolved via HTTP session resolver.
     */
    case BACKGROUND = 'background';
}
