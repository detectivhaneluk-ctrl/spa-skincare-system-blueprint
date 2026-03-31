# Foundation Kernel Architecture — FOUNDATION-A1 / A2

**Date installed:** 2026-03-31  
**Status:** LIVE — kernel installed and verified  
**Root tasks addressed:** FOUNDATION-A1 (TenantContext Kernel), FOUNDATION-A2 (Authorization Kernel — skeleton BIG-01; full PolicyAuthorizer BIG-04)  
**Root causes addressed:** ROOT-01 (id-only scoping), ROOT-03 (public/tenant bootstrap inconsistency), ROOT-04 (repair/global fallback ambiguity), ROOT-05 (service-layer scope drift)  
**Canonical execution queue:** `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`

---

## Why this kernel exists

The prior architecture had no immutable, resolved-at-entry representation of who is making a request and what they are allowed to do. Tenant scoping was a convention distributed across services, not an architectural guarantee.

The kernel installs two foundational contracts:

1. **TenantContext** — an immutable, resolved-once snapshot of the execution capability for a request  
2. **AuthorizerInterface** — a central, deny-by-default authorization gate that all protected operations must call

Everything else in the FOUNDATION roadmap (scoped repository API, media pilot, mechanical guardrails) depends on these two contracts being stable.

---

## Kernel interfaces installed

### `Core\Kernel\TenantContext` (immutable value object)

The core contract. Represents the fully resolved capability context for a single request.

**Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `actorId` | `int` | Effective session user. During support entry: the impersonated tenant user. Zero for guest. |
| `principalKind` | `PrincipalKind` | TENANT \| FOUNDER \| SUPPORT_ACTOR \| GUEST \| SYSTEM |
| `organizationId` | `?int` | Resolved org id. Non-null only when `tenantContextResolved = true`. |
| `branchId` | `?int` | Resolved branch id. Non-null only when `tenantContextResolved = true`. |
| `isSupportEntry` | `bool` | True when founder is impersonating a tenant user. |
| `supportActorId` | `?int` | Real founder's user id during support entry (for audit). |
| `assuranceLevel` | `AssuranceLevel` | SESSION \| NONE \| SESSION_MFA (future) \| STEP_UP (future) |
| `executionSurface` | `ExecutionSurface` | HTTP_TENANT \| HTTP_PLATFORM \| HTTP_PUBLIC \| CLI \| BACKGROUND |
| `tenantContextRequired` | `bool` | Whether the current surface requires a resolved tenant context. |
| `tenantContextResolved` | `bool` | Whether `organizationId` + `branchId` are trustworthy for data access. |
| `unresolvedReason` | `?string` | Human-readable reason when unresolved. Null when resolved. |
| `organizationResolutionMode` | `?string` | OrganizationContext resolution mode for diagnostics. |

**Named constructors (only valid creation paths):**

```php
TenantContext::resolvedTenant(...): self        // Authenticated tenant or support-entry actor, MODE_BRANCH_DERIVED
TenantContext::founderControlPlane(...): self   // Platform founder, no branch scope
TenantContext::guest(...): self                 // Unauthenticated / anonymous
TenantContext::unresolvedAuthenticated(...): self // Authenticated but missing branch/org
```

**Constructor is private.** There is no other way to create a `TenantContext`.

**Fail-closed method:**

```php
$ctx->requireResolvedTenant(): array{organization_id: int, branch_id: int}
// Throws UnresolvedTenantContextException when tenantContextResolved is false.
// Protected operations MUST call this before accessing tenant-owned data.
```

**Audit method:**

```php
$ctx->auditActorId(): int
// Returns the real actor for audit records.
// During support entry: returns the founder (supportActorId).
// Otherwise: returns actorId.
```

---

### `Core\Kernel\PrincipalKind` (enum)

```
TENANT         Authenticated user in tenant plane (staff, receptionist, etc.)
FOUNDER        Platform founder in control plane (not impersonating)
SUPPORT_ACTOR  Founder impersonating a tenant user (actorId = tenant; supportActorId = founder)
GUEST          Unauthenticated / anonymous
SYSTEM         CLI / background job (future — context from job payload, not session)
```

### `Core\Kernel\AssuranceLevel` (enum)

```
NONE          Unauthenticated
SESSION       Standard password session (current baseline)
SESSION_MFA   Session + MFA verified (future: PLT-MFA-01)
STEP_UP       Re-authenticated for privileged operation (future)
```

### `Core\Kernel\ExecutionSurface` (enum)

```
HTTP_TENANT    Authenticated tenant-internal HTTP routes
HTTP_PLATFORM  Founder/platform-control HTTP routes
HTTP_PUBLIC    Public/anonymous HTTP routes
CLI            Command-line scripts (future)
BACKGROUND     Queue workers / background jobs (future)
```

