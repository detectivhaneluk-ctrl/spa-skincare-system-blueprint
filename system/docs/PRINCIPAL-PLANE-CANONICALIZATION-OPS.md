# Principal-Plane Canonical Contract

## Plane Truth

- `CONTROL_PLANE`: authenticated user classified by `platform_founder` role via `PrincipalAccessService`; allowed to enter `/platform-admin` and `/platform/*`; does not require tenant-entry or branch context.
- `TENANT_PLANE`: authenticated non-platform user with at least one membership-authorized tenant branch from `TenantBranchAccessService`; allowed to enter tenant protected modules when tenant runtime context is valid.
- `BLOCKED_AUTHENTICATED`: authenticated but not control-plane and no valid tenant branch access; fail-closed to blocked tenant-entry surface and denied from protected control/tenant routes.

## Home Path Rules

- `CONTROL_PLANE` resolves home path to `/platform-admin`.
- `TENANT_PLANE` resolves home path to `/tenant-entry` and then to single-branch dashboard or multi-branch chooser.
- `BLOCKED_AUTHENTICATED` resolves home path to `/tenant-entry` and remains on blocked surface.

## Route Protection Rules

- Control-plane routes (`/platform-admin`, `/platform/*`) require `AuthMiddleware` + `PlatformPrincipalMiddleware` (+ permission middleware where needed).
- Tenant routes (`/dashboard`, tenant modules) require `AuthMiddleware` + `TenantProtectedRouteMiddleware` (+ permission middleware where needed).
- Neutral auth/public routes (`/login`, `/logout`, `/tenant-entry`, `/account/password`, `/account/branch-context`, public endpoints) remain plane-neutral and must not include control/tenant hard guards.
- Contradictory route access is canonical: control-plane principal is denied tenant routes; tenant-plane and blocked principals are denied control-plane routes.

## Shell/Nav Composition Rules

- Control-plane pages render only `shared/layout/platform_admin.php`.
- Tenant pages render `shared/layout/base.php` tenant shell only when principal plane is `TENANT_PLANE`.
- `shared/layout/base.php` suppresses tenant nav for non-tenant planes.
- Blocked/suspended tenant-entry surfaces force `hideNav=true` and render without control/tenant shell headers.

## Out of Scope

- No impersonation or dual-plane session model in this wave.
- No UI redesign; only runtime composition correctness and guard hardening.
