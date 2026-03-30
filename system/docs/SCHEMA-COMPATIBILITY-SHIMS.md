# Schema compatibility shims (runtime truth)

**Status:** Authoritative for **intentional** mixed-schema tolerance in this codebase. Canonical schema expectations live in `system/data/migrations/` and `system/data/full_project_schema.sql`. This file does **not** replace migrations; it documents why some code paths catch missing tables/columns instead of failing hard.

**Verification (read-only):** `php scripts/verify_core_schema_compat_readonly.php` (from `system/`).

---

## 1. `users.password_changed_at` (migration `055_users_password_changed_at.sql`)

**Canonical:** Column exists; used for `security.password_expiration` = `90_days` in `AuthMiddleware` (with fallback to `created_at` when the column is absent on the loaded row).

**Shim (kept):**

| Location | Behavior |
|----------|----------|
| `Core\Auth\SessionAuth::user()` | Try `SELECT` including `password_changed_at`; on PDO error message containing `password_changed_at`, retry without that column. |
| `Core\Auth\AuthService::updatePasswordForCurrentUser()` | Try `UPDATE` setting `password_changed_at`; on failure mentioning that column, update `password_hash` only. |
| `Modules\Auth\Services\PasswordResetService` | Same pattern on password reset completion. |

**Rationale:** Older databases that have not applied migration 055 must remain able to log in and change passwords without fatal errors.

---

## 2. Staff groups tables (migration `058_create_staff_groups_tables.sql`)

**Canonical:** Tables `staff_groups` and `staff_group_members` exist.

**Shim (kept):** `Modules\Staff\Repositories\StaffGroupRepository` — on `PDOException` with SQLSTATE `42S02` (base table not found):

- `listAssignableForServiceBranch` → empty list  
- `assertIdsAssignableToService` → `DomainException('Staff groups are not available.')`  
- `hasActiveGroupsForBranch` → `false`  
- `isStaffInAnyActiveGroupForBranch` → `false`  

**Rationale:** Scheduling and service UIs must not 500 when migration 058 is not applied; features degrade to “no groups”.

---

## 3. `staff_group_permissions` (migration `066_create_staff_group_permissions_table.sql`)

**Canonical:** Table exists; backs group-level permission grants merged in `PermissionService`.

**Shim (kept):** `Core\Permissions\StaffGroupPermissionRepository::listPermissionCodesForUserInBranchScope()` — on `42S02`, returns `[]` (no extra group permissions).

**Rationale:** RBAC falls back to direct user permissions only when the pivot table is missing; admin assignment APIs that write to this table are not used on every request.

---

## Non-goals

- No automatic schema repair from these shims. Apply pending migrations with `php scripts/migrate.php` (or your deployment process).
- Removing shims is a **separate**, explicit breaking-change task once all supported installs are on canonical schema.
