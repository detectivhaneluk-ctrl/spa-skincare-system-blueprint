<?php

declare(strict_types=1);

/**
 * FOUNDATION-A1/A2 Kernel Verification Script
 *
 * Sharp proof for kernel contracts. No DB required. Run with:
 *   php system/scripts/read-only/verify_kernel_tenant_context_01.php
 *
 * Contracts verified:
 *  1. TenantContext::resolvedTenant() — produces correct immutable fields
 *  2. requireResolvedTenant() — returns scope when resolved
 *  3. requireResolvedTenant() — throws UnresolvedTenantContextException when unresolved
 *  4. unresolvedAuthenticated context — tenant context required, fail-closed
 *  5. founderControlPlane context — not tenant-required, not resolved
 *  6. guest context — no auth, not tenant-required
 *  7. support/impersonation — correct actorId + auditActorId() split
 *  8. Immutability — no public mutators on TenantContext
 *  9. DenyAllAuthorizer — denies all actions, never returns ALLOW
 * 10. AccessDecision::orThrow() — throws AuthorizationException on denial
 * 11. AccessDecision::allow() — does NOT throw
 * 12. ResourceRef — collection and instance constructors
 * 13. RequestContextHolder — reset/set/requireContext
 * 14. RequestContextHolder::requireContext() throws when empty
 */

require_once __DIR__ . '/../../bootstrap.php';
// Do not load modules/bootstrap.php — kernel has no module dependencies.

use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\PrincipalKind;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Kernel\UnresolvedTenantContextException;
use Core\Kernel\Authorization\AccessDecision;
use Core\Kernel\Authorization\AuthorizationException;
use Core\Kernel\Authorization\DenyAllAuthorizer;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  PASS  {$label}\n";
        $passed++;
    } else {
        echo "  FAIL  {$label}\n";
        $failed++;
    }
}

function assert_throws(callable $fn, string $expectedClass, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  FAIL  {$label} — expected exception {$expectedClass} but none was thrown\n";
        $failed++;
    } catch (\Throwable $e) {
        if ($e instanceof $expectedClass) {
            echo "  PASS  {$label}\n";
            $passed++;
        } else {
            echo "  FAIL  {$label} — got " . get_class($e) . ": " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

function assert_not_throws(callable $fn, string $label): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  {$label}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$label} — unexpected exception " . get_class($e) . ": " . $e->getMessage() . "\n";
        $failed++;
    }
}

// =============================================================================
// 1–2. resolvedTenant context
// =============================================================================
echo "\n[Contract 1-2] TenantContext::resolvedTenant — correct fields\n";

$ctx = TenantContext::resolvedTenant(
    actorId: 42,
    organizationId: 10,
    branchId: 5,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::HTTP_TENANT,
    organizationResolutionMode: 'branch_derived',
);

assert_true($ctx->actorId === 42, 'actorId is 42');
assert_true($ctx->organizationId === 10, 'organizationId is 10');
assert_true($ctx->branchId === 5, 'branchId is 5');
assert_true($ctx->principalKind === PrincipalKind::TENANT, 'principalKind is TENANT');
assert_true($ctx->tenantContextResolved === true, 'tenantContextResolved is true');
assert_true($ctx->tenantContextRequired === true, 'tenantContextRequired is true');
assert_true($ctx->isSupportEntry === false, 'isSupportEntry is false');
assert_true($ctx->supportActorId === null, 'supportActorId is null');
assert_true($ctx->assuranceLevel === AssuranceLevel::SESSION, 'assuranceLevel is SESSION');
assert_true($ctx->executionSurface === ExecutionSurface::HTTP_TENANT, 'executionSurface is HTTP_TENANT');
assert_true($ctx->unresolvedReason === null, 'unresolvedReason is null');
assert_true($ctx->isAuthenticated(), 'isAuthenticated returns true');
assert_true(!$ctx->isFounderOrSupportActor(), 'isFounderOrSupportActor returns false for TENANT');

echo "\n[Contract 2] requireResolvedTenant() returns correct scope when resolved\n";
assert_not_throws(function () use ($ctx) {
    $scope = $ctx->requireResolvedTenant();
    assert_true($scope['organization_id'] === 10, 'scope organization_id is 10');
    assert_true($scope['branch_id'] === 5, 'scope branch_id is 5');
}, 'requireResolvedTenant does not throw on resolved context');

// =============================================================================
// 3–4. unresolved contexts fail closed
// =============================================================================
echo "\n[Contract 3-4] unresolvedAuthenticated — requireResolvedTenant throws\n";

