# WAVE-04 — Authorization / membership matrix (supporting)

Read-only snapshot. See primary audit: `WAVE-04-AUTHORIZATION-PRINCIPAL-MODE-AND-MEMBERSHIP-BOUNDARY-TRUTH-AUDIT-OPS.md`.

## A. Middleware vs truth

| Middleware | Identity | Platform? | Branch | Org mode | Permission | Notes |
|------------|----------|-----------|--------|----------|------------|-------|
| `BranchContextMiddleware` | Session `SessionAuth::user` | Clears branch if platform | Session/default/`TenantBranchAccessService` | — | — | Platform → `BranchContext` null |
| `OrganizationContextMiddleware` | `AuthService::user` (via resolver) | Same resolver | From `BranchContext` | Resolver precedence | — | Throws if branch org missing |
| `AuthMiddleware` | `AuthService::check` | — | Used for settings scope | — | — | F-25 gate + `TenantRuntimeContextEnforcer` |
| `TenantProtectedRouteMiddleware` | Yes | **Deny** if platform | Must be >0 | org>0 + `MODE_BRANCH_DERIVED` | — | Tenant module seal |
| `PlatformPrincipalMiddleware` | Yes | **Must** be platform | — | — | — | Control plane |
| `PermissionMiddleware` | Yes | — | Indirect via `PermissionService` | — | `PermissionService::has` | **No membership check** |

## B. Login vs post-login enforcement

| Check | Login (`AuthService::attempt`) | `LoginController` after success | First `AuthMiddleware` request |
|-------|--------------------------------|--------------------------------|-------------------------------|
| Password | Yes | — | — |
| `users.deleted_at` | Yes | — | `AuthService::check` |
| Suspended org (non-platform) | No | Yes | `TenantRuntimeContextEnforcer` |
| Active membership | No | No | Indirect via branch/org gates |
| `users.branch_id` valid | No | No | `BranchContextMiddleware` |

## C. Risk × hardened layer

| Hardened layer | Undermined if R1 (pin without membership)? | Undermined if R2 (role soft-delete)? | Undermined if R3 (perm-only route)? |
|----------------|---------------------------------------------|--------------------------------------|-------------------------------------|
| Tenant sealed routes + `TenantProtectedRouteMiddleware` | **Yes** (attacker path: stale pin) | No | Only if route omits tenant middleware |
| `TenantOwnedDataScopeGuard` / org-scoped repos | **Yes** (same branch/org as victim tenant) | No | Same as above |
| `PrincipalAccessService` plane split | No | No | Design-dependent |