### `Core\Kernel\UnresolvedTenantContextException`

Thrown by `TenantContext::requireResolvedTenant()` when context is absent or unresolved. Must not be caught silently. Controllers translate this to HTTP 403 or redirect.

### `Core\Kernel\RequestContextHolder`

Mutable per-request singleton that holds the resolved `TenantContext`. Reset by `TenantContextMiddleware` at the start of each request.

```php
$holder->get(): ?TenantContext           // null if middleware has not run
$holder->requireContext(): TenantContext  // throws RuntimeException if not set
$holder->set(TenantContext $ctx): void   // called only by TenantContextMiddleware
$holder->reset(): void                   // called only by TenantContextMiddleware
```

---

## Resolver entry point

### `Core\Kernel\TenantContextResolver`

**Location:** `system/core/Kernel/TenantContextResolver.php`  
**Called by:** `TenantContextMiddleware` — exactly once per request.

**Integration seam:** Reads from already-resolved request-scoped singletons:
- `SessionAuth` → `id()`, `isSupportEntryActive()`, `supportActorUserId()`
- `BranchContext` → `getCurrentBranchId()`
- `OrganizationContext` → `getCurrentOrganizationId()`, `getResolutionMode()`
- `PrincipalAccessService` → `isPlatformPrincipal()`

**Zero new DB queries.** All inputs come from singletons that have already been resolved by `BranchContextMiddleware` and `OrganizationContextMiddleware`.

**Resolution logic:**

```
1. No session user    → TenantContext::guest()
2. Support entry      → resolveAsSupportActor() → resolvedTenant (isSupportEntry=true) or unresolvedAuthenticated
3. Platform principal → TenantContext::founderControlPlane()
4. Tenant plane:
   - MODE_BRANCH_DERIVED + positive orgId + positive branchId → TenantContext::resolvedTenant()
   - Otherwise → TenantContext::unresolvedAuthenticated(reason)
```

### Pipeline order (`Core\Router\Dispatcher::$globalMiddleware`)

```
1. CsrfMiddleware
2. ErrorHandlerMiddleware
3. BranchContextMiddleware          ← resolves BranchContext
4. OrganizationContextMiddleware    ← resolves OrganizationContext
5. TenantContextMiddleware          ← materializes immutable TenantContext  [ADDED: FOUNDATION-A1]
6. [per-route middleware: AuthMiddleware, TenantProtectedRouteMiddleware, PermissionMiddleware, ...]
```

---

## Authorization kernel contracts

### `Core\Kernel\Authorization\AuthorizerInterface`

The single authorization gate. All protected service operations that check access to tenant-owned resources **must** call this interface.

```php
public function authorize(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): AccessDecision;
public function requireAuthorized(TenantContext $ctx, ResourceAction $action, ResourceRef $resource): void;
```

**Contract:** Deny-by-default. Any action not covered by an explicit policy rule returns DENY.

### `Core\Kernel\Authorization\ResourceAction` (enum)

Business-level action vocabulary. Named as `{resource-domain}:{verb}`. Examples:

```
appointment:view      appointment:create    appointment:modify    appointment:cancel
client:view           client:create         client:modify         client:delete
profile-image:upload  profile-image:delete
invoice:view          invoice:create        invoice:void
membership:view       membership:manage
branch-settings:view  branch-settings:manage
platform:support-entry  platform:org-manage
```

All new protected operations must add an entry here before exposing the operation.

### `Core\Kernel\Authorization\ResourceRef` (value object)

```php
ResourceRef::collection(string $resourceType): self  // collection or creation
ResourceRef::instance(string $resourceType, int $id): self  // specific entity
```

### `Core\Kernel\Authorization\AccessDecision` (immutable value object)

```php
AccessDecision::allow(string $reason): self
AccessDecision::deny(string $reason): self
$decision->isAllowed(): bool
$decision->isDenied(): bool
$decision->orThrow(): void  // throws AuthorizationException when denied
```

### `Core\Kernel\Authorization\PolicyAuthorizer` (BIG-04 — real implementation)

The registered implementation of `AuthorizerInterface` since BIG-04 (2026-03-31). Replaces `DenyAllAuthorizer`.

**Decision matrix:**

| Principal | Tenant-scoped actions | Platform-only actions (`platform:*`) |
|-----------|----------------------|--------------------------------------|
| `FOUNDER` | Allow (full access) | Allow |
| `SUPPORT_ACTOR` | Allow view actions only; write actions denied | Denied |
| `TENANT` | Permission-map based via `PermissionService` | Denied |
| `GUEST` / other | Denied | Denied |

**Deny-by-default preserved:**
- Actions not in `ACTION_PERMISSION_MAP` → `action_not_in_policy_map` deny
- Unresolved `TenantContext` → `tenant_context_unresolved` deny
- Unauthenticated tenant actor (`actorId <= 0`) → `unauthenticated_tenant_actor` deny

