# PLATFORM-AND-ORGANIZATION-PROFILE-PERMISSION-CATALOG — FOUNDATION-39 (IMPLEMENTATION)

**Wave:** F-37 **S2** — permission **catalog** only. **No** route guards, **no** `PermissionMiddleware` wiring, **no** role redesign, **no** `role_permissions` rows in migration **088**.

---

## 1. F-37 permission decisions implemented

| F-37 decision | Codes added |
|---------------|-------------|
| **Platform / cross-tenant org registry** | **`platform.organizations.view`**, **`platform.organizations.manage`** |
| **In-tenant org profile (resolved context)** | **`organizations.profile.manage`** |

Naming matches **`ORGANIZATION-REGISTRY-AND-PLATFORM-CONTROL-PLANE-MINIMAL-DESIGN-FOUNDATION-37.md` §3.1.

---

## 2. Files touched (catalog / deterministic sync)

| File | Change |
|------|--------|
| **`system/data/migrations/088_platform_organization_profile_permissions_catalog.sql`** | **`INSERT IGNORE INTO permissions (code, name)`** × 3 |
| **`system/data/seeders/001_seed_roles_permissions.php`** | Same three codes appended to **`$permissions`** array (deterministic seed parity) |

**Not changed:** `PermissionService`, middleware, routes, `staff_group_permissions`, `role_permissions` in SQL.

---

## 3. Owner role on fresh seed (existing behavior)

**`001_seed_roles_permissions.php`** grants **`owner`** **every** `permissions.id` via **`INSERT IGNORE role_permissions`**. Adding catalog rows therefore gives **owner** the new codes **when the full seeder runs** on a DB where those rows exist. This mirrors historical behavior for new permissions (e.g. **`branches.*`**). **Migration-only** deploys: rows appear in **`permissions`**; **no automatic** grant to **`admin`** or **`reception`**; **owner** gains grants only if an operator re-runs the owner-assignment portion or assigns manually.

---

## 4. Intentionally NOT implemented

- Platform / org-profile **route** or **middleware** enforcement
- Organization registry **services** / HTTP (F-37 **S3/S4**)
- Membership-aware **OrganizationContext** (F-37 **S5**)
- **`role_permissions`** backfill in **088** (catalog-only mandate)
- UI, founder dashboard, branch-domain changes

---

## 5. Backward compatibility

- **`INSERT IGNORE`** — re-runnable; no deletes.
- Existing module permissions unchanged; verifier probes **`branches.view`** still present.

---

## 6. Verifier usage

**From `system/`:**

```bash
php scripts/verify_platform_permission_catalog.php
php scripts/verify_platform_permission_catalog.php --json
```

**Success (exit 0):**

- **088** and **001** source files contain all three codes (quoted).
- DB has rows for all three codes (after **`migrate.php`** applies **088**).
- **`branches.view`** still exists in **`permissions`**.

**Failure (exit 1):** Any check fails (e.g. migration not applied).

---

## 7. Single recommended next wave (name only)

**FOUNDATION-40 — ORGANIZATION-REGISTRY-READ-SERVICE-MINIMAL-R1**

(F-37 **S3**: read-only registry service / optional HTTP — implement only when tasked.)

---

## 8. Acceptance

Does not claim production acceptance. Operators must **run migrations** and the verifier.
