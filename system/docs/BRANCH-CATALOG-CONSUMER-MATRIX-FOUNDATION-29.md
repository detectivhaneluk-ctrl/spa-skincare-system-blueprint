# Branch catalog — consumer matrix (FOUNDATION-29)

**Purpose:** Backend consumer map for **`BranchDirectory::getActiveBranchesForSelection()`**, plus admin-only **`listAllBranchesForAdmin`** / **`getBranchByIdForAdmin`**.  
**Evidence date:** 2026-03-23 tree.

**Legend — verdict:** **A** safe as-is · **B** audit-only waiver candidate · **C** needs minimal enforcement next (see primary ops doc).

---

## A. Shared selector: `getActiveBranchesForSelection()`

**Core implementation:** `system/core/Branch/BranchDirectory.php::getActiveBranchesForSelection` → global `SELECT` (no `organization_id` filter).

**Organization resolution (all rows):** `OrganizationContextMiddleware` → `OrganizationContextResolver::resolveForHttpRequest` (see `system/core/middleware/OrganizationContextMiddleware.php`, `system/core/Organization/OrganizationContextResolver.php`).

**Authenticated staff — unresolved org on multi-org:** `AuthMiddleware` → `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff` → **403** (exempt only `/logout` POST, `/account/password` GET/POST). **Therefore:** these consumers are **not reached** when multi-org + org unresolved, except exempt paths (which do not use this method per audit).

**List scope:** **Global** (all active branches, all orgs) for every consumer below unless/until implementation changes.

| Module family | Controller / helper | Callable surface(s) calling catalog | Route protection (typical) | Org required for HTTP entry? | How org is resolved | Result scope | Unresolved org (multi-org) | Cross-org leakage | Verdict |
|---------------|---------------------|-------------------------------------|---------------------------|------------------------------|---------------------|--------------|----------------------------|-------------------|---------|
| Appointments | `Modules\Appointments\Controllers\AppointmentController` | `getBranches()` → `getActiveBranchesForSelection()` used from `index`, `create`, `edit`, `dayCalendarPage`, `waitlistPage`, `waitlistCreate`, `renderCreateForm`, `renderEditForm` | `AuthMiddleware` + `PermissionMiddleware` (`appointments.*`) | Multi-org: yes (gate). Single-org: resolved via F-09 fallback. | Branch → org, or single-org fallback | Global active branches | Blocked (403) | Dropdown lists all orgs’ branches | **C** |
| Inventory | `Modules\Inventory\Controllers\ProductController` | `getBranches()` from `index`, `create`, `store` error paths, `edit`, `update` error paths, etc. | `AuthMiddleware` + `inventory.*` | Same as above | Same | Global | Blocked | Yes | **C** |
| Inventory | `Modules\Inventory\Controllers\ProductBrandController` | Direct calls in `create`, `store`, `edit`, `update`; `branchNameByIdForTaxonomyIndex()` foreach | `AuthMiddleware` + `inventory.*` | Same | Same | Global | Blocked | Yes (incl. index labels) | **C** |
| Inventory | `Modules\Inventory\Controllers\ProductCategoryController` | Same pattern as brands | `AuthMiddleware` + `inventory.*` | Same | Same | Global | Blocked | Yes | **C** |
| Inventory | `Modules\Inventory\Controllers\StockMovementController` | `getBranches()` | `AuthMiddleware` + `inventory.*` | Same | Same | Global | Blocked | Yes | **C** |
| Inventory | `Modules\Inventory\Controllers\InventoryCountController` | `getBranches()` | `AuthMiddleware` + `inventory.*` | Same | Same | Global | Blocked | Yes | **C** |
| Inventory | `Modules\Inventory\Controllers\SupplierController` | `getBranches()` | `AuthMiddleware` + `inventory.*` | Same | Same | Global | Blocked | Yes | **C** |
| Settings | `Modules\Settings\Controllers\SettingsController` | `index`, `store` via `Application::container()->get(BranchDirectory::class)` | `AuthMiddleware` + `settings.*` | Same | Same | Global | Blocked | Yes (normalization set) | **C** |
| Clients | `Modules\Clients\Controllers\ClientController` | `getBranches()` from `registrationsIndex`, `registrationsCreate`, `registrationsStore` catch, `registrationsShow` | `AuthMiddleware` + `clients.*` | Same | Same | Global | Blocked | Yes | **C** |
| Sales | `Modules\Sales\Controllers\InvoiceController` | `getBranches()` from `index`, `create`, `edit`, `renderCreateForm`, `renderEditForm` | `AuthMiddleware` + `sales.*` | Same | Same | Global | Blocked | Yes | **C** |
| Sales | `Modules\Sales\Controllers\RegisterController` | `getBranches()` (register UI) | `AuthMiddleware` + permission per routes file | Same | Same | Global | Blocked | Yes | **C** |
| Gift cards | `Modules\GiftCards\Controllers\GiftCardController` | `getBranches()` | `AuthMiddleware` + `gift_cards.*` | Same | Same | Global | Blocked | Yes | **C** |
| Marketing | `Modules\Marketing\Controllers\MarketingCampaignController` | `getBranches()` | `AuthMiddleware` + `marketing.*` | Same | Same | Global | Blocked | Yes (F-13 repo scope ≠ UI list) | **C** |
| Memberships | `Modules\Memberships\Controllers\ClientMembershipController` | `getBranches()` from `index` | `AuthMiddleware` + `memberships.*` | Same | Same | Global | Blocked | Yes | **C** |
| Memberships | `Modules\Memberships\Controllers\MembershipDefinitionController` | `getBranches()` | `AuthMiddleware` + `memberships.*` | Same | Same | Global | Blocked | Yes | **C** |
| Memberships | `Modules\Memberships\Controllers\MembershipRefundReviewController` | `getBranches()` | `AuthMiddleware` + `memberships.*` | Same | Same | Global | Blocked | Yes | **C** |
| Packages | `Modules\Packages\Controllers\ClientPackageController` | `getBranches()` | `AuthMiddleware` + `packages.*` | Same | Same | Global | Blocked | Yes | **C** |
| Packages | `Modules\Packages\Controllers\PackageDefinitionController` | `getBranches()` | `AuthMiddleware` + `packages.*` | Same | Same | Global | Blocked | Yes | **C** |
| Payroll | `Modules\Payroll\Controllers\PayrollRunController` | `getBranches()` | `AuthMiddleware` + `payroll.*` | Same | Same | Global | Blocked | Yes (F-14 repo scope ≠ UI list) | **C** |

