# LIFECYCLE-SUSPENSION-HARDENING-WAVE-01 — Coverage map (repo truth)

Date: 2026-03-28  
Scope: backend-only; no Sales module edits; no Clients module edits.

## A) Suspended organization — enforced today (verified in tree)

| Surface | Mechanism | File(s) |
|--------|-----------|---------|
| Post-login tenant users | Logout + flash if bound to suspended org | `modules/auth/controllers/LoginController.php` (`OrganizationLifecycleGate::isTenantUserBoundToSuspendedOrganization`) |
| GET `/tenant-entry` | Renders `tenant-suspended.php` when bound | `modules/auth/controllers/TenantEntryController.php` |
| Authenticated HTTP (after `AuthMiddleware`) | Branch-derived context: `isBranchLinkedToSuspendedOrganization`; non-branch-derived + org id: `!isOrganizationActive` | `core/tenant/TenantRuntimeContextEnforcer.php` |
| Auth pipeline order | `AuthMiddleware` → `StaffMultiOrgOrganizationResolutionGate` → `TenantRuntimeContextEnforcer::enforceForAuthenticatedUser` | `core/middleware/AuthMiddleware.php` |
| Org active probe | `organizations.suspended_at IS NULL` | `core/Organization/OrganizationLifecycleGate.php` |
| Tenant-internal route gate | Requires branch + org + `MODE_BRANCH_DERIVED` before controller | `core/middleware/TenantProtectedRouteMiddleware.php` |
| Access-shape audits | `UserAccessShapeService` uses lifecycle gate for suspended binding | `core/auth/UserAccessShapeService.php` |
| Public booking / public commerce (not tenant staff UI) | Service-level gate usage | `modules/online-booking/services/PublicBookingService.php`, `modules/public-commerce/services/PublicCommerceService.php` |

## B) Inactive / disallowed actor — prior gap (this wave)

| Concern | Prior behavior | Notes |
|--------|----------------|-------|
| Staff `is_active = 0` at current branch | No global request gate | Domain code (e.g. appointments) checked staff active for specific operations only; tenant dashboard and other modules could still run with session branch set. |
| `users.deleted_at` | Session `user()` omitted deleted users | Already fail-closed for soft-deleted users. |

## C) Membership / allowed branches vs suspended org (this wave)

| Component | Change | Rationale |
|-----------|--------|-----------|
| `TenantBranchAccessService::activeMembershipOrganizationIds` | Exclude `organizations.suspended_at IS NOT NULL` | Stops membership-based branch lists from treating suspended orgs as active membership targets. |
| `TenantBranchAccessService::activeDefaultMembershipBranchId` | Same org filter on join | Align default branch resolution with non-suspended orgs only. |
| `TenantBranchAccessService::isBranchInOrganizations` | Require org not suspended | Pin validation against org set cannot succeed on suspended org rows. |
| `TenantBranchAccessService::activeBranchIdsByOrganizations` | Require org not suspended | Defensive: branch listing under org ids never returns branches whose org is suspended. |

## D) Protected HTTP entry points (tenant data-plane)

**Definition:** Routes using `AuthMiddleware` and (for tenant-internal modules) `TenantProtectedRouteMiddleware` per `routes/web/*.php`.

**Enumerate:** All `$router->*` registrations that include both middleware classes (grep truth: `TenantProtectedRouteMiddleware` in `system/routes`).

**Behavior required when org suspended (branch-derived context):** `TenantRuntimeContextEnforcer` denies before controller (403 JSON `TENANT_ORGANIZATION_SUSPENDED` when `Accept: application/json`; else `tenant-suspended.php`).

**Behavior required when staff inactive at session branch (this wave):** `TenantRuntimeContextEnforcer` denies (403 JSON `TENANT_ACTOR_INACTIVE` when `Accept: application/json`; else `HttpErrorHandler` 403).

**Exempt paths (existing):** `/logout` POST, `/account/password` GET/POST, `/account/branch-context` POST, `/tenant-entry` GET, `/support-entry/stop` POST — `TenantRuntimeContextEnforcer::isExemptRequestPath()`. **PLT-LC-01 (2026-03-28):** `POST /account/branch-context` still skips the enforcer, but `BranchContextController` applies `OrganizationLifecycleGate::isBranchLinkedToSuspendedOrganization` after `TenantBranchAccessService::allowedBranchIdsForUser` (defense-in-depth + legacy pin alignment).

**Platform / control-plane:** `PrincipalPlaneResolver::CONTROL_PLANE` users skip tenant lifecycle enforcement in enforcer (unchanged).

## E) CLI / job entry points (no HTTP session)

| Script / pattern | Uses `AuthMiddleware`? | Wave note |
|------------------|-------------------------|-----------|
| `system/scripts/migrate.php` | No | DDL/maintenance; not tenant session scoped. |
| `system/scripts/dev-only/run_client_merge_jobs_once.php` | No | Invokes services directly; **Clients module** — not modified this wave. Residual: workers should rely on service-layer org checks where applicable (separate charter if needed). |
| Other `require bootstrap.php` CLIs | Varies | Only HTTP requests through `Dispatcher` + `AuthMiddleware` get this wave’s request gate. |

## F) Sales module

**Out of scope:** No edits under `modules/sales` in this wave.
