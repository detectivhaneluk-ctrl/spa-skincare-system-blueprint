# ORGANIZATION-REGISTRY-READ-SERVICE — FOUNDATION-40 (IMPLEMENTATION)

**Wave:** F-37 **S3** read layer only — **no** HTTP, **no** permission enforcement wiring, **no** mutations.

---

## 1. F-37 decisions operationalized

| F-37 intent | This wave |
|-------------|-----------|
| Platform registry **list/read** (future **`platform.organizations.*`**) | **`OrganizationRegistryReadService::listOrganizations()`**, **`getOrganizationById()`** |
| **Global** organization listing (not tenant-scoped) | Repository SQL has **no** `OrganizationContext` / branch filter |
| Phase-1 fields including **suspend** state | **`suspended_at`** included in row shape (**F-38** column) |

---

## 2. Files / classes added or changed

| Path | Role |
|------|------|
| **`system/modules/organizations/repositories/OrganizationRegistryReadRepository.php`** | `listAllOrderedById()`, `findById()` |
| **`system/modules/organizations/services/OrganizationRegistryReadService.php`** | Thin facade: `listOrganizations()`, `getOrganizationById()` |
| **`system/modules/bootstrap/register_organizations.php`** | DI singletons |
| **`system/modules/bootstrap.php`** | Requires **`register_organizations.php`** after **`register_branches.php`** |
| **`system/scripts/audit_organization_registry_read_service.php`** | Read-only contract audit |

---

## 3. Read contracts

**`OrganizationRegistryReadService`**

| Method | Returns |
|--------|---------|
| **`listOrganizations(): array`** | **List** of rows, **`id ASC`** |
| **`getOrganizationById(int $organizationId): ?array`** | One row or **`null`**; **`id <= 0`** ⇒ **`null`** |

**Row shape (each associative array):**

| Key | Type (runtime) |
|-----|----------------|
| **`id`** | int/string (driver) |
| **`name`** | string |
| **`code`** | string or null |
| **`created_at`** | string |
| **`updated_at`** | string |
| **`suspended_at`** | string or null |
| **`deleted_at`** | string or null |

---

## 4. Intentionally NOT implemented

- Routes/controllers/views
- **`PermissionMiddleware`** / platform guards
- INSERT/UPDATE/DELETE for organizations
- **`user_organization_memberships`** reads
- **`OrganizationContextResolver`** changes
- Role/permission assignment logic

---

## 5. Backward compatibility

- **Additive** DI + new module folder; existing runtime unchanged.
- No new middleware; no automatic calls from existing controllers.

---

## 6. Verifier usage

**From `system/`:** (loads **`modules/bootstrap.php`** so DI includes **`OrganizationRegistryReadService`**.)

```bash
php scripts/audit_organization_registry_read_service.php
php scripts/audit_organization_registry_read_service.php --json
```

**Success (exit 0):** list length matches `SELECT COUNT(*) FROM organizations`; every list row and **`getOrganizationById`** (first id) contain all required keys; **`getOrganizationById(0)`** and a non-existent id return **`null`**.

**Failure (exit 1):** shape/count mismatch or exception.

---

## 7. Single recommended next wave (name only)

**FOUNDATION-41 — ORGANIZATION-REGISTRY-MUTATION-SERVICE-MINIMAL-R1**

(F-37 **S4** — platform create/suspend + org-profile updates behind future guards; implement only when tasked.)

---

## 8. Acceptance

Does not claim final production acceptance.
