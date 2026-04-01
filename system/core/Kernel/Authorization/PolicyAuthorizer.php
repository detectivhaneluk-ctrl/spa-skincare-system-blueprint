<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

use Core\Kernel\PrincipalKind;
use Core\Kernel\TenantContext;
use Core\Permissions\PermissionService;

/**
 * Real policy authorizer — replaces DenyAllAuthorizer as the registered runtime implementation.
 *
 * FOUNDATION-A2 completion: this class installs the first real policy layer.
 *
 * Policy source of truth:
 * - FOUNDER principal: full allow for all tenant-scoped and platform actions within a resolved context.
 * - SUPPORT_ACTOR principal (impersonation): read-only allow for listed VIEW actions only.
 * - TENANT principal: permission-based, delegated to PermissionService (existing role+staff-group model).
 * - GUEST / unresolved / no matching principal: deny.
 *
 * Deny-by-default is preserved:
 * - Any ResourceAction not in ACTION_PERMISSION_MAP denies for TENANT principals.
 * - Any unresolved TenantContext (tenantContextResolved = false) denies for all tenant-scoped actions.
 * - Support actor write operations are always denied.
 *
 * Integration model:
 * - PermissionService is the backing store for TENANT permission codes (role + staff-group union).
 * - FOUNDER bypasses PermissionService entirely — platform founders have full control.
 * - This class does NOT replace direct PermissionService::has() calls in legacy service code;
 *   those are the OLD pre-architecture-reset pattern. New protected-domain entry points use
 *   AuthorizerInterface::requireAuthorized() via injection.
 *
 * What is currently enforced:
 * - TENANT, FOUNDER, SUPPORT_ACTOR principals for all ResourceAction vocabulary actions.
 *
 * What remains intentionally deny-by-default:
 * - Any ResourceAction added to the enum without a matching ACTION_PERMISSION_MAP entry.
 * - GUEST principals on all protected operations.
 *
 * Future: PostgreSQL RLS → organizationId/branchId from TenantContext drive SET LOCAL binding.
 * Future: ReBAC → decideForTenant() can delegate to OpenFGA when resource-level delegation is needed.
 */
final class PolicyAuthorizer implements AuthorizerInterface
{
    /**
     * Maps ResourceAction::value to the PermissionService permission code required for TENANT principals.
     * Platform-only actions (FOUNDER-only) map to null — they deny for all non-founder principals.
     *
     * @var array<string, string|null>
     */
    private const ACTION_PERMISSION_MAP = [
        // Appointments
        'appointment:view'        => 'appointments.view',
        'appointment:create'      => 'appointments.create',
        'appointment:modify'      => 'appointments.edit',
        'appointment:cancel'      => 'appointments.edit',
        'appointment:delete'      => 'appointments.edit',
        // Clients
        'client:view'             => 'clients.view',
        'client:create'           => 'clients.create',
        'client:modify'           => 'clients.edit',
        'client:delete'           => 'clients.delete',
        // Profile images (FOUNDATION-A5 pilot)
        'profile-image:upload'    => 'clients.media.upload',
        'profile-image:delete'    => 'clients.media.delete',
        // Services and resources
        'service:view'            => 'services-resources.view',
        'service:manage'          => 'services-resources.edit',
        // Staff
        'staff:view'              => 'staff.view',
        'staff:manage'            => 'staff.edit',
        // Sales / invoices
        'invoice:view'            => 'sales.view',
        'invoice:create'          => 'sales.create',
        'invoice:edit'            => 'sales.edit',
        'invoice:delete'          => 'sales.delete',
        'invoice:void'            => 'sales.edit',
        'invoice:pay'             => 'sales.pay',
        // Packages and memberships
        'membership:view'         => 'memberships.view',
        'membership:manage'       => 'memberships.manage',
        // Branch settings
        'branch-settings:view'    => 'settings.view',
        'branch-settings:manage'  => 'settings.edit',
        // Platform actions — FOUNDER only, null = deny for all non-founder principals
        'platform:support-entry'  => null,
        'platform:org-manage'     => null,
    ];

    /**
     * Support actor (impersonation) may only read. These are the allowed read actions.
     * Write operations are always denied for support actors to prevent accidental mutation during audit.
     *
     * @var list<string>
     */
    private const SUPPORT_ACTOR_ALLOWED_ACTIONS = [
        'appointment:view',
        'client:view',
        'service:view',
        'staff:view',
        'invoice:view',
        'membership:view',
        'branch-settings:view',
    ];

    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function authorize(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): AccessDecision
    {
        // Platform-only actions: require FOUNDER, no tenant context needed
        if ($action === ResourceAction::PLATFORM_SUPPORT_ENTRY || $action === ResourceAction::PLATFORM_ORG_MANAGE) {
            return $ctx->principalKind === PrincipalKind::FOUNDER
                ? AccessDecision::allow('founder_platform_policy')
                : AccessDecision::deny('platform_action_requires_founder_principal');
        }

        // All tenant-scoped actions require resolved tenant context
        if (!$ctx->tenantContextResolved) {
            return AccessDecision::deny(
                'tenant_context_unresolved: ' . ($ctx->unresolvedReason ?? 'context_not_resolved')
            );
        }

        return match ($ctx->principalKind) {
            PrincipalKind::FOUNDER       => AccessDecision::allow('founder_tenant_policy'),
            PrincipalKind::SUPPORT_ACTOR => $this->decideForSupportActor($action),
            PrincipalKind::TENANT        => $this->decideForTenantPrincipal($ctx, $action),
            default => AccessDecision::deny('no_policy_for_principal_kind:' . $ctx->principalKind->value),
        };
    }

    public function requireAuthorized(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): void
    {
        $this->authorize($ctx, $action, $resource)->orThrow();
    }

    private function decideForSupportActor(ResourceAction $action): AccessDecision
    {
        if (in_array($action->value, self::SUPPORT_ACTOR_ALLOWED_ACTIONS, true)) {
            return AccessDecision::allow('support_actor_read_policy');
        }

        return AccessDecision::deny('support_actor_write_blocked: ' . $action->value);
    }

    private function decideForTenantPrincipal(TenantContext $ctx, ResourceAction $action): AccessDecision
    {
        if ($ctx->actorId <= 0) {
            return AccessDecision::deny('unauthenticated_tenant_actor');
        }

        $permissionCode = self::ACTION_PERMISSION_MAP[$action->value] ?? '__unmapped__';

        // Platform actions: deny for non-founder principals
        if ($permissionCode === null) {
            return AccessDecision::deny('platform_action_requires_founder_principal: ' . $action->value);
        }

        // Unmapped actions: deny-by-default
        if ($permissionCode === '__unmapped__') {
            return AccessDecision::deny('action_not_in_policy_map: ' . $action->value);
        }

        if ($this->permissions->has($ctx->actorId, $permissionCode)) {
            return AccessDecision::allow('tenant_permission_granted: ' . $permissionCode);
        }

        return AccessDecision::deny('tenant_permission_denied: ' . $permissionCode);
    }
}
