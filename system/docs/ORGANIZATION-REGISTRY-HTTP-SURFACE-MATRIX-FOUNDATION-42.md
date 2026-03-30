# ORGANIZATION-REGISTRY-HTTP-SURFACE-MATRIX — FOUNDATION-42

Companion to **`ORGANIZATION-REGISTRY-HTTP-EXPOSURE-AND-PLATFORM-GUARD-BOUNDARY-TRUTH-AUDIT-FOUNDATION-42-OPS.md`**.

**Legend:** **Today** = as of post–F-41 tree (no org registry HTTP). **Phase 1a** = recommended first HTTP slice. **Phase 1b** = follow-on mutations.

| # | HTTP | Illustrative path | Today | Phase 1a (read-first) | Phase 1b (mutations) | Permission (F-39) | Service method | Global middleware order (relevant) | F-25 multi-org note |
|---|------|-------------------|-------|----------------------|---------------------|-------------------|----------------|-----------------------------------|---------------------|
| 1 | GET | `/platform/organizations` | **Absent** | Add: list HTML/JSON | — | `platform.organizations.view` | `OrganizationRegistryReadService::listOrganizations()` | Csrf → ErrorHandler → BranchContext → OrgContext → **Auth** → **Permission** | **403** if org unresolved & count>1 **unless** gate compatibility added |
| 2 | GET | `/platform/organizations/{id}` | **Absent** | Add: show | — | `platform.organizations.view` | `OrganizationRegistryReadService::getOrganizationById($id)` | Same | Same |
| 3 | GET | `/platform/organizations/create` (optional) | **Absent** | Defer | Form for create | `platform.organizations.manage` | — (view only) | Same | Same |
| 4 | POST | `/platform/organizations` | **Absent** | Defer | Create org | `platform.organizations.manage` | `OrganizationRegistryMutationService::createOrganization` | Same + **CSRF** on POST | Same |
| 5 | POST | `/platform/organizations/{id}/suspend` (or POST update) | **Absent** | Defer | Suspend | `platform.organizations.manage` | `suspendOrganization` | Same + CSRF | Same |
| 6 | POST | `/platform/organizations/{id}/reactivate` | **Absent** | Defer | Reactivate | `platform.organizations.manage` | `reactivateOrganization` | Same + CSRF | Same |
| 7 | POST | `/platform/organizations/{id}` (profile) | **Absent** | Defer | Cross-tenant profile edit | `platform.organizations.manage` | `updateOrganizationProfile` | Same + CSRF | Same |
| 8 | *tenant* | `/settings/...` or `/account/...` (example) | Various | **Do not** attach global org list here | In-tenant name/code | `organizations.profile.manage` + **resolved org only** | `updateOrganizationProfile` **only** when target id matches context | Same | Tenant path may have resolved org when branch set |

## Comparable route files (copy shape)

| File | Pattern |
|------|---------|
| `system/routes/web/register_branches.php` | `[AuthMiddleware::class, PermissionMiddleware::for('...')]` on each route |
| `system/routes/web/register_inventory.php` | Same; multiple GET/POST routes |
| `system/routes/web.php` | Ordered `require` of registrars |

## Comparable controllers (copy shape)

| Class | Methods | Notes |
|-------|---------|-------|
| `Modules\Branches\Controllers\BranchAdminController` | `index`, `create`, `store`, `edit`, `update`, `destroy` | Audit on mutate; flash + redirect |
| *(future)* `OrganizationRegistry*` controller | `index`, `show`, … | Inject F-40/F-41 services; register in `register_organizations.php` |

## Permission middleware (exact API)

- **`Core\Middleware\PermissionMiddleware::for(string $permission)`** — one permission per route instance.
- **`Core\Permissions\PermissionService::has(int $userId, string $permission)`** — used by middleware.

## Auth middleware (exact hook)

- **`Core\Middleware\AuthMiddleware::handle`** — ends with **`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()`** before `$next()`.
