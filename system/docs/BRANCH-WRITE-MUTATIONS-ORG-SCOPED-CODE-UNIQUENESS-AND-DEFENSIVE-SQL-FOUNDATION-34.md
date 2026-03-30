# BRANCH-WRITE-MUTATIONS-ORG-SCOPED-CODE-UNIQUENESS-AND-DEFENSIVE-SQL — FOUNDATION-34

**Wave:** minimal R1 — **`system/core/Branch/BranchDirectory.php`** only (`createBranch`, `updateBranch`, `softDeleteBranch`, `isCodeTaken`). **No** migrations, **no** UI, **no** middleware, **no** permission/bootstrap changes.

---

## 1. Previous behavior (FOUNDATION-33 baseline)

| Area | Behavior |
|------|----------|
| **`createBranch`** | `organization_id` = resolved context id when **non-null** (including **0** coerced path historically), else `defaultOrganizationIdForNewBranch()` (**MIN** active org). **Note:** implementation now treats only **positive** resolved ids as the dedicated path (aligned with F-30/F-32). |
| **`isCodeTaken`** | **Global** `branches.code` collision across **all** rows; **no** `deleted_at` filter (soft-deleted rows still reserve codes). |
| **`updateBranch` / `softDeleteBranch`** | Preconditions: `getBranchByIdForAdmin` + `OrganizationScopedBranchAssert`. **SQL** targeted rows by **`id` only** (no `organization_id` in `WHERE`). |

---

## 2. New behavior when organization context is **resolved** (`getCurrentOrganizationId()` non-null and **> 0**)

| Method | Change |
|--------|--------|
| **`createBranch`** | Inserts under **that** `organization_id` only; **does not** invoke `defaultOrganizationIdForNewBranch()`. **`isCodeTaken`** runs **per organization** (`code` + `organization_id`). |
| **`updateBranch`** | Same validation/assert as before; **`UPDATE ... WHERE id = ? AND organization_id = ?`**. **`isCodeTaken`** is **per organization**. |
| **`softDeleteBranch`** | **`UPDATE ... WHERE id = ? AND organization_id = ? AND deleted_at IS NULL`**. |
| **`isCodeTaken`** | `WHERE code = ? AND organization_id = ?` (and `id <> ?` when excluding). |

**Deleted / not-deleted:** Still **no** `deleted_at` predicate on code checks — semantics unchanged; org scope added only.

---

## 3. Preserved behavior when organization context is **unresolved** (`null` or **≤ 0**)

| Method | Behavior |
|--------|----------|
| **`createBranch`** | `organization_id` from **`defaultOrganizationIdForNewBranch()`** (legacy MIN-org pin). **`isCodeTaken`** remains **global** across all branches. |
| **`updateBranch` / `softDeleteBranch`** | SQL remains **id-only** (plus `deleted_at IS NULL` on soft delete). **`isCodeTaken`** remains **global**. |

**Intentional:** This wave does **not** introduce fail-closed create or new exceptions on null-org paths (per task boundary).

---

## 4. Why multi-org unresolved staff does not rely on this file

**FOUNDATION-25** **`StaffMultiOrgOrganizationResolutionGate`** (invoked from **`AuthMiddleware`** after authentication): when **more than one** active organization exists and **`OrganizationContext`** did not resolve to a positive org id, staff requests are **403** before **`BranchAdminController`** mutators run. So **normal** multi-org branch admin traffic arrives with **resolved** org (or is blocked). **FOUNDATION-33** proved the only mutation entrypoints are those controller actions.

---

## 5. Why permissions, resolver, and middleware were not touched

Branch writes are already gated by **`branches.manage`** and org ownership assert + F-32 admin reads. This wave adds **defense in depth** on the **SQL** `WHERE` clause and removes **cross-org `code` coupling** when context resolves — without redefining RBAC, org resolution rules, or route registration.

---

## 6. Optional QA proof notes (three contexts)

| Context | Expected |
|---------|----------|
| **Resolved org = 1** | Branch A in org 1 with `code = 'X'` does **not** block org **2** from creating/updating another branch with `code = 'X'`. Update/delete SQL requires **`organization_id = 1`** when mutating under that context. |
| **Resolved org = 2** | Same as above, mirrored: codes independent per org; mutations include **`organization_id = 2`**. |
| **Null org** (degenerate / legacy path, e.g. zero-org gate escape + F-09 modes) | **`isCodeTaken`** still **global**; create still uses **`defaultOrganizationIdForNewBranch()`** when that path runs; UPDATE/DELETE **id-only** SQL unchanged. |

---

## 7. Out of scope (explicit)

- **`listAllBranchesForAdmin` / `getBranchByIdForAdmin` / `getActiveBranchesForSelection` / `isActiveBranchId`** — unchanged.
- **FOUNDATION-35** or further waves — not opened here.
