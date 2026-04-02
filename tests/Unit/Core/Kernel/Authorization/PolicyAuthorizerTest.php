<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Kernel\Authorization;

use Core\Kernel\AssuranceLevel;
use Core\Kernel\Authorization\AuthorizationException;
use Core\Kernel\Authorization\PolicyAuthorizer;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\TenantContext;
use Core\Permissions\PermissionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PolicyAuthorizer critical-path authorization logic.
 *
 * Covers paths that short-circuit before PermissionService::has() is called:
 * - FOUNDER principal: platform-only actions ALLOW; tenant-scoped actions DENY (unresolved)
 * - SUPPORT_ACTOR principal: read-allow / write-block
 * - Unresolved TenantContext: always-deny
 * - Guest context: always-deny
 * - Platform-only actions: FOUNDER allow, others deny
 * - Tenant principal unmapped-action deny-by-default (no PermissionService call)
 *
 * PermissionService is constructed via ReflectionClass::newInstanceWithoutConstructor()
 * because it is a final class with DB dependencies. Any test that accidentally invokes
 * PermissionService::has() will fail with an uninitialized property error — desired
 * fail-closed behavior for tests of paths that must NOT call into the permission store.
 *
 * Architecture note (documented for FND-TST-04):
 * The PolicyAuthorizer `match` arm `PrincipalKind::FOUNDER => allow('founder_tenant_policy')`
 * for tenant-scoped actions is currently unreachable through production TenantContext named
 * constructors: founderControlPlane() always has tenantContextResolved=false (denied before
 * the match), and resolvedTenant() always produces TENANT or SUPPORT_ACTOR. This arm is
 * reserved for a future TenantContext::founderWithTenantScope() constructor. Tests verify
 * actual reachable behavior — no reflection-fabricated impossible states.
 */
final class PolicyAuthorizerTest extends TestCase
{
    private PolicyAuthorizer $authorizer;
    private ResourceRef $anyResource;

    protected function setUp(): void
    {
        // PermissionService is final — bypass constructor for paths that never call has().
        // If has() is accidentally called, uninitialized properties cause a TypeError: fail-closed.
        /** @var PermissionService $permSvc */
        $permSvc = (new \ReflectionClass(PermissionService::class))->newInstanceWithoutConstructor();
        $this->authorizer = new PolicyAuthorizer($permSvc);
        $this->anyResource = ResourceRef::collection('appointment');
    }

    // -------------------------------------------------------------------------
    // FOUNDER principal — platform-only actions
    // -------------------------------------------------------------------------

    public function testFounderAllowsPlatformSupportEntry(): void
    {
        $ctx = TenantContext::founderControlPlane(
            actorId: 1,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_PLATFORM,
        );

        $decision = $this->authorizer->authorize($ctx, ResourceAction::PLATFORM_SUPPORT_ENTRY, $this->anyResource);

        self::assertTrue($decision->isAllowed(), 'FOUNDER must be allowed PLATFORM_SUPPORT_ENTRY');
        self::assertStringContainsString('founder_platform_policy', $decision->reason);
    }

    public function testFounderAllowsPlatformOrgManage(): void
    {
        $ctx = TenantContext::founderControlPlane(
            actorId: 1,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_PLATFORM,
        );

        $decision = $this->authorizer->authorize($ctx, ResourceAction::PLATFORM_ORG_MANAGE, $this->anyResource);

        self::assertTrue($decision->isAllowed(), 'FOUNDER must be allowed PLATFORM_ORG_MANAGE');
        self::assertStringContainsString('founder_platform_policy', $decision->reason);
    }

    /**
     * FOUNDER control-plane context has tenantContextResolved=false.
     * Tenant-scoped actions must deny before reaching the principal match.
     * The 'founder_tenant_policy' allow path is reserved for a future constructor.
     */
    public function testFounderControlPlaneCtxDeniesAllTenantScopedActions(): void
    {
        $ctx = TenantContext::founderControlPlane(
            actorId: 1,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_PLATFORM,
        );

        $decision = $this->authorizer->authorize($ctx, ResourceAction::APPOINTMENT_VIEW, $this->anyResource);

        self::assertTrue($decision->isDenied(), 'FOUNDER control-plane context must deny tenant-scoped actions');
        self::assertStringContainsString('tenant_context_unresolved', $decision->reason);
    }

