# Branch-admin read surfaces — truth and decision audit (FOUNDATION-31)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-31 — BRANCH-ADMIN-READ-SURFACES-ORG-SCOPE-TRUTH-AND-DECISION-AUDIT  
**Mode:** Read-only audit (no runtime/schema/UI change).  
**Companion matrix:** `BRANCH-ADMIN-READ-CALLER-MATRIX-FOUNDATION-31.md`  
**Accepted prior work:** FOUNDATION-06 through FOUNDATION-30 are **not** re-litigated unless contradicted below. **FOUNDATION-30** org-scopes **`getActiveBranchesForSelection()`** only; **`listAllBranchesForAdmin()`** / **`getBranchByIdForAdmin()`** remain **global** by design of that wave.

---

## 1. Methods audited (exact definitions)

**File:** `system/core/Branch/BranchDirectory.php`

| Method | Definition (evidence) |
|--------|------------------------|
| `listAllBranchesForAdmin(): array` | `SELECT id, name, code, deleted_at FROM branches ORDER BY name` — **no** `organization_id` filter; includes **soft-deleted** rows. |
| `getBranchByIdForAdmin(int $id): ?array` | `SELECT id, name, code, deleted_at FROM branches WHERE id = ?` — **id-only**; **no** org filter; includes soft-deleted row if present. |

---

## 2. In-repo PHP callers (complete)

Verified with repository search: `listAllBranchesForAdmin` and `getBranchByIdForAdmin` in `*.php` (2026-03-23 tree). **No** script-only or test-only callers beyond the core class and branch admin controller.

| Method | Caller | Role |
|--------|--------|------|
| `listAllBranchesForAdmin` | `Modules\Branches\Controllers\BranchAdminController::index` | HTTP admin index |
| `getBranchByIdForAdmin` | `BranchAdminController::store` (post-create audit snapshot) | HTTP |
| `getBranchByIdForAdmin` | `BranchAdminController::edit` | HTTP |
| `getBranchByIdForAdmin` | `BranchAdminController::update` (before + after audit) | HTTP |
| `getBranchByIdForAdmin` | `BranchAdminController::destroy` (before audit) | HTTP |
| `getBranchByIdForAdmin` | `BranchDirectory::updateBranch` (load before mutate) | Internal mutation |
| `getBranchByIdForAdmin` | `BranchDirectory::softDeleteBranch` (load before mutate) | Internal mutation |

**Dead/unused:** **None** identified for either symbol.

---

## 3. Route, auth, and organization context (HTTP callers)

**Routes:** `system/routes/web/register_branches.php`

| Action | Path | Middleware | Permission |
|--------|------|------------|------------|
| `index` | `GET /branches` | `AuthMiddleware`, `PermissionMiddleware::for('branches.view')` | `branches.view` |
| `create` | `GET /branches/create` | `AuthMiddleware`, `PermissionMiddleware::for('branches.manage')` | `branches.manage` |
| `store` | `POST /branches` | same | `branches.manage` |
| `edit` | `GET /branches/{id}/edit` | same | `branches.manage` |
| `update` | `POST /branches/{id}` | same | `branches.manage` |
| `destroy` | `POST /branches/{id}/delete` | same | `branches.manage` |

**Super-admin / platform operator:** **No** separate permission key, role flag, or route guard in this file. Access is **only** `branches.view` / `branches.manage` (plus authenticated session). Historical note: roadmap **FOUNDATION-01** / migration **084** backfilled `branches.*` alongside other settings-area perms — **not** proof of super-admin exclusivity in code.

**Organization context on these requests:** Global pipeline runs `OrganizationContextMiddleware` → `OrganizationContextResolver::resolveForHttpRequest` (see `system/core/router/Dispatcher.php`). For **multi-org** deployments, **`StaffMultiOrgOrganizationResolutionGate`** (post-`AuthMiddleware`) blocks **unresolved** org → **403** on non-exempt paths; **`/branches`** is **not** exempt. Therefore, for typical multi-org staff sessions that reach `/branches`, **`OrganizationContext::getCurrentOrganizationId()` is non-null** in practice.

**Single-org fallback (exactly one active organization, no current branch):** Resolver sets org id (`MODE_SINGLE_ACTIVE_ORG_FALLBACK`) — context **is** resolved for HTTP.

