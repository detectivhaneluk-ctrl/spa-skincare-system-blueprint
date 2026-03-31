<?php

declare(strict_types=1);

namespace Core\Kernel;

/**
 * Immutable, resolved capability context for a protected request.
 *
 * Created exactly once per request by {@see TenantContextResolver} and stored
 * in {@see RequestContextHolder}. Once set, it MUST NOT change within a request lifecycle.
 *
 * Design contracts:
 * - All fields are readonly and set only at construction time.
 * - Protected operations that access tenant-owned data MUST call requireResolvedTenant()
 *   before using organizationId / branchId. Fail-closed: throws UnresolvedTenantContextException.
 * - Support/impersonation mode is first-class: actorId is always the effective session user;
 *   supportActorId is the real founder. Audit records use auditActorId().
 * - The kernel introduces zero new DB queries: all fields are derived from existing
 *   request-scoped singletons (BranchContext, OrganizationContext, SessionAuth).
 *
 * Future compatibility:
 * - PostgreSQL RLS: organizationId/branchId from this context are the binding values.
 * - ReBAC: principalKind + actorId are the subject; resourceId is the object.
 * - Observable: all fields are accessible for structured audit/trace logging.
 */
final class TenantContext
{
    private function __construct(
        /**
         * Effective actor for this request (session user id).
         * During support entry: this is the TENANT user being impersonated, not the founder.
         * Zero (0) for unauthenticated / guest contexts.
         */
        public readonly int $actorId,

        /**
         * Principal classification. Determines which authorization rules apply.
         */
        public readonly PrincipalKind $principalKind,

        /**
         * Resolved organization id. Non-null only when tenantContextResolved is true.
         * Must not be used directly — always call requireResolvedTenant() first.
         */
        public readonly ?int $organizationId,

        /**
         * Resolved branch id. Non-null only when tenantContextResolved is true.
         * Must not be used directly — always call requireResolvedTenant() first.
         */
        public readonly ?int $branchId,

        /**
         * True when the founder is operating as a tenant user (support/impersonation entry).
         * When true: actorId = tenant user; supportActorId = real founder performing the action.
         */
        public readonly bool $isSupportEntry,

        /**
         * The platform founder's user id during support entry.
         * Null when not in support entry mode.
         * Use auditActorId() to get the correct actor id for audit records.
         */
        public readonly ?int $supportActorId,

        /**
         * Authentication assurance level established at session creation time.
         * Current sessions are SESSION. MFA levels are future (PLT-MFA-01).
         */
        public readonly AssuranceLevel $assuranceLevel,

        /**
         * Request origin surface. Differentiates HTTP, CLI, and background contexts.
         */
        public readonly ExecutionSurface $executionSurface,

        /**
         * Whether the current request surface REQUIRES a resolved tenant context to proceed.
         * True for tenant-internal routes. False for platform, public, and background surfaces.
         * When true and tenantContextResolved is false, requireResolvedTenant() throws.
         */
        public readonly bool $tenantContextRequired,

        /**
         * Whether organizationId + branchId are fully resolved and trustworthy for tenant data access.
         * This is the gate: MUST be checked before ANY tenant-scoped data operation.
         * True only when OrganizationContext::MODE_BRANCH_DERIVED is active.
         */
        public readonly bool $tenantContextResolved,

        /**
         * Human-readable reason when tenantContextResolved is false.
         * Null when resolved. Used for structured error messages and trace logs.
         */
        public readonly ?string $unresolvedReason,

        /**
         * OrganizationContext resolution mode preserved for observability and diagnostics.
         * See OrganizationContext::MODE_* constants.
         */
        public readonly ?string $organizationResolutionMode,
    ) {
    }

    // -------------------------------------------------------------------------
    // Named constructors — the only way to create a TenantContext
    // -------------------------------------------------------------------------

    /**
     * Fully resolved tenant context for an authenticated tenant user (or support-entry actor).
     * Requires MODE_BRANCH_DERIVED organization resolution with positive org + branch ids.
     *
     * @param bool  $isSupportEntry  True when a founder is operating as this tenant user.
     * @param int|null $supportActorId  The real founder user id during support entry.
     */
    public static function resolvedTenant(
        int $actorId,
        int $organizationId,
        int $branchId,
        bool $isSupportEntry,
        ?int $supportActorId,
        AssuranceLevel $assuranceLevel,
        ExecutionSurface $executionSurface,
        string $organizationResolutionMode,
    ): self {
        return new self(
            actorId: $actorId,
            principalKind: $isSupportEntry ? PrincipalKind::SUPPORT_ACTOR : PrincipalKind::TENANT,
            organizationId: $organizationId,
            branchId: $branchId,
            isSupportEntry: $isSupportEntry,
            supportActorId: $isSupportEntry ? $supportActorId : null,
            assuranceLevel: $assuranceLevel,
            executionSurface: $executionSurface,
            tenantContextRequired: true,
            tenantContextResolved: true,
            unresolvedReason: null,
            organizationResolutionMode: $organizationResolutionMode,
        );
    }

