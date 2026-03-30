# TENANT-ACCESS-DATETIME-DATA-TRUTH-REPAIR-RULES

Task: **TENANT-ACCESS-DATETIME-DATA-TRUTH-AUDIT-AND-REPAIR-01**

Scope is limited to **users**, **user_organization_memberships**, **organizations**, and **branches** only where they affect founder tenant-access / access-shape truth. No other tables are modified.

## Schema note

`user_organization_memberships` carries lifecycle in **`status`** (`active` | `suspended` in application code). There is **no** `revoked_at` (or `suspended_at`) column on that pivot. Rules that mention “revoked + revoked_at” do not apply to this codebase; unknown status strings are **audit-only**, not auto-repaired.

## Read-only audit

Script: `system/scripts/read-only/audit_tenant_access_datetime_truth.php`

- Detects dirty datetime-like values (empty string, `0000-00-00…`) on scoped columns.
- Detects membership vs organization lifecycle contradictions and invalid `default_branch_id`.
- Detects user `branch_id` pins pointing at deleted branches or deleted organizations.
- Emits informational rows for tenant users with no active membership (no repair in this task).

## Repair script

Script: `system/scripts/repair_tenant_access_datetime_truth.php`

- **Default: dry-run** (prints planned SQL only).
- **`--apply`**: runs all planned updates in **one transaction** (all succeed or none).

## Explicit repair rules

| Rule | Condition | Action |
|------|-----------|--------|
| **R4** | `users.deleted_at`, `organizations.deleted_at`, `organizations.suspended_at`, or `branches.deleted_at` is non-NULL but “dirty” (empty string after trim, or `0000-00-00…` prefix). | `UPDATE … SET <column> = NULL WHERE id = ?` |
| **R3** | `user_organization_memberships.status = 'active'` and the joined **organization** row has **`deleted_at` NOT NULL** (soft-deleted org). | `UPDATE user_organization_memberships SET status = 'suspended', updated_at = CURRENT_TIMESTAMP WHERE (user_id, organization_id) = …` |
| **R1** | `user_organization_memberships.status = 'active'` and the joined organization is **not** deleted (`deleted_at IS NULL`) but **`suspended_at` IS NOT NULL**. | Same as R3: set membership to **`suspended`**. |
| **R2** | `default_branch_id` is NOT NULL and the branch row is missing, **`deleted_at` NOT NULL**, or **`organization_id` ≠ membership’s `organization_id`**. | `UPDATE user_organization_memberships SET default_branch_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE (user_id, organization_id) = …` |
| **R5** | `users.branch_id` references a branch with **`branches.deleted_at` NOT NULL**. | `UPDATE users SET branch_id = NULL WHERE id = ?` **unless** the user has **`platform_founder`** (via `user_roles` / `roles`); those rows are skipped. |
| **R6** | `users.branch_id` references a branch whose **organization** has **`deleted_at` NOT NULL**. | Same as R5: clear `branch_id`, with the same **platform principal** skip. |

## Rules intentionally not automated

- **Unknown `user_organization_memberships.status`** values other than `active` / `suspended`: reported by the audit only; requires a manual mapping before any bulk update.
- **Users with no active membership** (informational orphan / blocked access shape): use founder tenant-access UI or provisioning flows; not ambiguous enough to auto-fix here.
- **R1 semantic overlap**: If an organization is both deleted and suspended, **R3** and **R1** may both match the same row; the repair script emits two updates with the same end state (`suspended` membership), which is idempotent.

## Verification

1. Run the audit script (optionally `--json`).
2. Run the repair script dry-run, review planned statements.
3. Run `php system/scripts/repair_tenant_access_datetime_truth.php --apply` in a maintenance window if appropriate.
4. Re-run the audit; confirm targeted finding groups are empty.
5. Load `/platform-admin/tenant-access` as a founder user and confirm no SQL fatals.
