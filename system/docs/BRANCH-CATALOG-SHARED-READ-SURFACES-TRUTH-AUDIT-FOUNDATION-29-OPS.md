# Branch catalog — shared read surfaces — truth audit (FOUNDATION-29)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-29 — BRANCH-CATALOG-AND-SHARED-BRANCH-READ-SURFACES-TRUTH-AUDIT  
**Mode:** Read-only audit (no runtime/schema/UI change).  
**Companion matrix:** `BRANCH-CATALOG-CONSUMER-MATRIX-FOUNDATION-29.md`  
**Prior foundations:** FOUNDATION-06 through FOUNDATION-28 are treated as accepted unless this tree contradicts them.

---

## 1. Methods audited (exact)

**File:** `system/core/Branch/BranchDirectory.php`

| Method | SQL / behavior (evidence) |
|--------|---------------------------|
| `getActiveBranchesForSelection(): array` | `SELECT id, name, code FROM branches WHERE deleted_at IS NULL ORDER BY name` — **no** `organization_id` predicate; **no** read of `OrganizationContext`. |
| `listAllBranchesForAdmin(): array` | `SELECT id, name, code, deleted_at FROM branches ORDER BY name` — **global** all rows (all orgs, includes soft-deleted). |
| `getBranchByIdForAdmin(int $id): ?array` | `SELECT id, name, code, deleted_at FROM branches WHERE id = ?` — **id-only**; **no** org predicate. |
| `isActiveBranchId(int $branchId): bool` | `SELECT 1 ... WHERE id = ? AND deleted_at IS NULL` — **no** org predicate (used for branch validity, not org isolation). |
| `isCodeTaken` (private, used by create/update) | `SELECT id FROM branches WHERE code = ?` — **global** code uniqueness across all org rows. |

**Internal callers inside `BranchDirectory`:** `updateBranch` / `softDeleteBranch` call `getBranchByIdForAdmin` then `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` (mutate path only; **read/list methods above do not**).

---

## 2. Organization context — how it is resolved (HTTP)

**Global middleware order** (`system/core/router/Dispatcher.php`): `BranchContextMiddleware` → `OrganizationContextMiddleware`.

**Resolver** (`system/core/Organization/OrganizationContextResolver.php::resolveForHttpRequest`):

- If `BranchContext` has a current branch id → org = `branches.organization_id` joined to active `organizations` (`MODE_BRANCH_DERIVED`).
- If no branch → if **0** active orgs → unresolved no-org; if **>1** active orgs → unresolved ambiguous; if **exactly 1** active org → that org id (`MODE_SINGLE_ACTIVE_ORG_FALLBACK`).

**Documented contract** (`system/core/Organization/OrganizationContext.php` docblock): branch-derived org must reference an active organization; single-org fallback when branch null; otherwise null (fail closed).

---

## 3. Staff multi-org gate (post–FOUNDATION-25 / aligned with FOUNDATION-28)

**`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff`** (`system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php`) runs at end of **`AuthMiddleware`** (`system/core/middleware/AuthMiddleware.php`) **after** successful authentication.

- If active organization **count ≤ 1** → gate **does nothing** (single-org or zero-org degenerate).
- If **multi-org** and `OrganizationContext::getCurrentOrganizationId()` is null → **403** (JSON if `Accept: application/json`, else plain text).
- Exempt: `POST /logout`, `GET`/`POST /account/password` only.

**Implication for shared branch read surfaces:** On **multi-org** deployments, **authenticated staff routes that use `AuthMiddleware`** cannot reach controllers that call `getActiveBranchesForSelection()` when org context is **unresolved** (except the exempt paths, which do not render branch selectors in this audit’s consumer set).

**Still reachable without resolved org (documented elsewhere; repeated for audit completeness):** zero-active-org degenerate (`count ≤ 1` includes **0** → gate skipped); guest/unauthenticated pipelines (no `AuthMiddleware`); CLI (no HTTP middleware — **no** in-repo script callers of `getActiveBranchesForSelection` were found).

---

## 4. Surface classification

### 4.1 Branch-management admin surfaces

