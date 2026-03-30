# Branch catalog — shared selector org scope — implementation (FOUNDATION-30)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-30 — BRANCH-CATALOG-SHARED-SELECTOR-ORG-SCOPE-MINIMAL-IMPLEMENTATION-R1  
**Scope:** `Core\Branch\BranchDirectory::getActiveBranchesForSelection()` only.  
**Out of scope (explicit):** `listAllBranchesForAdmin`, `getBranchByIdForAdmin`, `isActiveBranchId`; resolver/middleware/gate; public validators; branch-admin CRUD reads.

---

## 1. Old selector behavior

`getActiveBranchesForSelection()` executed:

`SELECT id, name, code FROM branches WHERE deleted_at IS NULL ORDER BY name`

with **no** `organization_id` filter. Every staff consumer received **all** active branches in the database (cross-organization metadata exposure when multiple orgs exist). Truth audit: `BRANCH-CATALOG-SHARED-READ-SURFACES-TRUTH-AUDIT-FOUNDATION-29-OPS.md`.

---

## 2. New selector behavior

- If `OrganizationContext::getCurrentOrganizationId()` is **non-null** and **> 0**:
  - `SELECT id, name, code FROM branches WHERE deleted_at IS NULL AND organization_id = ? ORDER BY name` with bound org id.
- If the current organization id is **null** (or not positive):
  - **Unchanged** legacy query: global active branches (same SQL as pre–FOUNDATION-30).

`listAllBranchesForAdmin()`, `getBranchByIdForAdmin()`, and `isActiveBranchId()` are **unchanged**.

---

## 3. Why unresolved multi-org staff is already blocked earlier

On deployments with **more than one** active organization, `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()` (invoked from `AuthMiddleware` after successful auth) returns **403** when `OrganizationContext` has no resolved org id. Exempt paths: `POST /logout`, `GET`/`POST /account/password` only. Therefore authenticated staff on multi-org cannot reach normal module pages that render shared selectors while org is unresolved (FOUNDATION-25 / FOUNDATION-26 / FOUNDATION-27).

---

## 4. Why null-org legacy fallback is intentionally preserved

Some contexts still leave `OrganizationContext` without a resolved id (e.g. **zero** active organizations degenerate, non-HTTP callers, or any future path where middleware runs but org stays null). FOUNDATION-30 **does not** introduce new fail-closed behavior for selectors: when org id is null, behavior matches the **pre-wave** global list so existing dual-path semantics stay stable until a separate product decision.

---

## 5. Why admin list/id reads are deferred

`listAllBranchesForAdmin()` and `getBranchByIdForAdmin()` power **`/branches`** administration (global catalog including soft-deleted rows). Whether that surface should become org-scoped, permission-tiered “super-admin,” or split is a **product and permission** decision. FOUNDATION-29 recommended one future coordinated wave for that; FOUNDATION-30 implements **only** the shared operational selector path.

---

## 6. Optional QA proof note (expected outcomes)

Assume branches A1, A2 belong to **organization 1**; B1 belongs to **organization 2**; all `deleted_at IS NULL`.

| Resolved org id | Expected `getActiveBranchesForSelection()` rows |
|-----------------|--------------------------------------------------|
| **1** | A1, A2 only (ordered by name). |
| **2** | B1 only. |
| **null** | A1, A2, B1 (global legacy — same as pre–FOUNDATION-30). |

This is illustrative; runtime verification is operator/release QA, not part of this wave.

---

## 7. Artifact

Checkpoint ZIP (hygiene per `handoff/build-final-zip.ps1` / `HandoffZipRules.ps1`): excludes `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip`.
