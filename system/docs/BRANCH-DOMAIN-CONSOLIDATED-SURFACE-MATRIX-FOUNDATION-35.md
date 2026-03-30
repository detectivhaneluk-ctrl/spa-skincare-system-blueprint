# BRANCH-DOMAIN-CONSOLIDATED-SURFACE-MATRIX — FOUNDATION-35

Consolidated matrix for **post F-30 / F-32 / F-34** tree. **Read-only** audit artifact.

**Legend — multi-org unresolved (staff HTTP):** **`StaffMultiOrgOrganizationResolutionGate`** (F-25) ⇒ **403** when **>1** active org and org context not resolved — typical staff UI **not reached**.

| `BranchDirectory` API | Callers (summary) | Resolved org behavior | Null-org behavior | Multi-org unresolved HTTP | Cross-org / global notes |
|----------------------|-------------------|------------------------|-------------------|---------------------------|---------------------------|
| `isActiveBranchId` | `BranchContextMiddleware`; `ProductController`; `ClientMembershipController` | **Global** id + `deleted_at IS NULL` | Same | N/A at gate layer | **No** org filter; runs **before** org middleware reset |
| `getActiveBranchesForSelection` | 19 staff controller/helper sites (appointments, inventory×6, settings, clients, sales×2, gift cards, marketing, memberships×3, packages×2, payroll) | Org-scoped active list (F-30) | Global active list | Blocked (F-25) | Null-org waiver |
| `listAllBranchesForAdmin` | `BranchAdminController::index` | Org-scoped (F-32) | Global list | Blocked | Null-org waiver |
| `getBranchByIdForAdmin` | `BranchAdminController` (edit/update/destroy/store); internal `update`/`softDelete` | `id` + `organization_id` (F-32) | Id-only | Blocked | Null-org waiver |
| `createBranch` | `BranchAdminController::store` only | Context org; per-org `isCodeTaken`; no MIN fallback (F-34) | MIN org + global `isCodeTaken` | Blocked | Mutations: single entrypoint (F-33) |
| `updateBranch` | `BranchAdminController::update` only | Assert + org `isCodeTaken` + `UPDATE ... AND organization_id` (F-34) | Global `isCodeTaken` + id-only `UPDATE` | Blocked | Legacy null path |
| `softDeleteBranch` | `BranchAdminController::destroy` only | Assert + `UPDATE ... AND organization_id` (F-34) | Id-only soft delete | Blocked | Legacy null path |
| `isCodeTaken` | *(private)* `createBranch`, `updateBranch` | Per-org SQL | Global SQL | N/A | Intended split (F-34) |
| `defaultOrganizationIdForNewBranch` | *(private)* `createBranch` when context not >0 | **Not used** when resolved | `MIN(organizations.id)` | N/A | F-08 legacy |

---

## Branch admin routes × controller × permission

| Route | Action | Permission |
|-------|--------|------------|
| `GET /branches` | `index` | `branches.view` |
| `GET /branches/create` | `create` | `branches.manage` |
| `POST /branches` | `store` | `branches.manage` |
| `GET /branches/{id}/edit` | `edit` | `branches.manage` |
| `POST /branches/{id}` | `update` | `branches.manage` |
| `POST /branches/{id}/delete` | `destroy` | `branches.manage` |

**Source:** `system/routes/web/register_branches.php`

---

## Verdict row (mirror ops doc)

| Verdict | **B — Closed with documented waiver(s)** |
|---------|----------------------------------------|
| Next program | **Roadmap §5.C / §6** next prioritized item — **no** mandatory branch FOUNDATION wave |
