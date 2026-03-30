# Branch-admin read surfaces — org scope — implementation (FOUNDATION-32)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-32 — BRANCH-ADMIN-READ-SURFACES-ORG-SCOPE-MINIMAL-IMPLEMENTATION-R1  
**Scope:** `Core\Branch\BranchDirectory::listAllBranchesForAdmin()` and `getBranchByIdForAdmin(int $id)` only.  
**Unchanged:** `getActiveBranchesForSelection()`, `isActiveBranchId()`, `updateBranch` / `softDeleteBranch` bodies (they inherit stricter admin reads via `getBranchByIdForAdmin` when org resolved), middleware, gates, routes, permissions.

---

## 1. Old admin-read behavior

- **`listAllBranchesForAdmin()`** — `SELECT id, name, code, deleted_at FROM branches ORDER BY name` (all organizations, soft-deleted included).
- **`getBranchByIdForAdmin($id)`** — `SELECT ... FROM branches WHERE id = ?` (any organization).

Truth audit: `BRANCH-ADMIN-READ-SURFACES-TRUTH-AND-DECISION-AUDIT-FOUNDATION-31-OPS.md`.

---

## 2. New admin-read behavior

**When `OrganizationContext::getCurrentOrganizationId()` is non-null and `> 0`:**

- **`listAllBranchesForAdmin()`** — `SELECT id, name, code, deleted_at FROM branches WHERE organization_id = ? ORDER BY name` (same columns, same soft-deleted inclusion, org filter only).
- **`getBranchByIdForAdmin($id)`** — `SELECT ... WHERE id = ? AND organization_id = ?`; returns **null** if no matching row (same shape as “not found”).

**When organization id is null (or not positive):**

- **Unchanged** legacy queries: global list and id-only row lookup (same as pre–FOUNDATION-32).

---

## 3. Why unresolved multi-org staff is already blocked earlier

For deployments with **more than one** active organization, `StaffMultiOrgOrganizationResolutionGate` (post-`AuthMiddleware`) returns **403** when organization context is unresolved. `/branches` is not exempt. So normal multi-org staff reach branch admin only with **resolved** org (FOUNDATION-25 / FOUNDATION-31).

---

## 4. Why null-org legacy fallback is intentionally preserved

Paths with **no** resolved org id (e.g. zero-org degenerate, or non-HTTP callers) keep **global** admin list and **id-only** admin load so this wave does **not** introduce new fail-closed behavior; parity with FOUNDATION-30 null-org handling for selectors.

---

## 5. Why this wave does not redesign `branches.*` or super-admin logic

Route registration and `PermissionMiddleware` keys (`branches.view`, `branches.manage`) are **unchanged**. No new role, no platform-only guard: enforcement is **data scoping** at read time when org context resolves, matching the FOUNDATION-31 decision **B** without option **C** (split permission model).

---

## 6. Internal interaction with `updateBranch` / `softDeleteBranch`

Both call `getBranchByIdForAdmin` before mutating. With **resolved** org, an out-of-org id returns **null** → `InvalidArgumentException` **Branch not found** before `OrganizationScopedBranchAssert` runs. With **null** org, behavior matches legacy id-only load + assert no-op/assert as before (F-11).

---

## 7. Optional QA proof note

Assume branch rows A1, A2 → org **1**; B1 → org **2**; mixed active/soft-deleted as stored.

| Resolved org id | `listAllBranchesForAdmin()` | `getBranchByIdForAdmin(A1)` | `getBranchByIdForAdmin(B1)` |
|-----------------|----------------------------|----------------------------|----------------------------|
| **1** | A1, A2 only (by name) | row | **null** |
| **2** | B1 only | **null** | row |
| **null** | all branches (global) | row for A1 if id matches | row for B1 if id matches |

Operator QA remains outside this wave.

---

## 8. Checkpoint ZIP

Built via `handoff/build-final-zip.ps1` with standard exclusions (`.env`, `storage/logs/**`, `storage/backups/**`, `*.log`, nested `*.zip`).
