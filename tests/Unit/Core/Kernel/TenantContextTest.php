<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Kernel;

use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\PrincipalKind;
use Core\Kernel\TenantContext;
use Core\Kernel\UnresolvedTenantContextException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TenantContext immutable value object.
 *
 * These tests exercise the named constructors and fail-closed access contract
 * without any database or HTTP dependencies.
 */
final class TenantContextTest extends TestCase
{
    // -------------------------------------------------------------------------
    // resolvedTenant() — happy path
    // -------------------------------------------------------------------------

    public function testResolvedTenantSetsCorrectFields(): void
    {
        $ctx = TenantContext::resolvedTenant(
            actorId: 42,
            organizationId: 10,
            branchId: 20,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            organizationResolutionMode: 'branch_derived',
        );

        self::assertSame(42, $ctx->actorId);
        self::assertSame(PrincipalKind::TENANT, $ctx->principalKind);
        self::assertSame(10, $ctx->organizationId);
        self::assertSame(20, $ctx->branchId);
        self::assertFalse($ctx->isSupportEntry);
        self::assertNull($ctx->supportActorId);
        self::assertSame(AssuranceLevel::SESSION, $ctx->assuranceLevel);
        self::assertSame(ExecutionSurface::HTTP_TENANT, $ctx->executionSurface);
        self::assertTrue($ctx->tenantContextRequired);
        self::assertTrue($ctx->tenantContextResolved);
        self::assertNull($ctx->unresolvedReason);
        self::assertSame('branch_derived', $ctx->organizationResolutionMode);
    }

    public function testResolvedTenantRequireResolvedTenantReturnsIds(): void
    {
        $ctx = TenantContext::resolvedTenant(
            actorId: 5,
            organizationId: 7,
            branchId: 8,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            organizationResolutionMode: 'branch_derived',
        );

        $ids = $ctx->requireResolvedTenant();

        self::assertSame(['organization_id' => 7, 'branch_id' => 8], $ids);
    }

    public function testResolvedTenantAuditActorIdIsActorIdWhenNotSupportEntry(): void
    {
        $ctx = TenantContext::resolvedTenant(
            actorId: 99,
            organizationId: 1,
            branchId: 2,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            organizationResolutionMode: 'branch_derived',
        );

        self::assertSame(99, $ctx->auditActorId());
        self::assertTrue($ctx->isAuthenticated());
        self::assertFalse($ctx->isFounderOrSupportActor());
    }

    // -------------------------------------------------------------------------
    // resolvedTenant() — support entry
    // -------------------------------------------------------------------------

    public function testResolvedTenantSupportEntrySetsCorrectFields(): void
    {
        $ctx = TenantContext::resolvedTenant(
            actorId: 55,
            organizationId: 10,
            branchId: 20,
            isSupportEntry: true,
            supportActorId: 1,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            organizationResolutionMode: 'branch_derived',
        );

        self::assertSame(PrincipalKind::SUPPORT_ACTOR, $ctx->principalKind);
        self::assertTrue($ctx->isSupportEntry);
        self::assertSame(1, $ctx->supportActorId);
        // auditActorId() returns the real founder, not the impersonated user
        self::assertSame(1, $ctx->auditActorId());
        self::assertTrue($ctx->isFounderOrSupportActor());
    }

    // -------------------------------------------------------------------------
    // founderControlPlane()
    // -------------------------------------------------------------------------

    public function testFounderControlPlaneContext(): void
    {
        $ctx = TenantContext::founderControlPlane(
            actorId: 1,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_PLATFORM,
        );

        self::assertSame(PrincipalKind::FOUNDER, $ctx->principalKind);
        self::assertNull($ctx->organizationId);
        self::assertNull($ctx->branchId);
        self::assertFalse($ctx->tenantContextRequired);
        self::assertFalse($ctx->tenantContextResolved);
        self::assertNotNull($ctx->unresolvedReason);
        self::assertTrue($ctx->isFounderOrSupportActor());
        self::assertTrue($ctx->isAuthenticated());
    }

    public function testFounderControlPlaneRequireResolvedTenantThrows(): void
    {
        $ctx = TenantContext::founderControlPlane(
            actorId: 1,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_PLATFORM,
        );

        $this->expectException(UnresolvedTenantContextException::class);
        $ctx->requireResolvedTenant();
    }

    // -------------------------------------------------------------------------
    // guest()
    // -------------------------------------------------------------------------

    public function testGuestContextDefaults(): void
    {
        $ctx = TenantContext::guest();

        self::assertSame(0, $ctx->actorId);
        self::assertSame(PrincipalKind::GUEST, $ctx->principalKind);
        self::assertSame(AssuranceLevel::NONE, $ctx->assuranceLevel);
        self::assertSame(ExecutionSurface::HTTP_PUBLIC, $ctx->executionSurface);
        self::assertFalse($ctx->tenantContextRequired);
        self::assertFalse($ctx->tenantContextResolved);
        self::assertFalse($ctx->isAuthenticated());
        self::assertFalse($ctx->isFounderOrSupportActor());
    }

    public function testGuestRequireResolvedTenantThrows(): void
    {
        $ctx = TenantContext::guest();

        $this->expectException(UnresolvedTenantContextException::class);
        $ctx->requireResolvedTenant();
    }

    // -------------------------------------------------------------------------
    // unresolvedAuthenticated()
    // -------------------------------------------------------------------------

    public function testUnresolvedAuthenticatedContextPreservesReason(): void
    {
        $reason = 'No branch selected in session';
        $ctx = TenantContext::unresolvedAuthenticated(
            actorId: 10,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            reason: $reason,
        );

        self::assertSame(10, $ctx->actorId);
        self::assertTrue($ctx->tenantContextRequired);
        self::assertFalse($ctx->tenantContextResolved);
        self::assertSame($reason, $ctx->unresolvedReason);
        self::assertTrue($ctx->isAuthenticated());
    }

    public function testUnresolvedAuthenticatedRequireResolvedTenantThrowsWithReason(): void
    {
        $reason = 'Branch context missing';
        $ctx = TenantContext::unresolvedAuthenticated(
            actorId: 10,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            reason: $reason,
        );

        $this->expectException(UnresolvedTenantContextException::class);
        $this->expectExceptionMessage($reason);
        $ctx->requireResolvedTenant();
    }
}