**Source:** `system/core/Kernel/Authorization/PolicyAuthorizer.php`

### `Core\Kernel\Authorization\DenyAllAuthorizer` (historical — replaced by BIG-04)

The initial placeholder implementation of `AuthorizerInterface`. Denied all actions unconditionally. Replaced by `PolicyAuthorizer` in BIG-04. Retained in codebase for reference but no longer registered.

### `Core\Kernel\Authorization\AuthorizationException`

Thrown by `AuthorizerInterface::requireAuthorized()` on denial. Controllers translate to HTTP 403.

---

## Deny-by-default rule

The authorization kernel fails closed by architecture, not by convention:

- `PolicyAuthorizer` is the registered implementation of `AuthorizerInterface` (since BIG-04).
- Every call to `authorize()` returns `AccessDecision::deny()` unless an explicit policy path exists.
- Unmapped actions, unresolved tenant context, and unauthenticated actors all return DENY with a reason string.
- `DenyAllAuthorizer` is retained as historical reference but is no longer registered.

**What this means for services:** Services consuming `AuthorizerInterface` receive real policy decisions. The effective deny-by-default guarantee is preserved through the unmapped-action and unresolved-context deny paths in `PolicyAuthorizer`.

---

## How future protected flows must consume TenantContext

### Step 1 — Receive TenantContext explicitly

Protected service methods must receive `TenantContext` as a parameter OR retrieve it from `RequestContextHolder`. They must **not** re-derive it from `BranchContext`/`OrganizationContext`/`SessionAuth` inside the method.

```php
// Correct: receive TenantContext as a parameter
public function createAppointment(TenantContext $ctx, CreateAppointmentCommand $cmd): Appointment
{
    $scope = $ctx->requireResolvedTenant(); // throws if unresolved
    $authorizer->requireAuthorized($ctx, ResourceAction::APPOINTMENT_CREATE, ResourceRef::collection('appointment'));
    // proceed with $scope['organization_id'] and $scope['branch_id']
}

// Correct: retrieve from holder (for services wired before FOUNDATION-A4 migration)
public function createAppointment(CreateAppointmentCommand $cmd): Appointment
{
    $ctx = $this->contextHolder->requireContext();
    $scope = $ctx->requireResolvedTenant();
    // ...
}
```

### Step 2 — Authorize before acting

```php
$authorizer->requireAuthorized($ctx, ResourceAction::APPOINTMENT_CREATE, ResourceRef::collection('appointment'));
```

### Step 3 — Use scope for data access

```php
$scope = $ctx->requireResolvedTenant();
$repo->mutateOwned($ctx, $resourceId, $command); // FOUNDATION-A4 canonical API
```

### Step 4 — Use auditActorId() for audit records

```php
$audit->record($ctx->auditActorId(), 'appointment.created', $appointmentId);
```

---

## What is explicitly forbidden going forward

| Forbidden pattern | Why | Correct alternative |
|-------------------|-----|---------------------|
| Calling `BranchContext::getCurrentBranchId()` or `OrganizationContext::getCurrentOrganizationId()` inside new service/repository methods | Bypasses the kernel; re-derives context ad-hoc | Call `TenantContext::requireResolvedTenant()` |
| Adding per-service ownership checks that don't call `AuthorizerInterface` | Scattered authorization; ROOT-04/05 | Call `AuthorizerInterface::requireAuthorized()` |
| Constructing `TenantContext` with `new TenantContext(...)` | Constructor is private | Use named constructors only |
| Calling `RequestContextHolder::set()` outside `TenantContextMiddleware` | Breaks the resolved-once contract | Only the middleware sets context |
| Adding ALLOW cases to `DenyAllAuthorizer` | FOUNDATION-A2 is complete — `DenyAllAuthorizer` is no longer registered | Write a policy method in `PolicyAuthorizer` |
| New raw `findById`/`updateById`/`deleteById` repository paths for tenant-owned rows | ROOT-01 | Use FOUNDATION-A4 canonical scoped API |
| Direct `$this->db->fetchOne/fetchAll/query` in service layer for protected domains | ROOT-05 | Use repositories only (FOUNDATION-A3 rule) |

---

## Compatibility with existing code

The kernel is additive. The following existing classes and behaviors are unchanged:

- `BranchContext`, `OrganizationContext`, `BranchContextMiddleware`, `OrganizationContextMiddleware` — still run; TenantContextMiddleware reads their output.
- `TenantOwnedDataScopeGuard` — still usable in legacy code paths not yet migrated.
- `TenantRuntimeContextEnforcer` — still enforces per-route tenant requirements (unchanged).
- `PrincipalPlaneResolver`, `PrincipalAccessService`, `SessionAuth` — unchanged; kernel reads from them.
- All existing services that use `BranchContext`/`OrganizationContext` directly — unaffected until they are migrated in FOUNDATION-A5+.