**Degenerate `count ≤ 1` active orgs including zero orgs:** Gate does **not** block (`StaffMultiOrgOrganizationResolutionGate` returns early when `countActiveOrganizations() <= 1`). Org context may be **unresolved** (e.g. ambiguous path not applicable at zero orgs — resolver sets no-org mode). **`/branches` can still be reached** if the user has `branches.view` / `branches.manage` — **global** admin reads still apply.

---

## 4. Fit assessment: product-correct vs tenancy leak

| Surface | Current behavior | Assessment |
|---------|------------------|------------|
| **`listAllBranchesForAdmin` → index** | Lists **every** branch row in DB (all orgs, soft-deleted included) to anyone with **`branches.view`**. | In **multi-org** data, this is a **cross-tenant catalog read** (names, codes, deletion state) unless product **guarantees** only platform staff hold `branches.view`. Code does **not** enforce that guarantee. |
| **`getBranchByIdForAdmin` → edit** | Loads any branch by numeric id for **`branches.manage`**. | **Metadata disclosure** for arbitrary org’s branch if id is known or enumerated. **`updateBranch` / `softDeleteBranch`** then **`OrganizationScopedBranchAssert`** reject mutate when org resolved and branch is out-of-org — but **read** (form render) already occurred. |
| **`getBranchByIdForAdmin` → audit payloads** | `before`/`after` snapshots in audit log. | Same global id semantics; persistence of **cross-org** row snapshots if such ids are used. |
| **Internal `updateBranch` / `softDeleteBranch`** | Id-only load then assert. | Not an end-user “admin index,” but **same global id read** primitive. |

**Single-org deployment:** Global list ≈ org’s branches if **all** `branches` rows share one `organization_id` (data invariant). Code still does **not** assert that invariant on read.

---

## 5. Options A / B / C (code-truth evaluation)

| Option | Meaning | Code truth |
|--------|---------|------------|
| **A** | Keep global reads; document **super-admin-only** | **Documentation-only.** Runtime still allows **any** user with `branches.view` / `branches.manage`. **False assurance** unless RBAC/product **outside** these methods restricts who receives those permissions. |
| **B** | Org-scope admin reads under **resolved** organization | Aligns with **FOUNDATION-30** selector contract; closes **proven** cross-org read for normal multi-org staff **without** new permission keys. **Null-org** legacy global can mirror F-30 (preserve until separate policy). |
| **C** | Split: explicit **global** capability vs **org-scoped** admin | **Most faithful** if product needs **both** cross-org operators and org-local admins under different entitlements. Requires **new** permission (or equivalent) and product rules — **larger** than a single minimal patch to `BranchDirectory` only. |

---

## 6. Recommended product decision (choose one — required)

**Recommendation: B — org-scope branch-admin reads under resolved organization** (implemented in a **future** wave, not here).

**Justification:**

1. **No code-level “super-admin only”** exists on `/branches`; **A** does not remove leakage by itself.  
2. **FOUNDATION-30** already committed the product direction that **resolved org** narrows branch catalog for staff UX; leaving admin list/id **global** creates **inconsistent** tenancy boundaries between **selectors** and **`/branches`**.  
3. **C** is appropriate **only** if stakeholders confirm a **first-class cross-org branch operator** role; that is **not** expressed in current route permissions — defer **C** until that requirement is explicit.

---

## 7. Safest next wave name (single recommendation only)

**FOUNDATION-32 — BRANCH-ADMIN-READ-SURFACES-ORG-SCOPE-MINIMAL-IMPLEMENTATION-R1**

**Scope sketch (for planning only):** apply the same **resolved-org filters** pattern as F-30 to **`listAllBranchesForAdmin`** and **`getBranchByIdForAdmin`** (and ensure internal mutation loads remain consistent — e.g. id load + assert, or org-scoped id load). Preserve **null-org** legacy global if F-30 parity is required until a separate fail-closed policy wave.

---

## 8. Contradiction check vs FOUNDATION-30

**None.** F-30 explicitly left **`listAllBranchesForAdmin` / `getBranchByIdForAdmin`** unchanged; this audit states current global behavior and callers — consistent with shipped F-30.

---

## 9. Verification

- `rg "listAllBranchesForAdmin|getBranchByIdForAdmin" --glob "*.php"` under repo root.
