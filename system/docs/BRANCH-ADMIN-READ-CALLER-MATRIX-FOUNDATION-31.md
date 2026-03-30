# Branch-admin read surfaces — caller matrix (FOUNDATION-31)

**Symbols:** `BranchDirectory::listAllBranchesForAdmin()`, `BranchDirectory::getBranchByIdForAdmin(int $id)`.

---

## A. `listAllBranchesForAdmin()`

| # | Caller file | Function | Classification | Entry path | Auth / policy | Org context (typical) | Global read = leak? | Unresolved org (multi-org) |
|---|-------------|----------|----------------|------------|---------------|-------------------------|---------------------|----------------------------|
| 1 | `system/modules/branches/controllers/BranchAdminController.php` | `index` | **Mixed / ambiguous** (org-admin UX intent vs global data) | `GET /branches` | `AuthMiddleware` + `PermissionMiddleware` **`branches.view`** | Resolved after F-25 gate | **Yes** in multi-org if non-platform users hold `branches.view` | **Not reached** (403) |

**Count:** **1** PHP caller. **Dead/unused:** none.

---

## B. `getBranchByIdForAdmin(int $id)`

| # | Caller file | Function | Classification | Entry path | Auth / policy | Org context (typical) | Global read = leak? | Unresolved org (multi-org) |
|---|-------------|----------|----------------|------------|---------------|-------------------------|---------------------|----------------------------|
| 1 | `system/modules/branches/controllers/BranchAdminController.php` | `store` | **Organization-admin surface** (audit snapshot after create) | `POST /branches` | **`branches.manage`** | Resolved | Loads **new** row id (same org as `createBranch` in normal flows) | Not reached |
| 2 | same | `edit` | **Mixed / ambiguous** | `GET /branches/{id}/edit` | **`branches.manage`** | Resolved | **Yes** if `{id}` is another org’s branch (form disclosure before update assert) | Not reached |
| 3 | same | `update` | **Organization-admin surface** | `POST /branches/{id}` | **`branches.manage`** | Resolved | **Yes** for `before` load by id; mutate blocked by F-11 assert when out-of-org | Not reached |
| 4 | same | `destroy` | **Organization-admin surface** | `POST /branches/{id}/delete` | **`branches.manage`** | Resolved | **Yes** for `before` load by id; soft-delete blocked by assert when out-of-org | Not reached |
| 5 | `system/core/Branch/BranchDirectory.php` | `updateBranch` | **Internal mutation pipeline** (not a standalone HTTP read) | Invoked from `BranchAdminController::update` | N/A (caller already authenticated) | Same request | Id-only load; assert follows | N/A |
| 6 | `system/core/Branch/BranchDirectory.php` | `softDeleteBranch` | **Internal mutation pipeline** | Invoked from `BranchAdminController::destroy` | N/A | Same request | Id-only load; assert follows | N/A |

**Dead/unused:** none.

---

## C. Summary

| Symbol | Total PHP call sites (excl. definition) | HTTP-exposed | Internal only |
|--------|----------------------------------------|--------------|---------------|
| `listAllBranchesForAdmin` | 1 | 1 | 0 |
| `getBranchByIdForAdmin` | 6 | 4 (same controller) | 2 (`BranchDirectory`) |

---

## D. Single-org fallback note

When exactly **one** active organization exists, `OrganizationContext` is **resolved** for staff HTTP (F-09 fallback). **Global** admin SQL still returns **all** `branches` rows; leakage severity depends on whether multiple org-owned rows exist in DB. **Product-correct** only if data model ensures a single org’s branches or operators accept global listing in single-org mode.

---

## E. Re-run

```bash
rg "listAllBranchesForAdmin|getBranchByIdForAdmin" --glob "*.php"
```