- **`BranchAdminController`** (`system/modules/branches/controllers/BranchAdminController.php`) + routes `system/routes/web/register_branches.php`.
- Uses **`listAllBranchesForAdmin`** (`index`), **`getBranchByIdForAdmin`** (`edit` / `store` audit snapshots / `update` / `destroy` pre-checks), **`createBranch` / `updateBranch` / `softDeleteBranch`** (mutations).
- **Middleware:** `AuthMiddleware` + `PermissionMiddleware` (`branches.view` / `branches.manage`).
- **List scope:** **Global** (all organizations, includes soft-deleted in list).
- **Org resolution:** Same as any staff route; **not** used to filter admin list or id-only admin load.
- **Mutation enforcement:** `updateBranch` / `softDeleteBranch` use **`OrganizationScopedBranchAssert`** when org resolved; assert is **no-op** when org unresolved (`system/core/Organization/OrganizationScopedBranchAssert.php`).

### 4.2 Shared selector / lookup surfaces (canonical operational list)

- All HTTP consumers of **`BranchDirectory::getActiveBranchesForSelection()`** identified in-repo are **staff module controllers** behind **`AuthMiddleware` + `PermissionMiddleware`** (per-route). **No** anonymous route calls this method were found (`rg` over `system/**/*.php`, 2026-03-23 tree).
- **List scope:** **Global** — every call returns **all** non-deleted branches in the table, **not** filtered by `OrganizationContext`.
- **Cross-organization leakage (read/UI):** In a **multi-org** database, any staff user who can open a module that renders the dropdown **sees branch id/name/code for every organization**, regardless of resolved org. This is **metadata disclosure**; downstream **writes** may still be blocked or constrained by other layers (F-11 choke points, F-13 marketing repos, F-14 payroll repos, F-16/F-18 client repo, `BranchContext::assertBranchMatch`, etc.), but the **catalog read itself is not org-scoped**.

### 4.3 Public / anonymous surfaces (related branch reads, not `BranchDirectory` selectors)

These **do not** call `getActiveBranchesForSelection` / `listAllBranchesForAdmin` / `getBranchByIdForAdmin` but **do** read `branches` for validation or IDs:

- **`PublicBookingService::validateBranch`** — `system/modules/online-booking/services/PublicBookingService.php` — single branch by id + `deleted_at IS NULL`.
- **`PublicCommerceService`** — `system/modules/public-commerce/services/PublicCommerceService.php` — branch id existence check.
- **`IntakeFormService`** — `system/modules/intake/services/IntakeFormService.php` — branch id existence check.
- **`AppointmentSeriesService`** — `system/modules/appointments/services/AppointmentSeriesService.php` — branch id check.

**Scope:** Per-request **id validation**, not a **shared multi-row catalog** for staff UI. **Organization context** on public flows may remain unresolved under F-09; these paths are **out of scope** for the staff shared selector map but noted for boundary completeness.

---

## 5. Module family coverage (mandatory list)

| Family | Uses shared `getActiveBranchesForSelection`? | Notes |
|--------|-----------------------------------------------|--------|
| **branches** | Admin methods only (`listAllBranchesForAdmin`, `getBranchByIdForAdmin`) | See §4.1. |
| **staff** | **No** `BranchDirectory` usage in `system/modules/staff` (search: no matches) | Staff UI does not use this catalog for lists in-repo. |
| **clients** | **Yes** — `ClientController` (registration flows) | See matrix. |
| **appointments / calendar** | **Yes** — `AppointmentController` | Includes list/create/edit/calendar/waitlist/render helpers; **not** `dayCalendar` JSON (no branch list in audited snippet). |
| **services / resources** | **No** direct `BranchDirectory` in `register_services_resources.php` or service controllers | Branch context used via services/repos; no shared catalog method. |
| **sales / payments / commerce** | **Yes** — `InvoiceController`, `RegisterController` | Staff commerce; public commerce uses separate service (§4.3). |
| **inventory** | **Yes** — `ProductController`, `ProductBrandController`, `ProductCategoryController`, `StockMovementController`, `InventoryCountController`, `SupplierController` | Taxonomy/index helpers also iterate selection list for name maps. |
| **marketing** | **Yes** — `MarketingCampaignController` | **Notable:** F-13 org-scoped **repositories** vs **global** branch dropdown — UI can still offer other orgs’ branch ids until server-side validation rejects (campaign service asserts branch when org resolved). |
| **settings** | **Yes** — `SettingsController::index` / `store` | Drives online-booking context branch normalization against **global** active list. |
| **memberships** | **Yes** — `ClientMembershipController`, `MembershipDefinitionController`, `MembershipRefundReviewController` | |
| **payroll** | **Yes** — `PayrollRunController` | F-14 repo scope vs global dropdown — same pattern as marketing. |
| **gift cards** | **Yes** — `GiftCardController` | |
| **packages** | **Yes** — `ClientPackageController`, `PackageDefinitionController` | |

