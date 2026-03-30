# ORGANIZATION-REGISTRY-PLATFORM-HTTP-READ-SURFACE-AND-F25-COMPATIBILITY — FOUNDATION-43 (R1)

**Wave:** F-42 audit → **minimal** platform HTTP **read** + **narrow** F-25 path compatibility. **No** mutations, **no** `platform.organizations.manage` routes, **no** membership/context rewiring.

---

## 1. F-42 decisions implemented (exact)

| F-42 / matrix intent | This wave |
|----------------------|-----------|
| Phase **1a** — GET list + GET show only | **`GET /platform/organizations`**, **`GET /platform/organizations/{id}`** |
| Guard with **`platform.organizations.view`** | **`PermissionMiddleware::for('platform.organizations.view')`** on both routes |
| Use **`OrganizationRegistryReadService`** | **`PlatformOrganizationRegistryController`** delegates only to that service |
| URL prefix **`/platform/organizations`** | Matches F-42 illustrative prefix |
| F-25 blocks multi-org unresolved **before** permission | **GET-only** exemption for **exact** platform registry paths so **`PermissionMiddleware`** still runs |
| Do not broadly weaken F-25 | Exemption limited to two path shapes + **GET** only; all other routes unchanged |

---

## 2. Routes added

| Method | Path | Handler |
|--------|------|---------|
| GET | `/platform/organizations` | `PlatformOrganizationRegistryController::index` |
| GET | `/platform/organizations/{id:\d+}` | `PlatformOrganizationRegistryController::show` |

**Registrar:** `system/routes/web/register_platform_organization_registry.php`  
**Orchestrator:** `system/routes/web.php` — included after `register_branches.php`.

**Middleware (per route):** `AuthMiddleware`, `PermissionMiddleware::for('platform.organizations.view')`.

---

## 3. Controller / actions

| Class | Method | Behavior |
|-------|--------|----------|
| `Modules\Organizations\Controllers\PlatformOrganizationRegistryController` | `index()` | `listOrganizations()` → view |
| | `show(int $id)` | `getOrganizationById($id)`; missing → flash + redirect to list |

**DI:** `system/modules/bootstrap/register_organizations.php` — singleton constructor receives `OrganizationRegistryReadService` only.

---

## 4. Views

| Path | Role |
|------|------|
| `system/modules/organizations/views/platform-registry/index.php` | Table list (ID, name, code, suspended/archived flags, link to show) |
| `system/modules/organizations/views/platform-registry/show.php` | Read-only detail table |

Uses `shared/layout/base.php` and `data-table` / flash patterns consistent with **`modules/branches/views/`**.

---

## 5. F-25 compatibility adjustment (exact)

**File:** `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php`

- **`isExemptRequestPath()`** — after existing `/logout` and `/account/password` rules: if **`REQUEST_METHOD === GET`** and **`isPlatformOrganizationRegistryReadPath($path)`**, return **true**.
- **`isPlatformOrganizationRegistryReadPath(string $normalizedPath)`** — **true** iff:
  - `$normalizedPath === '/platform/organizations'`, or
  - `preg_match('#^/platform/organizations/\d+$#', $normalizedPath)`.

**Why narrow:** Only these **two** URL shapes, **GET only**. No POST (mutations), no prefix wildcard for `/platform/*`, no permission logic inside the gate (still **`platform.organizations.view`** on the route).

**Why safe:** Unauthenticated users still fail **`AuthMiddleware`**; authenticated users without the permission still get **403** from **`PermissionMiddleware`**. Unresolved-org staff on **other** routes still hit F-25 as before.

---

## 6. Intentionally not implemented

- HTTP create / update / suspend / reactivate (`OrganizationRegistryMutationService`)
- **`platform.organizations.manage`** on any route
- **`organizations.profile.manage`** HTTP
- F-25 exemption for mutations or for non-registry paths
- Nav/shell changes (no global header link; users open `/platform/organizations` directly or bookmark)
- Audit scripts (optional per task; omitted)

---

## 7. Backward compatibility

- Additive routes and one gate branch; existing staff flows unchanged.
- Single-org deployments: F-25 already no-op for count ≤ 1; new routes behave like any other permissioned page.

---

## 8. Single recommended next wave (name only)

**FOUNDATION-44 — ORGANIZATION-REGISTRY-PLATFORM-HTTP-MUTATION-SURFACE-MINIMAL-R1**

---

## 9. Stop

This wave ends at read HTTP + F-25 compatibility + docs + roadmap. **Do not** start FOUNDATION-44 in the same change set unless explicitly tasked.
