# ORGANIZATION-REGISTRY-PLATFORM-HTTP-MUTATION-SURFACE-AND-F25-COMPATIBILITY — FOUNDATION-44 (R1)

**Wave:** F-41 mutation service + F-43 read HTTP + F-42/F-37 design → **platform HTTP mutations** + **narrow F-25 extension**. **No** `organizations.profile.manage` HTTP, **no** membership rewiring, **no** org delete.

---

## 1. Prior decisions operationalized

| Source | This wave |
|--------|-----------|
| **F-41** `OrganizationRegistryMutationService` | HTTP delegates only to **`createOrganization`**, **`updateOrganizationProfile`**, **`suspendOrganization`**, **`reactivateOrganization`** — field scope unchanged (**name** / **code** only for create/update) |
| **F-42** | Platform routes require **F-25** compatibility so **`PermissionMiddleware`** runs when org context is unresolved (multi-org) |
| **F-43** | Read routes + **GET** exemptions **unchanged**; manage paths added with **separate** gate rules |
| **F-37** §7 | Phase-1 platform create / suspend / reactivate / cross-tenant profile edit via **`platform.organizations.manage`** (HTTP now) |

---

## 2. Routes added (`register_platform_organization_registry.php`)

| Method | Path | Controller | Permission |
|--------|------|------------|------------|
| GET | `/platform/organizations` | `PlatformOrganizationRegistryController::index` | `platform.organizations.view` |
| GET | `/platform/organizations/create` | `PlatformOrganizationRegistryManageController::create` | `platform.organizations.manage` |
| POST | `/platform/organizations` | `PlatformOrganizationRegistryManageController::store` | `platform.organizations.manage` |
| GET | `/platform/organizations/{id}/edit` | `PlatformOrganizationRegistryManageController::edit` | `platform.organizations.manage` |
| POST | `/platform/organizations/{id}/suspend` | `PlatformOrganizationRegistryManageController::suspend` | `platform.organizations.manage` |
| POST | `/platform/organizations/{id}/reactivate` | `PlatformOrganizationRegistryManageController::reactivate` | `platform.organizations.manage` |
| POST | `/platform/organizations/{id}` | `PlatformOrganizationRegistryManageController::update` | `platform.organizations.manage` |
| GET | `/platform/organizations/{id}` | `PlatformOrganizationRegistryController::show` | `platform.organizations.view` |

**Registration order:** More specific paths (`create`, `{id}/edit`, `{id}/suspend`, `{id}/reactivate`, POST `{id}`) before GET `{id}` so the router resolves correctly.

---

## 3. Controller / actions

| Class | Methods |
|-------|---------|
| **`PlatformOrganizationRegistryManageController`** | `create`, `store`, `edit`, `update`, `suspend`, `reactivate` — all use **`OrganizationRegistryMutationService`** / **`OrganizationRegistryReadService`** (load for forms), **no** duplicated SQL |
| **`PlatformOrganizationRegistryController`** | `index`, `show` — injects **`PermissionService`** to set **`canManageOrganizations`** for minimal UI links (manage users still require **`platform.organizations.manage`** on mutation URLs) |

**DI:** `system/modules/bootstrap/register_organizations.php` — manage controller singleton; read controller updated with **`PermissionService`**.

---

## 4. Permission guard

- All **new** mutation routes: **`AuthMiddleware`** + **`PermissionMiddleware::for('platform.organizations.manage')`**.
- **Read** routes remain **`platform.organizations.view`** (F-43).

---

## 5. F-25 compatibility (exact)

**File:** `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php`

**New:** **`isPlatformOrganizationRegistryManagePath(string $normalizedPath, string $method): bool`**

- **GET** `/platform/organizations/create`
- **GET** `/platform/organizations/<digits>/edit`
- **POST** `/platform/organizations` (create body)
- **POST** `/platform/organizations/<digits>` (profile update)
- **POST** `/platform/organizations/<digits>/suspend`
- **POST** `/platform/organizations/<digits>/reactivate`

**Unchanged:** **`isPlatformOrganizationRegistryReadPath`** (F-43 GET list/show). **`isExemptRequestPath`** calls read helper first, then manage helper.

**Narrow / safe:** Only these path shapes and methods; **no** wildcard `/platform/*`. **Auth** + **permission** still apply after the gate. Tenant routes and F-25 behavior elsewhere unchanged.

---

## 6. Views / UX (minimal)

- **`create.php`**, **`edit.php`** — form fields **name**, **code** (optional / clear-on-empty on edit via POST `code`).
- **`index.php`**, **`show.php`** — optional **Add / Edit / Suspend / Reactivate** when **`canManageOrganizations`** (mirrors **`BranchAdminController`** pattern).

CSRF: all POSTs use configured token (same as branches).

---

## 7. Intentionally not implemented

- **`organizations.profile.manage`** (in-tenant, resolved-org-only profile HTTP)
- **`user_organization_memberships`** HTTP / backfill / resolver changes
- Org **archive** / **`deleted_at`** mutation (F-37 optional / deferred)
- **Audit** table logging for mutations (F-37 S4 fuller slice)
- **`platform.organizations.view`** behavior change
- Nav shell / design system work beyond existing layout + `data-table`

---

## 8. Single recommended next wave (name only)

**FOUNDATION-45 — USER-ORGANIZATION-MEMBERSHIP-AND-ORGANIZATION-CONTEXT-RESOLUTION-INTEGRATION-MINIMAL-R1**

---

## 9. Stop

This wave ends at mutation HTTP + F-25 extension + docs + roadmap + ZIP. **Do not** start FOUNDATION-45 unless explicitly tasked.