    // -------------------------------------------------------------------------
    // Non-FOUNDER principals: platform-only actions always denied
    // -------------------------------------------------------------------------

    public function testTenantPrincipalDeniedPlatformSupportEntry(): void
    {
        $ctx = TenantContext::resolvedTenant(
            actorId: 1,
            organizationId: 10,
            branchId: 20,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            organizationResolutionMode: 'branch_derived',
        );

        $decision = $this->authorizer->authorize($ctx, ResourceAction::PLATFORM_SUPPORT_ENTRY, $this->anyResource);

        self::assertTrue($decision->isDenied(), 'TENANT principal must be denied PLATFORM_SUPPORT_ENTRY');
        self::assertStringContainsString('platform_action_requires_founder', $decision->reason);
    }

    // -------------------------------------------------------------------------
    // SUPPORT_ACTOR principal: read-allow, write-block
    // -------------------------------------------------------------------------

    public function testSupportActorAllowsReadAction(): void
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

        $decision = $this->authorizer->authorize($ctx, ResourceAction::APPOINTMENT_VIEW, $this->anyResource);

        self::assertTrue($decision->isAllowed(), 'SUPPORT_ACTOR must be allowed to view appointments');
        self::assertSame('support_actor_read_policy', $decision->reason);
    }

    public function testSupportActorBlocksWriteAction(): void
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

        $decision = $this->authorizer->authorize($ctx, ResourceAction::APPOINTMENT_CREATE, $this->anyResource);

        self::assertTrue($decision->isDenied(), 'SUPPORT_ACTOR must be denied write actions');
        self::assertStringContainsString('support_actor_write_blocked', $decision->reason);
        self::assertStringContainsString('appointment:create', $decision->reason);
    }

    public function testSupportActorRequireAuthorizedThrowsOnWriteAction(): void
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

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessageMatches('/support_actor_write_blocked/');

        $this->authorizer->requireAuthorized(
            $ctx,
            ResourceAction::CLIENT_CREATE,
            ResourceRef::collection('client')
        );
    }

    // -------------------------------------------------------------------------
    // Unresolved context always-deny
    // -------------------------------------------------------------------------

    public function testUnresolvedContextDeniesAllTenantScopedActions(): void
    {
        $ctx = TenantContext::unresolvedAuthenticated(
            actorId: 10,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            reason: 'no_branch_in_session',
        );

        $decision = $this->authorizer->authorize($ctx, ResourceAction::APPOINTMENT_VIEW, $this->anyResource);

        self::assertTrue($decision->isDenied(), 'Unresolved context must deny all tenant actions');
        self::assertStringContainsString('tenant_context_unresolved', $decision->reason);
        self::assertStringContainsString('no_branch_in_session', $decision->reason);
    }

    // -------------------------------------------------------------------------
    // Guest context always-deny
    // -------------------------------------------------------------------------

    public function testGuestContextDeniesAllTenantScopedActions(): void
    {
        $ctx = TenantContext::guest();

        $decision = $this->authorizer->authorize($ctx, ResourceAction::APPOINTMENT_VIEW, $this->anyResource);

        self::assertTrue($decision->isDenied(), 'Guest context must deny all actions');
    }

    public function testGuestContextDeniesAllPlatformActionsAlso(): void
    {
        $ctx = TenantContext::guest();

        $decision = $this->authorizer->authorize($ctx, ResourceAction::PLATFORM_SUPPORT_ENTRY, $this->anyResource);

        self::assertTrue($decision->isDenied(), 'Guest context must deny platform actions');
        self::assertStringContainsString('platform_action_requires_founder', $decision->reason);
    }

    // -------------------------------------------------------------------------
    // Tenant principal: platform-only action mapped to null => deny for non-FOUNDER
    // -------------------------------------------------------------------------

    public function testTenantPrincipalDeniedPlatformOrgManageWithCorrectReason(): void
    {
        $ctx = TenantContext::resolvedTenant(
            actorId: 5,
            organizationId: 10,
            branchId: 20,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::HTTP_TENANT,
            organizationResolutionMode: 'branch_derived',
        );

        $decision = $this->authorizer->authorize($ctx, ResourceAction::PLATFORM_ORG_MANAGE, $this->anyResource);

        self::assertTrue($decision->isDenied());
        self::assertStringContainsString('platform_action_requires_founder', $decision->reason);
    }
}
