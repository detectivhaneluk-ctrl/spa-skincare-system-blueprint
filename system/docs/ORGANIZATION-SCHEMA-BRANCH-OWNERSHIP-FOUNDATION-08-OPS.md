# Organization schema and branch ownership — FOUNDATION-08 ops

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-08  
**Scope:** DB truth only — `organizations` + `branches.organization_id` + backfill + minimal branch-create compatibility.  
**Normative design context:** `ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md` (wave A).

---

## What shipped

| Artifact | Role |
|----------|------|
| `system/data/migrations/086_organizations_and_branch_ownership_foundation.sql` | Creates `organizations`, adds nullable then NOT NULL `branches.organization_id`, backfill, index + FK. |
| `system/data/full_project_schema.sql` | Canonical snapshot: `organizations` before `branches`, NOT NULL + FK, bootstrap INSERT for default org on empty DB. |
| `system/core/Branch/BranchDirectory.php` | `createBranch()` sets `organization_id` to **MIN(id)** among `organizations` with `deleted_at IS NULL`. |
| `system/scripts/verify_organization_branch_ownership_readonly.php` | Read-only ownership report (`--json` optional). |
| `system/scripts/dev-only/seed_branch_smoke_data.php` | Uses default org when inserting branches (dev smoke). |

---

## Apply migration (incremental installs)

From `system/`:

```bash
php scripts/migrate.php
```

**Backfill rules (in SQL):**

- If `organizations` is empty, inserts one row: name `Default organization`, `code` NULL.
- Sets `branches.organization_id` to `MIN(organizations.id)` for any row still NULL, then enforces NOT NULL + `fk_branches_organization`.

Re-running failed statements on older migrate legacy mode may log tolerated duplicate-column / duplicate-FK messages; the `migrations` table remains authoritative for full file application.

---

## Verify (read-only)

From `system/`:

```bash
php scripts/verify_organization_branch_ownership_readonly.php
php scripts/verify_organization_branch_ownership_readonly.php --json
```

**Clean ownership** (exit **0**):

- `organizations_count` ≥ 1  
- `branches_with_null_organization_count` === 0  
- `orphaned_branch_organization_refs_count` === 0  

Exit **1** if `organizations` table or `branches.organization_id` column is missing.  
Exit **2** if schema exists but counts are not clean.

---

## Runtime assumptions (explicit)

- **No** organization middleware, session field, or query scoping — not in this wave.
- **Single-org behavior:** new branches always attach to the **lowest-id active** organization. Multi-org selection is a future task.
- Deleting the last organization or soft-deleting all organizations will cause `createBranch()` to throw until data is repaired.

---

## Fresh canonical DB (`migrate.php --canonical`)

The snapshot creates `organizations`, `branches` (with FK), then idempotent INSERT for the default organization when the table is empty, so subsequent branch inserts can satisfy `organization_id`.

---

## Related

- Truth audit: `ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`  
- Canonical boundary plan: `ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md`  
- Runtime org context (next slice): `ORGANIZATION-CONTEXT-RESOLUTION-FOUNDATION-09-OPS.md`
