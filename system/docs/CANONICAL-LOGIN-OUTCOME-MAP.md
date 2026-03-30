# Canonical login outcome map

**Task:** SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01

## Credential validation

- `Modules\Auth\Controllers\LoginController::attempt` → `AuthService::attempt` (email/password, throttle, session).
- Failed login → `/login` with flash.

## Post-login redirect (single authority)

- `LoginController` and `RootController` use `AuthenticatedHomePathResolver::homePathForUserId`, which delegates to `PostLoginHomePathResolver` → `UserAccessShapeService::expectedHomePathForUserId`.

## Decision tree

1. **Invalid / missing user id** → `/tenant-entry` (should not occur after successful login).
2. **Platform principal** (`PrincipalAccessService::isPlatformPrincipal`, role `platform_founder`) → `/platform-admin`.
3. **Tenant user with org suspension binding** (`OrganizationLifecycleGate::isTenantUserBoundToSuspendedOrganization`) → `/tenant-entry` (suspended surface).  
   Note: login is denied before session is established when this gate fires at login; this branch still applies to `GET /` and other authenticated entry points.
4. **Tenant user, tenant entry resolution** (`TenantEntryResolverService` / `TenantBranchAccessService`):
   - **Exactly one usable branch** → `/dashboard` (branch context is established on the next request by `BranchContextMiddleware` from membership + defaults).
   - **Multiple usable branches** → `/tenant-entry` (chooser).
   - **Zero usable branches** → `/tenant-entry` (blocked view).

## Principal plane (middleware)

- `PrincipalPlaneResolver` uses the same branch membership truth as access shape (via `UserAccessShapeService::principalPlaneForUserId`).
- **CONTROL_PLANE** → platform routes only; tenant modules return 403 or redirect to `/platform-admin` where applicable.
- **TENANT_PLANE** → tenant routes with branch/org context.
- **BLOCKED_AUTHENTICATED** → no usable branches; tenant-protected routes redirect to `/tenant-entry`.

## Founder must not enter tenant dashboard

- `TenantProtectedRouteMiddleware` denies `CONTROL_PLANE` on tenant module routes.
- `PlatformPrincipalMiddleware` restricts `/platform-admin` to platform principals.
- `BranchContextMiddleware` clears session branch for platform principals.

## Related code

- `UserAccessShapeService` — authoritative access-shape evaluation for audits and founder tooling.
- `TenantEntryController` — chooser / blocked / suspended HTML surfaces.
- `TenantPrincipalMiddleware` — `/tenant-entry` allows only non-platform principals.
