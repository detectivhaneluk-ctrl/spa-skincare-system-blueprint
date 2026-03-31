<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

use Core\Kernel\TenantContext;

/**
 * Central authorization policy gate (FOUNDATION-A2 kernel contract).
 *
 * Single source of truth for: "Can actor X perform action Y on resource Z within context T?"
 *
 * Architecture contracts:
 * - ALL protected service operations that gate access to tenant-owned resources MUST call
 *   this interface — not implement their own ownership checks.
 * - Deny-by-default: any action not covered by an explicit policy rule MUST return DENY.
 * - Context-first: the TenantContext carries org/branch/principal — policies must not
 *   re-derive scope from session or global singletons.
 * - Implementations must be deterministic and free of side effects.
 *
 * FOUNDATION-A2 will install the full policy resolution layer implementing this interface.
 * Until then, DenyAllAuthorizer is the registered implementation.
 *
 * Future: PostgreSQL RLS — the authorization decision here drives the RLS binding values.
 * Future: ReBAC — this interface remains stable; the implementation delegates to the
 *         relationship graph when resource-level sharing or delegated permissions are needed.
 */
interface AuthorizerInterface
{
    /**
     * Evaluate whether the actor in $ctx may perform $action on $resource.
     *
     * Never throws on denial — returns an AccessDecision so callers control the response.
     * Always call requireAuthorized() when you want exception-on-denial semantics.
     */
    public function authorize(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): AccessDecision;

    /**
     * Authorize or throw AuthorizationException.
     * Convenience wrapper: authorize() + orThrow().
     *
     * Use at service entry points where denial must abort the operation.
     *
     * @throws AuthorizationException when denied
     */
    public function requireAuthorized(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): void;
}
