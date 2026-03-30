# BRANCH-WRITE-CALLER-MATRIX — FOUNDATION-33

Read-only matrix for **`BranchDirectory::createBranch` / `updateBranch` / `softDeleteBranch`** and related **private** helpers **`isCodeTaken`**, **`defaultOrganizationIdForNewBranch`**.

**Grep proof (in-repo `*.php`):** `->createBranch`, `->updateBranch`, `->softDeleteBranch` match **only** `BranchAdminController.php` (runtime). No tests or other PHP modules invoke these methods.

---

## 1. Runtime mutation callers

| # | File | Function / method | Mutation | Classification | Route (if HTTP) | Permission | Org context on entry | Unresolved-org (practical) | Single-org / F-25 note |
|---|------|-------------------|----------|----------------|-----------------|------------|----------------------|----------------------------|-------------------------|
| 1 | `system/modules/branches/controllers/BranchAdminController.php` | `store` | `createBranch` | **Organization-admin mutation surface** | `POST /branches` | `branches.manage` | Set by `OrganizationContextMiddleware` before `AuthMiddleware` | **Multi-org:** blocked by F-25 if null | Single-org: resolver sets org; zero-org: null, create throws at MIN-org |
| 2 | same | `update` | `updateBranch` | **Organization-admin mutation surface** | `POST /branches/{id}` | `branches.manage` | same | **Multi-org:** blocked if null | Degenerate null-org: F-32 load global id; assert no-op; UPDATE by id only |
| 3 | same | `destroy` | `softDeleteBranch` | **Organization-admin mutation surface** | `POST /branches/{id}/delete` | `branches.manage` | same | same | same |

**Internal-only (not HTTP entry):**

| # | File | Method | Role | Classification |
|---|------|--------|------|----------------|
| 4 | `system/core/Branch/BranchDirectory.php` | `createBranch` → `isCodeTaken` | Global code check before insert | N/A (implementation detail) |
| 5 | same | `updateBranch` → `isCodeTaken` | Global code check before update | N/A |
| 6 | same | `createBranch` → `defaultOrganizationIdForNewBranch` | Org id when context null | N/A |

---

## 2. Non-runtime / documentation references

| File | Role | Classification |
|------|------|----------------|
| `system/scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php` | Scans `updateBranch`/`softDeleteBranch` for assert needle; JSON **note** mentions `createBranch` policy | **Dead/unused as mutation caller** — audit tooling only |

---

## 3. Other `BranchDirectory` injectees (read-only for this task)

The following **inject** `BranchDirectory` but **do not** call create/update/soft-delete (confirmed by repository grep for `->createBranch` etc.): appointments, inventory (multiple), settings, clients, sales/register/invoices, gift cards, marketing, memberships, packages, payroll, `BranchContextMiddleware`. They are **out of scope** for this matrix except to prove **no hidden mutation paths**.

---

## 4. Summary counts

| Classification | Count (mutation entrypoints) |
|----------------|------------------------------|
| Organization-admin mutation surface | **3** (`store`, `update`, `destroy`) |
| Mixed / ambiguous | **0** |
| Dead / unused (runtime) | **0** |
| Tooling-only references | **1** script (no invocation) |