    /**
     * Platform founder context — control plane, not branch-scoped by the tenant kernel.
     * Tenant context is NOT required for platform routes; tenantContextResolved = false.
     */
    public static function founderControlPlane(
        int $actorId,
        AssuranceLevel $assuranceLevel,
        ExecutionSurface $executionSurface,
    ): self {
        return new self(
            actorId: $actorId,
            principalKind: PrincipalKind::FOUNDER,
            organizationId: null,
            branchId: null,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: $assuranceLevel,
            executionSurface: $executionSurface,
            tenantContextRequired: false,
            tenantContextResolved: false,
            unresolvedReason: 'Platform principal is not branch-scoped via tenant kernel',
            organizationResolutionMode: null,
        );
    }

    /**
     * Unauthenticated / guest context.
     * actorId = 0, assurance = NONE, tenantContextRequired = false.
     */
    public static function guest(ExecutionSurface $executionSurface = ExecutionSurface::HTTP_PUBLIC): self
    {
        return new self(
            actorId: 0,
            principalKind: PrincipalKind::GUEST,
            organizationId: null,
            branchId: null,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::NONE,
            executionSurface: $executionSurface,
            tenantContextRequired: false,
            tenantContextResolved: false,
            unresolvedReason: 'Unauthenticated request — no session',
            organizationResolutionMode: null,
        );
    }

    /**
     * Authenticated tenant user but org/branch context could not be resolved.
     * tenantContextRequired = true; tenantContextResolved = false.
     * requireResolvedTenant() will throw with the given reason.
     */
    public static function unresolvedAuthenticated(
        int $actorId,
        AssuranceLevel $assuranceLevel,
        ExecutionSurface $executionSurface,
        string $reason,
    ): self {
        return new self(
            actorId: $actorId,
            principalKind: PrincipalKind::TENANT,
            organizationId: null,
            branchId: null,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: $assuranceLevel,
            executionSurface: $executionSurface,
            tenantContextRequired: true,
            tenantContextResolved: false,
            unresolvedReason: $reason,
            organizationResolutionMode: null,
        );
    }

    // -------------------------------------------------------------------------
    // Fail-closed access contract
    // -------------------------------------------------------------------------

    /**
     * Fail-closed gate for tenant-owned data operations.
     *
     * Returns [organization_id, branch_id] when context is fully resolved.
     * Throws UnresolvedTenantContextException when context is absent or unresolved.
     *
     * All protected repository and service operations that touch tenant-owned rows
     * MUST call this before using organizationId or branchId.
     *
     * @return array{organization_id: int, branch_id: int}
     * @throws UnresolvedTenantContextException
     */
    public function requireResolvedTenant(): array
    {
        if (!$this->tenantContextResolved || $this->organizationId === null || $this->branchId === null) {
            throw new UnresolvedTenantContextException(
                $this->unresolvedReason ?? 'Tenant context is required but was not resolved.'
            );
        }

        return ['organization_id' => $this->organizationId, 'branch_id' => $this->branchId];
    }

    // -------------------------------------------------------------------------
    // Audit and identity helpers
    // -------------------------------------------------------------------------

    /**
     * The user id to record as the real actor in audit logs.
     *
     * During support entry: returns the founder (the human performing the operation).
     * Otherwise: returns actorId (the session user).
     *
     * This mirrors {@see \Core\Auth\SessionAuth::auditActorUserId()} but is available
     * on the immutable context object without re-querying the session.
     */
    public function auditActorId(): int
    {
        if ($this->isSupportEntry && $this->supportActorId !== null && $this->supportActorId > 0) {
            return $this->supportActorId;
        }

        return $this->actorId;
    }

    /**
     * True for any authenticated request (actorId > 0).
     */
    public function isAuthenticated(): bool
    {
        return $this->actorId > 0;
    }

    /**
     * True when the actor is a platform founder (directly or via support entry).
     */
    public function isFounderOrSupportActor(): bool
    {
        return $this->principalKind === PrincipalKind::FOUNDER
            || $this->principalKind === PrincipalKind::SUPPORT_ACTOR;
    }
}
