<?php

declare(strict_types=1);

namespace Core\Kernel;

/**
 * Classification of the authenticated principal for a given request.
 *
 * Determines which authorization rules and scoping rules apply.
 * Derived once by TenantContextResolver and embedded in the immutable TenantContext.
 */
enum PrincipalKind: string
{
    /**
     * Authenticated user operating in the tenant plane (staff, receptionist, manager, etc.).
     * Requires resolved organization_id + branch_id for tenant-owned data access.
     */
    case TENANT = 'tenant';

    /**
     * Platform founder operating in the control plane — not impersonating a tenant.
     * Not branch-scoped via the tenant context kernel; platform routes govern access separately.
     */
    case FOUNDER = 'founder';

    /**
     * Platform founder operating AS a tenant user via support/impersonation entry.
     * actorId = the tenant user being impersonated.
     * supportActorId = the real founder performing the operation (used for audit).
     * Requires resolved organization_id + branch_id (same as TENANT).
     */
    case SUPPORT_ACTOR = 'support_actor';

    /**
     * Unauthenticated / anonymous / guest request (public API, public booking, no session).
     * actorId = 0. No tenant scope. tenantContextRequired = false.
     */
    case GUEST = 'guest';

    /**
     * Internal system / CLI / background job. Not an HTTP actor.
     * Requires an explicit named context from the calling job — not derived from session.
     */
    case SYSTEM = 'system';
}