**Migration path:** When a service or repository is migrated (per FOUNDATION-A4/A5/A7), it adopts `TenantContext` as its primary scoping input and drops direct `BranchContext`/`OrganizationContext` dependencies. Migration is module-by-module; no "big bang" cutover.

---

## Future compatibility notes

- **PostgreSQL RLS:** `organizationId` and `branchId` from `TenantContext` are the intended binding values for RLS session variables. The resolved-once contract ensures the values are stable before any query executes.
- **ReBAC / OpenFGA:** `principalKind` + `actorId` are the subject. `ResourceRef` (`resourceType` + `resourceId`) is the object. `AuthorizerInterface` remains the call site; the implementation can delegate to a relationship engine.
- **Observability:** All `TenantContext` fields are `readonly` and publicly readable, making structured audit/trace logging trivial.
- **CLI/background contexts:** `ExecutionSurface::CLI` and `BACKGROUND` are defined. When queue workers are added (PLT-Q-01, Phase 2), they will bind `TenantContext` from job payload — not from session. `TenantContextResolver::resolveForHttpRequest()` is HTTP-only.

---

## Verification

**Script:** `system/scripts/read-only/verify_kernel_tenant_context_01.php`  
**Run:** `php system/scripts/read-only/verify_kernel_tenant_context_01.php`  
**Result:** 74/74 assertions pass (no DB required).

Contracts verified:
1. `resolvedTenant` — all fields correct
2. `requireResolvedTenant()` — returns scope when resolved
3. `requireResolvedTenant()` — throws `UnresolvedTenantContextException` when unresolved
4. `unresolvedAuthenticated` — `tenantContextRequired=true`, fail-closed
5. `founderControlPlane` — `tenantContextRequired=false`, no branch scope
6. `guest` — `actorId=0`, not authenticated
7. Support entry — `actorId` = tenant user, `auditActorId()` = founder
8. Immutability — all public fields are `readonly`, constructor is private
9. `DenyAllAuthorizer` — denies all actions for all principals
10. `AccessDecision::orThrow()` — throws `AuthorizationException` on denial
11. `AccessDecision::allow()` — does not throw
12. `ResourceRef` — collection and instance constructors
13–14. `RequestContextHolder` — reset/set/get/requireContext lifecycle

---

## Files installed

| File | Role |
|------|------|
| `system/core/Kernel/TenantContext.php` | Immutable context value object |
| `system/core/Kernel/PrincipalKind.php` | Principal classification enum |
| `system/core/Kernel/AssuranceLevel.php` | Authentication assurance level enum |
| `system/core/Kernel/ExecutionSurface.php` | Request origin surface enum |
| `system/core/Kernel/UnresolvedTenantContextException.php` | Fail-closed exception |
| `system/core/Kernel/RequestContextHolder.php` | Per-request context holder singleton |
| `system/core/Kernel/TenantContextResolver.php` | Single designated resolver entry point |
| `system/core/Kernel/Authorization/AuthorizerInterface.php` | Central authorization gate contract |
| `system/core/Kernel/Authorization/ResourceAction.php` | Business-level action vocabulary |
| `system/core/Kernel/Authorization/ResourceRef.php` | Resource reference value object |
| `system/core/Kernel/Authorization/AccessDecision.php` | Authorization decision value object |
| `system/core/Kernel/Authorization/DenyAllAuthorizer.php` | Deny-by-default initial placeholder (historical — replaced by PolicyAuthorizer in BIG-04) |
| `system/core/Kernel/Authorization/PolicyAuthorizer.php` | Real authorization implementation (BIG-04) — FOUNDER/SUPPORT_ACTOR/TENANT policy, deny-by-default preserved |
| `system/core/Kernel/Authorization/AuthorizationException.php` | Authorization denial exception |
| `system/core/middleware/TenantContextMiddleware.php` | Pipeline middleware; resolves once at edge |

| File modified | Change |
|---------------|--------|
| `system/core/Router/Dispatcher.php` | `TenantContextMiddleware` added to global pipeline |
| `system/bootstrap.php` | Kernel singletons registered |
| `composer.json` | `Core\\Kernel\\` namespace added to PSR-4 autoloader |

---

## FOUNDATION-A2 is complete

`PolicyAuthorizer` is installed and registered as of BIG-04 (2026-03-31). The authorization kernel is fully operational:
- FOUNDER full-allow
- SUPPORT_ACTOR read-only
- TENANT permission-map based (integrates `PermissionService`)
- Deny-by-default for unresolved context, unmapped actions, unauthenticated actors

**Next:** FOUNDATION-A7 PHASE-2 (Online-booking domain migration).