---

## 6. Contradiction / risk list (evidence-based)

1. **Roadmap / product narrative vs code:** `BOOKER-PARITY-MASTER-ROADMAP.md` §5.C describes `getActiveBranchesForSelection` as canonical for staff selectors after branch admin work; it does **not** claim org scoping — **no contradiction**. Prior **`ORGANIZATION-SCOPED-CHOKE-POINTS-TRUTH-AUDIT-FOUNDATION-10-OPS.md`** stated BranchAdmin id-only loads without org check on update/delete — **superseded** for **mutations** by F-11 (`updateBranch`/`softDeleteBranch` assert). This audit **does not** re-open F-11 mutate truth.
2. **Multi-tenant isolation gap:** `getActiveBranchesForSelection` and `listAllBranchesForAdmin` are **global** reads — **cross-org branch metadata exposure** to any staff who can hit those UIs.
3. **`OrganizationScopedBranchAssert` on unresolved org:** F-11 assert **does not run** when `getCurrentOrganizationId()` is null — combined with **single-org / zero-org** gate behavior, mutating paths may lack org assertion in degenerate cases; **read surfaces** remain global regardless.
4. **Global branch `code` uniqueness:** `isCodeTaken` does not scope by `organization_id` — organizational tenancy for **codes** is not modeled as per-org unique in code.
5. **Settings online booking context:** `SettingsController` normalizes posted branch ids against **global** `getActiveBranchesForSelection()` — a staff user could **see and post** ids outside resolved org; whether persistence is safe depends on settings service (out of scope for this branch-catalog audit beyond listing the consumer).

---

## 7. Conclusion per surface type

| Surface | Verdict | Rationale |
|---------|---------|-----------|
| **`getActiveBranchesForSelection`** (all module consumers) | **C — needs minimal enforcement next** | Operational selectors are **global**; multi-org deployments leak branch catalog across org boundary at read time. |
| **`listAllBranchesForAdmin` / `getBranchByIdForAdmin` (admin UI)** | **C — needs minimal enforcement next** (or deliberate product split) | **Global** list and id load; admin UX likely must either stay **super-admin global** (explicit) or be **org-scoped** to match tenancy — current code is **implicit global**. |
| **`isActiveBranchId` (validity checks)** | **B — audit-only waiver candidate** | Existence/active check is not a “catalog surface”; changing it may belong to a **broader branch-id policy** wave rather than selector scoping alone. |
| **Public id validators** (§4.3) | **A — safe as-is** for *this* audit’s objective | They are not shared multi-row org catalog surfaces; separate public-flow audits apply. |

---

## 8. Single recommended next wave (required decision)

**One wave only:** **Minimal org-scoped branch catalog reads for staff operational selectors** — implement filtering of **`BranchDirectory::getActiveBranchesForSelection()`** by **`OrganizationContext::getCurrentOrganizationId()`** when non-null (SQL `WHERE deleted_at IS NULL AND organization_id = ?`), with explicit product decision for **`listAllBranchesForAdmin` / `getBranchByIdForAdmin`** (either align to resolved org or document and enforce a dedicated **global super-admin** permission). **Do not** split into multiple parallel waves: treat admin catalog + shared selector alignment as **one** coordinated enforcement/design closure.

**Rationale:** This is the narrowest fix that addresses the **largest proven cross-org leakage** (§6.2) shared across **all** module families in the matrix; other items (`isCodeTaken` global uniqueness, settings normalization) are **dependent product/schema** decisions, not a second wave in this recommendation.

---

## 9. Verification method

- Repository search: `getActiveBranchesForSelection`, `listAllBranchesForAdmin`, `getBranchByIdForAdmin`, `BranchDirectory` across `system/**/*.php`.
- **Completeness claim:** All **PHP** call sites of `getActiveBranchesForSelection` in this workspace are listed in **`BRANCH-CATALOG-CONSUMER-MATRIX-FOUNDATION-29.md`** (excluding the method definition line). Re-run `rg` after future edits.
