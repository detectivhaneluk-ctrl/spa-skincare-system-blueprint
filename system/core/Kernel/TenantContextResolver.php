<?php

declare(strict_types=1);

namespace Core\Kernel;

use Core\Auth\PrincipalAccessService;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;

/**
 * Single designated resolver for TenantContext.
 *
 * Architecture contract (FOUNDATION-A1):
 * - Context is resolved EXACTLY ONCE per request at the edge.
 * - Called exclusively by TenantContextMiddleware after BranchContextMiddleware
 *   and OrganizationContextMiddleware have already resolved their singletons.
 * - Zero new DB queries: all inputs come from already-resolved request-scoped singletons.
 * - No tenant context is derived mid-request outside this class.
 * - Scattered ad-hoc resolution in individual services is forbidden for new code.
 *
 * Integration seam:
 * Reads from existing resolved singletons (SessionAuth, BranchContext, OrganizationContext,
 * PrincipalAccessService) — does not duplicate their resolution logic, only consolidates
 * their outputs into a single immutable snapshot. Existing code paths that set those
 * singletons are not modified.
 *
 * Support / impersonation:
 * When SessionAuth::isSupportEntryActive() is true, the session user IS the impersonated
 * tenant user. The real founder is stored as SessionAuth::supportActorUserId(). This resolver
 * correctly captures both when building a SUPPORT_ACTOR context.
 */
final class TenantContextResolver
{
    public function __construct(
        private SessionAuth $sessionAuth,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private PrincipalAccessService $principalAccess,
    ) {
    }

    /**
     * Resolve the TenantContext for the current HTTP request.
     * Must be called after BranchContextMiddleware and OrganizationContextMiddleware.
     */
    public function resolveForHttpRequest(): TenantContext
    {
        $userId = $this->sessionAuth->id();

        // --- Unauthenticated / guest ---
        if ($userId === null || $userId <= 0) {
            return TenantContext::guest(ExecutionSurface::HTTP_PUBLIC);
        }

        $assurance = AssuranceLevel::SESSION;

        // --- Support entry: founder impersonating a tenant user ---
        // Check BEFORE isPlatformPrincipal because during support entry the effective
        // session user is the tenant user, but the support actor key reveals the founder.
        if ($this->sessionAuth->isSupportEntryActive()) {
            return $this->resolveAsSupportActor($userId, $assurance);
        }

        // --- Platform founder (control plane, no support entry) ---
        if ($this->principalAccess->isPlatformPrincipal($userId)) {
            return TenantContext::founderControlPlane($userId, $assurance, ExecutionSurface::HTTP_PLATFORM);
        }

        // --- Tenant plane ---
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        $branchId = $this->branchContext->getCurrentBranchId();
        $mode = $this->organizationContext->getResolutionMode();

        if (
            $orgId !== null && $orgId > 0
            && $branchId !== null && $branchId > 0
            && $mode === OrganizationContext::MODE_BRANCH_DERIVED
        ) {
            return TenantContext::resolvedTenant(
                actorId: $userId,
                organizationId: $orgId,
                branchId: $branchId,
                isSupportEntry: false,
                supportActorId: null,
                assuranceLevel: $assurance,
                executionSurface: ExecutionSurface::HTTP_TENANT,
                organizationResolutionMode: $mode,
            );
        }

        // Authenticated tenant user but context not fully resolved.
        $reason = $this->buildUnresolvedReason($orgId, $branchId, $mode);

        return TenantContext::unresolvedAuthenticated($userId, $assurance, ExecutionSurface::HTTP_TENANT, $reason);
    }

    private function resolveAsSupportActor(int $tenantUserId, AssuranceLevel $assurance): TenantContext
    {
        $supportActorId = $this->sessionAuth->supportActorUserId();
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        $branchId = $this->branchContext->getCurrentBranchId();
        $mode = $this->organizationContext->getResolutionMode();

        if (
            $orgId !== null && $orgId > 0
            && $branchId !== null && $branchId > 0
            && $mode === OrganizationContext::MODE_BRANCH_DERIVED
        ) {
            return TenantContext::resolvedTenant(
                actorId: $tenantUserId,
                organizationId: $orgId,
                branchId: $branchId,
                isSupportEntry: true,
                supportActorId: $supportActorId,
                assuranceLevel: $assurance,
                executionSurface: ExecutionSurface::HTTP_TENANT,
                organizationResolutionMode: $mode,
            );
        }

        $reason = 'Support entry: ' . $this->buildUnresolvedReason($orgId, $branchId, $mode);

        return TenantContext::unresolvedAuthenticated($tenantUserId, $assurance, ExecutionSurface::HTTP_TENANT, $reason);
    }

    private function buildUnresolvedReason(?int $orgId, ?int $branchId, ?string $mode): string
    {
        if ($branchId === null || $branchId <= 0) {
            return 'Branch context not resolved — user has no active branch selection';
        }
        if ($orgId === null || $orgId <= 0) {
            return 'Organization context not resolved';
        }
        if ($mode !== OrganizationContext::MODE_BRANCH_DERIVED) {
            return sprintf('Organization resolution mode is not branch_derived (actual: %s)', $mode ?? 'null');
        }

        return 'Tenant context resolution failed (unexpected state)';
    }
}
