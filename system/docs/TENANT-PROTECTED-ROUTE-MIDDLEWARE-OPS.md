# Tenant-protected route boundary (WAVE-01)

**Middleware:** `Core\Middleware\TenantProtectedRouteMiddleware`  
**Stack order:** global pipeline (`CsrfMiddleware`, `ErrorHandlerMiddleware`, `BranchContextMiddleware`, `OrganizationContextMiddleware`) → `AuthMiddleware` → **`TenantProtectedRouteMiddleware`** → `PermissionMiddleware` (when used).

## Contract

For the route to continue:

1. Session user is authenticated (`AuthMiddleware` normally runs first; this middleware still returns **401** JSON or `/login` redirect if not).
2. User is **not** a platform principal → else **403** JSON (`TENANT_ROUTE_PLATFORM_PRINCIPAL_FORBIDDEN`) or `HttpErrorHandler` **403** page for HTML.
3. `BranchContext::getCurrentBranchId()` is a **positive** integer.
4. `OrganizationContext::getCurrentOrganizationId()` is a **positive** integer.
5. `OrganizationContext::getResolutionMode()` is **`OrganizationContext::MODE_BRANCH_DERIVED`**.

If (3–5) fail: **403** JSON (`TENANT_CONTEXT_REQUIRED`, same message as `TenantRuntimeContextEnforcer`) or **redirect** to `/tenant-entry` for HTML (same as enforcer).

## Where it is **not** used

- Public anonymous routes (`[]` middleware).
- `GuestMiddleware` login/password-reset flows.
- `POST /logout`, `GET|POST /account/password` (auth only).
- `GET /tenant-entry`, `POST /account/branch-context` — `TenantPrincipalMiddleware` only (entry / branch switch).
- `GET /` — `AuthMiddleware` only (home resolver).
- Platform routes — `PlatformPrincipalMiddleware` + permissions (`/platform-admin`, `/platform/organizations/*`).

## Related

- `TenantRuntimeContextEnforcer` (in `AuthMiddleware`) remains the authenticated-user-wide guard; this middleware is the **explicit route registration** boundary for tenant modules.