$unresolved = TenantContext::unresolvedAuthenticated(
    actorId: 99,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::HTTP_TENANT,
    reason: 'Branch context not resolved — user has no active branch selection',
);

assert_true($unresolved->tenantContextResolved === false, 'tenantContextResolved is false');
assert_true($unresolved->tenantContextRequired === true, 'tenantContextRequired is true for tenant user');
assert_true($unresolved->unresolvedReason !== null, 'unresolvedReason is set');
assert_true($unresolved->actorId === 99, 'actorId is preserved on unresolved context');

assert_throws(
    fn () => $unresolved->requireResolvedTenant(),
    UnresolvedTenantContextException::class,
    'requireResolvedTenant throws UnresolvedTenantContextException when unresolved'
);

// =============================================================================
// 5. founderControlPlane — not tenant-required
// =============================================================================
echo "\n[Contract 5] founderControlPlane — not tenant-scoped\n";

$founderCtx = TenantContext::founderControlPlane(
    actorId: 1,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::HTTP_PLATFORM,
);

assert_true($founderCtx->principalKind === PrincipalKind::FOUNDER, 'principalKind is FOUNDER');
assert_true($founderCtx->tenantContextRequired === false, 'tenantContextRequired is false for founder');
assert_true($founderCtx->tenantContextResolved === false, 'tenantContextResolved is false for founder');
assert_true($founderCtx->organizationId === null, 'organizationId is null for founder');
assert_true($founderCtx->branchId === null, 'branchId is null for founder');
assert_true($founderCtx->isFounderOrSupportActor(), 'isFounderOrSupportActor returns true for FOUNDER');
assert_true($founderCtx->auditActorId() === 1, 'auditActorId equals actorId for non-support-entry founder');

// =============================================================================
// 6. guest context
// =============================================================================
echo "\n[Contract 6] guest context\n";

$guest = TenantContext::guest();

assert_true($guest->actorId === 0, 'guest actorId is 0');
assert_true($guest->principalKind === PrincipalKind::GUEST, 'principalKind is GUEST');
assert_true($guest->assuranceLevel === AssuranceLevel::NONE, 'assuranceLevel is NONE');
assert_true($guest->tenantContextRequired === false, 'tenantContextRequired is false for guest');
assert_true(!$guest->isAuthenticated(), 'isAuthenticated returns false for guest');

assert_throws(
    fn () => $guest->requireResolvedTenant(),
    UnresolvedTenantContextException::class,
    'guest requireResolvedTenant throws even though not required (fail-closed guard)'
);

// =============================================================================
// 7. support/impersonation — auditActorId split
// =============================================================================
echo "\n[Contract 7] support entry — actorId is tenant user; auditActorId is founder\n";

$supportCtx = TenantContext::resolvedTenant(
    actorId: 77,       // tenant user
    organizationId: 10,
    branchId: 5,
    isSupportEntry: true,
    supportActorId: 1, // real founder
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::HTTP_TENANT,
    organizationResolutionMode: 'branch_derived',
);

assert_true($supportCtx->actorId === 77, 'actorId is the tenant user (77)');
assert_true($supportCtx->supportActorId === 1, 'supportActorId is the founder (1)');
assert_true($supportCtx->isSupportEntry === true, 'isSupportEntry is true');
assert_true($supportCtx->principalKind === PrincipalKind::SUPPORT_ACTOR, 'principalKind is SUPPORT_ACTOR');
assert_true($supportCtx->auditActorId() === 1, 'auditActorId returns the founder (1) during support entry');
assert_true($supportCtx->tenantContextResolved === true, 'support entry with resolved branch is still resolved');
assert_true($supportCtx->isFounderOrSupportActor(), 'isFounderOrSupportActor returns true for SUPPORT_ACTOR');

// =============================================================================
// 8. Immutability — readonly properties cannot be mutated
// =============================================================================
echo "\n[Contract 8] TenantContext fields are readonly\n";

$reflector = new \ReflectionClass(TenantContext::class);
$props = $reflector->getProperties(\ReflectionProperty::IS_PUBLIC);
$allReadonly = true;
foreach ($props as $prop) {
    if (!$prop->isReadOnly()) {
        $allReadonly = false;
        echo "  FAIL  Property {$prop->getName()} is NOT readonly\n";
    }
}
if ($allReadonly) {
    assert_true(true, 'All public TenantContext properties are readonly');
}

$ctor = $reflector->getConstructor();
assert_true($ctor !== null && !$ctor->isPublic(), 'TenantContext constructor is not public (named constructors only)');

// =============================================================================
// 9. DenyAllAuthorizer — denies all actions
// =============================================================================
echo "\n[Contract 9] DenyAllAuthorizer denies all actions\n";