**Not present in repo (module family):** **staff** — no `BranchDirectory` references under `system/modules/staff`. **services / resources** — no `BranchDirectory` in `register_services_resources.php` controllers.

**Related (not this method):** `ProductController::optionalActiveBranchIdFromRequestGet` uses **`isActiveBranchId`** — see primary doc §7.

---

## B. Branch admin catalog: `listAllBranchesForAdmin` / `getBranchByIdForAdmin`

| Module family | Controller | Method(s) | Route protection | Org required (HTTP) | How org resolved | Scope | Unresolved org (multi-org) | Cross-org leakage | Verdict |
|---------------|------------|-----------|------------------|---------------------|------------------|-------|----------------------------|-------------------|---------|
| Branches | `Modules\Branches\Controllers\BranchAdminController` | `index` → `listAllBranchesForAdmin`; `edit`/`store`/`update`/`destroy` → `getBranchByIdForAdmin` | `AuthMiddleware` + `branches.view` / `branches.manage` | Multi-org: gate requires resolved org for non-exempt staff | F-09 | **Global** (all orgs + soft-deleted in list) | Blocked | Admin sees **all** branches | **C** |

**Mutation note:** `update`/`destroy` call `BranchDirectory::updateBranch` / `softDeleteBranch` → **`OrganizationScopedBranchAssert`** when org resolved (`BranchDirectory.php`).

---

## C. Adjacent `BranchDirectory` reads (not selector/admin list)

| Symbol | Callers | Role |
|--------|---------|------|
| `isActiveBranchId` | `BranchContextMiddleware`; `ProductController` (optional GET branch hint); `ClientMembershipController` (validation path) | Active-row check, **not** multi-row catalog |
| `isCodeTaken` | `BranchDirectory::createBranch` / `updateBranch` | **Global** code uniqueness |

---

## D. Re-run command (completeness check)

```bash
rg "getActiveBranchesForSelection" system --glob "*.php"
```

Expect: `BranchDirectory.php` definition + only the consumer files listed in section A above.
