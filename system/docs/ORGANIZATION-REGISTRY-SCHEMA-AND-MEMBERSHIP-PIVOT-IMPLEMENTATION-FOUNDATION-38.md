# ORGANIZATION-REGISTRY-SCHEMA-AND-MEMBERSHIP-PIVOT — FOUNDATION-38 (IMPLEMENTATION)

**Wave:** **S1** only per **`ORGANIZATION-REGISTRY-AND-PLATFORM-CONTROL-PLANE-MINIMAL-DESIGN-FOUNDATION-37.md`**. **Additive** DDL + read-only verifier + docs. **No** runtime, routes, permissions seeds, auth/context changes, UI, or data backfill.

---

## 1. F-37 decisions implemented (exact)

| Decision | Implementation |
|----------|------------------|
| **`organizations.suspended_at`** nullable timestamp | **`087_organization_registry_membership_foundation.sql`** `ALTER TABLE organizations ADD COLUMN suspended_at ...` |
| **`user_organization_memberships`** pivot | New table: composite **`PRIMARY KEY (user_id, organization_id)`** |
| Columns **`user_id`**, **`organization_id`**, **`status`**, **`created_at`**, **`updated_at`**, optional **`default_branch_id`** | All present; **`status`** = **`VARCHAR(20) NOT NULL DEFAULT 'active'`** (values **active / invited / revoked** per F-37; not DB-enforced ENUM) |
| FK **`user_id` → users** | **`ON DELETE CASCADE`** |
| FK **`organization_id` → organizations** | **`ON DELETE RESTRICT`** |
| FK **`default_branch_id` → branches** | **`ON DELETE SET NULL`** |
| Secondary indexes | **`idx_user_organization_memberships_organization_id`**, **`idx_user_organization_memberships_status`** |

---

## 2. Tables / columns / constraints added

**Migration:** `system/data/migrations/087_organization_registry_membership_foundation.sql`

**Canonical snapshot:** `system/data/full_project_schema.sql` updated (header through **087**; **`organizations`** + new table).

| Object | Detail |
|--------|--------|
| **`organizations.suspended_at`** | `TIMESTAMP NULL DEFAULT NULL` after **`updated_at`** |
| **`user_organization_memberships`** | Engine InnoDB; FKs named **`fk_user_organization_memberships_user`**, **`fk_user_organization_memberships_organization`**, **`fk_user_organization_memberships_default_branch`** |

---

## 3. Intentionally NOT implemented (this wave)

- Organization CRUD HTTP / services
- **`platform.*`** / **`organizations.profile.manage`** permission rows (F-37 **S2**)
- Membership-aware **`OrganizationContextResolver`** / gates / suspended-org enforcement
- Backfill from **`users.branch_id`** into **`user_organization_memberships`** (F-37 optional phase-1 backfill — **not** mandated for schema-only wave)
- Seed redesign
- Branch-domain or **`users.branch_id`** removal

---

## 4. Backward compatibility

- **Existing rows:** **`suspended_at`** is NULL → interpret as “not suspended” for future code.
- **Empty pivot:** Valid; runtime **unchanged**; inference still branch / single-org (F-09, F-25).
- **`users.branch_id`:** Unchanged; verifier asserts column still exists.

---

## 5. Verifier usage

**Command** (from `system/`):

```bash
php scripts/verify_organization_registry_schema.php
php scripts/verify_organization_registry_schema.php --json
```

**Success (exit 0):**

- **`organizations.suspended_at`** exists
- **`user_organization_memberships`** exists with required columns
- **PRIMARY** + both secondary indexes + three named FKs present
- **`users.branch_id`** present; core tables **`organizations`**, **`branches`**, **`users`**, **`settings`**, **`roles`**, **`permissions`** exist

**Failure (exit 1):** Any check above missing (e.g. migration not applied).

---

## 6. Single recommended next wave (name only)

**FOUNDATION-39 — PLATFORM-AND-ORGANIZATION-PROFILE-PERMISSION-CATALOG-MINIMAL-R1**

(F-37 **S2**: insert **`platform.organizations.view`**, **`platform.organizations.manage`**, **`organizations.profile.manage`** — or exact codes chosen at implementation time; **no** route wiring required in that wave’s minimal form if task stays seed-only.)

---

## 7. Acceptance

This document does **not** claim production acceptance; operators must **run migrations** and the verifier on target DBs.