$authorizer = new DenyAllAuthorizer();
$tenantCtx = TenantContext::resolvedTenant(
    actorId: 42,
    organizationId: 10,
    branchId: 5,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::HTTP_TENANT,
    organizationResolutionMode: 'branch_derived',
);

$actionsToTest = [
    ResourceAction::APPOINTMENT_VIEW,
    ResourceAction::APPOINTMENT_MODIFY,
    ResourceAction::CLIENT_VIEW,
    ResourceAction::INVOICE_CREATE,
    ResourceAction::PROFILE_IMAGE_UPLOAD,
    ResourceAction::PLATFORM_SUPPORT_ENTRY,
];

foreach ($actionsToTest as $action) {
    $decision = $authorizer->authorize($tenantCtx, $action, ResourceRef::collection($action->value));
    assert_true($decision->isDenied(), "DenyAllAuthorizer denies action {$action->value}");
    assert_true(!$decision->isAllowed(), "DenyAllAuthorizer does not allow action {$action->value}");
}

// Founder context also denied
$founderDecision = $authorizer->authorize($founderCtx, ResourceAction::PLATFORM_ORG_MANAGE, ResourceRef::collection('organization'));
assert_true($founderDecision->isDenied(), 'DenyAllAuthorizer denies founder PLATFORM_ORG_MANAGE (no policy defined)');

// =============================================================================
// 10. AccessDecision::orThrow — throws AuthorizationException on denial
// =============================================================================
echo "\n[Contract 10] AccessDecision::orThrow throws on denial\n";

assert_throws(
    fn () => AccessDecision::deny('test_denial')->orThrow(),
    AuthorizationException::class,
    'AccessDecision::deny->orThrow throws AuthorizationException'
);

assert_throws(
    fn () => $authorizer->requireAuthorized($tenantCtx, ResourceAction::CLIENT_VIEW, ResourceRef::collection('client')),
    AuthorizationException::class,
    'DenyAllAuthorizer::requireAuthorized throws AuthorizationException'
);

// =============================================================================
// 11. AccessDecision::allow — does NOT throw
// =============================================================================
echo "\n[Contract 11] AccessDecision::allow does not throw\n";

assert_not_throws(
    fn () => AccessDecision::allow('test_allow')->orThrow(),
    'AccessDecision::allow->orThrow does not throw'
);

assert_true(AccessDecision::allow()->isAllowed(), 'AccessDecision::allow isAllowed returns true');
assert_true(!AccessDecision::allow()->isDenied(), 'AccessDecision::allow isDenied returns false');
assert_true(AccessDecision::deny()->isDenied(), 'AccessDecision::deny isDenied returns true');

// =============================================================================
// 12. ResourceRef — collection and instance
// =============================================================================
echo "\n[Contract 12] ResourceRef constructors\n";

$col = ResourceRef::collection('appointment');
assert_true($col->resourceType === 'appointment', 'collection resourceType is appointment');
assert_true($col->resourceId === null, 'collection resourceId is null');
assert_true($col->isCollection(), 'isCollection returns true for collection');

$inst = ResourceRef::instance('appointment', 123);
assert_true($inst->resourceType === 'appointment', 'instance resourceType is appointment');
assert_true($inst->resourceId === 123, 'instance resourceId is 123');
assert_true(!$inst->isCollection(), 'isCollection returns false for instance');

// =============================================================================
// 13–14. RequestContextHolder
// =============================================================================
echo "\n[Contract 13-14] RequestContextHolder set/get/reset/requireContext\n";

$holder = new RequestContextHolder();

assert_true($holder->get() === null, 'holder is empty after construction');

assert_throws(
    fn () => $holder->requireContext(),
    \RuntimeException::class,
    'requireContext throws RuntimeException when holder is empty'
);

$holder->set($ctx);
assert_true($holder->get() === $ctx, 'holder->get returns the set context');
assert_not_throws(fn () => $holder->requireContext(), 'requireContext does not throw after set');

$holder->reset();
assert_true($holder->get() === null, 'holder is empty after reset');

assert_throws(
    fn () => $holder->requireContext(),
    \RuntimeException::class,
    'requireContext throws again after reset'
);

// =============================================================================
// Summary
// =============================================================================
echo "\n";
echo str_repeat('=', 60) . "\n";
echo "KERNEL VERIFICATION RESULT: ";
if ($failed === 0) {
    echo "ALL PASSED ({$passed} assertions)\n";
} else {
    echo "FAILURES: {$failed} failed, {$passed} passed\n";
}
echo str_repeat('=', 60) . "\n";

exit($failed > 0 ? 1 : 0);
