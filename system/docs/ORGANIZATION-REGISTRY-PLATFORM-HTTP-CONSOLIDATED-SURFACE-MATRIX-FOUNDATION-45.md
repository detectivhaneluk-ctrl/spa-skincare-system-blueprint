# ORGANIZATION-REGISTRY-PLATFORM-HTTP-CONSOLIDATED-SURFACE-MATRIX — FOUNDATION-45

Companion to **`ORGANIZATION-REGISTRY-PLATFORM-HTTP-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-45-OPS.md`**.

## 1. Route × action × permission × service × F-25

| # | Method | Normalized path pattern | Controller::action | Permission | Service call(s) | F-25 exempt? |
|---|--------|-------------------------|---------------------|------------|-----------------|--------------|
| 1 | GET | `/platform/organizations` | `PlatformOrganizationRegistryController::index` | `platform.organizations.view` | `listOrganizations()` | Yes (GET read) |
| 2 | GET | `/platform/organizations/create` | `PlatformOrganizationRegistryManageController::create` | `platform.organizations.manage` | — (form) | Yes (manage) |
| 3 | POST | `/platform/organizations` | `PlatformOrganizationRegistryManageController::store` | `platform.organizations.manage` | `createOrganization` | Yes (manage) |
| 4 | GET | `/platform/organizations/{id}/edit` | `PlatformOrganizationRegistryManageController::edit` | `platform.organizations.manage` | `getOrganizationById` | Yes (manage) |
| 5 | POST | `/platform/organizations/{id}/suspend` | `PlatformOrganizationRegistryManageController::suspend` | `platform.organizations.manage` | `suspendOrganization` | Yes (manage) |
| 6 | POST | `/platform/organizations/{id}/reactivate` | `PlatformOrganizationRegistryManageController::reactivate` | `platform.organizations.manage` | `reactivateOrganization` | Yes (manage) |
| 7 | POST | `/platform/organizations/{id}` | `PlatformOrganizationRegistryManageController::update` | `platform.organizations.manage` | `updateOrganizationProfile` | Yes (manage) |
| 8 | GET | `/platform/organizations/{id}` | `PlatformOrganizationRegistryController::show` | `platform.organizations.view` | `getOrganizationById` | Yes (GET read) |

**Source of truth for paths:** `system/routes/web/register_platform_organization_registry.php`.  
**F-25 mirror:** `StaffMultiOrgOrganizationResolutionGate::isPlatformOrganizationRegistryReadPath` + `isPlatformOrganizationRegistryManagePath`.

## 2. Middleware stack order (reminder)

`Dispatcher` global middleware → **`AuthMiddleware`** (includes F-25) → **`PermissionMiddleware`** → controller.

## 3. Field scope (HTTP → service)

| Action | POST fields | Service payload |
|--------|-------------|-----------------|
| **store** | `name`, optional `code` | `['name' => …]` + `['code' => …]` only if non-empty trimmed code |
| **update** | `name`, `code` (key present) | `name` always; `code` => null if empty trim else string |

Matches **`OrganizationRegistryMutationService`** (F-41).

## 4. Catalog code without HTTP (`organizations.profile.manage`)

| Code | HTTP usage in `system/**/*.php` |
|------|----------------------------------|
| `organizations.profile.manage` | **None** (seed, migration **088**, `verify_platform_permission_catalog.php` only) |

## 5. Drift hazard (W-1)

Any new route under `/platform/organizations` **must** update **both**:

1. `register_platform_organization_registry.php`  
2. `StaffMultiOrgOrganizationResolutionGate` (read and/or manage helpers)

or multi-org unresolved-org users may be **blocked** or **incorrectly** allowed.
