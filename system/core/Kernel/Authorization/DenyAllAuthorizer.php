<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

use Core\Kernel\TenantContext;

/**
 * Deny-by-default authorizer — the initial FOUNDATION-A1/A2 kernel implementation.
 *
 * Denies ALL actions until explicit policy rules are registered.
 * This is the correct starting state: fail-closed by architecture, not by convention.
 *
 * Replacement plan (FOUNDATION-A2):
 * When FOUNDATION-A2 installs the full policy resolution layer, a real PolicyAuthorizer
 * will replace this as the registered implementation of AuthorizerInterface.
 * DenyAllAuthorizer will remain available for testing and as a fallback sentinel.
 *
 * Forbidden: Do not add ALLOW cases to this class. That is FOUNDATION-A2 work.
 * All callers must inject AuthorizerInterface — never DenyAllAuthorizer directly.
 */
final class DenyAllAuthorizer implements AuthorizerInterface
{
    public function authorize(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): AccessDecision
    {
        return AccessDecision::deny(
            sprintf(
                'no_policy_defined: action=%s resource_type=%s resource_id=%s principal=%s',
                $action->value,
                $resource->resourceType,
                $resource->resourceId !== null ? (string) $resource->resourceId : 'null',
                $ctx->principalKind->value,
            )
        );
    }

    public function requireAuthorized(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): void
    {
        $this->authorize($ctx, $action, $resource)->orThrow();
    }
}
