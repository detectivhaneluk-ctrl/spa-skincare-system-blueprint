# Organization unresolved behavior — surface matrix (FOUNDATION-21)

**Companion:** `ORGANIZATION-RESOLUTION-GAP-AND-UNRESOLVED-BEHAVIOR-TRUTH-AUDIT-FOUNDATION-21-OPS.md`  
**Legend — unresolved classification:**

- **Legacy global:** No org EXISTS / join; may return cross-org rows (subject to other filters).
- **Legacy ID-only / filter-only:** Same as pre–org-wave for that method’s WHERE clause.
- **Branch-limited not org-isolated:** Filters by branch (or branch OR NULL) but **no** org predicate when unresolved.
- **No-op assert:** `OrganizationScopedBranchAssert` returns without checking org.
- **Fail-closed (org):** When **resolved**, excludes out-of-org / NULL branch per design; when **unresolved**, **not** org-fail-closed unless noted.

---

## A) FOUNDATION-09 — `OrganizationContext` / resolver

| File / method | Depends on `resolvedOrganizationId()`? | Org **resolved** | Org **unresolved** | Unresolved class |
|---------------|----------------------------------------|------------------|--------------------|------------------|
| `OrganizationContextResolver::resolveForHttpRequest` | N/A (sets context) | Sets org id + mode | Sets org **null** + ambiguous / no-org mode | **Prerequisite** |
| `OrganizationContextMiddleware::handle` | N/A | Invokes resolver then `$next` | Same | Always runs on HTTP stack |

---

## B) FOUNDATION-11 — `OrganizationScopedBranchAssert` + choke services

| File / method | Uses org context? | Org **resolved** | Org **unresolved** | Unresolved class |
|---------------|-------------------|------------------|--------------------|------------------|
| `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` | `getCurrentOrganizationId()` | Validates branch row `organization_id` | **Early return** | **No-op assert** |
| `BranchDirectory::updateBranch` / `softDeleteBranch` | Calls assert | Assert enforces org match for `$id` | Assert **no-op** — update/delete proceed **without** org check | **Legacy global** for admin branch row |
| `BranchDirectory::createBranch` | `getCurrentOrganizationId()` | Pins `organization_id` to context | Uses `defaultOrganizationIdForNewBranch()` (**MIN** active org) | **Not** unresolved pin — **not** org-context isolation when multi-org (may pin to smallest id) |
| `InvoiceService` / `PaymentService` / `ClientService` (assert call sites) | Assert | Assert runs when branch id passed | Assert **no-op** | **No org check** from assert path |
| `MarketingCampaignService::createCampaign` (branch assert) | Assert on `branch_id` | Assert runs | Assert **no-op** | **No org check** from assert when unresolved |
| `PayrollService::createRun` / `PayrollRuleController::store` | Assert | Assert runs | Assert **no-op** | **No org check** from assert when unresolved |

---

## C) FOUNDATION-13 — Marketing repositories

| File / method | `resolvedOrganizationId()` branch? | Org **resolved** | Org **unresolved** | Unresolved class |
|---------------|-------------------------------------|------------------|--------------------|------------------|
| `MarketingCampaignRepository::find` | Fragment always appended | Org EXISTS on `mc.branch_id` | Empty fragment → **ID-only** | **Legacy ID-only** |
| `MarketingCampaignRepository::list` / `count` | Fragment + branch filter split | Strict `branch_id = ?` when filter set | `(branch_id = ? OR branch_id IS NULL)` + empty org fragment | **Branch-limited + NULL-branch rows; not org-isolated** |
| `MarketingCampaignRepository::update` | Fragment on WHERE | Org-scoped update | Empty fragment → update by id only | **Legacy ID-only** |
| `MarketingCampaignRunRepository::find` / `findForUpdate` / `listByCampaign` / `update` | Explicit `if null` | Join campaign + org clause | Raw SQL on run table (or id-only) | **Legacy global** (run/campaign id scope only) |
| `MarketingCampaignRecipientRepository::findForUpdate` / `listByRunWithOutbound` / `listPendingForRun` / `update` / `cancelAllPendingForRun` | Explicit `if null` | Join through campaign + org | Id/run-scoped **without** org join | **Legacy global** (run id scope) |

---

## D) FOUNDATION-14 — Payroll repositories

| File / method | `resolvedOrganizationId()` branch? | Org **resolved** | Org **unresolved** | Unresolved class |
|---------------|-------------------------------------|------------------|--------------------|------------------|
| `PayrollRunRepository::find` / `listForBranch` / `update` | Fragment | Org EXISTS on run `branch_id` | Empty fragment | **Legacy ID-only** / branch param only |
| `PayrollRunRepository::listRecent(null)` | Explicit | Org EXISTS, all in-org runs | `SELECT * FROM payroll_runs ORDER BY …` | **Legacy global** |
| `PayrollRunRepository::delete` | Fragment empty check | DELETE with org join | `DELETE FROM payroll_runs WHERE id = ?` | **Legacy ID-only** |
| `PayrollCompensationRuleRepository::find` / `update` | Fragment | Org-scoped | Empty fragment | **Legacy ID-only** |
| `PayrollCompensationRuleRepository::listActive` / `listAllForBranchFilter` | Branch filter + fragment | Strict branch + org on rule `branch_id` | `(branch_id IS NULL OR branch_id = ?)` + empty org fragment | **Branch + NULL global rules; not org-isolated** |
| `PayrollCommissionLineRepository::deleteByRunId` | Explicit | DELETE with join to run + org | `DELETE … WHERE payroll_run_id = ?` | **Legacy** (run id only) |
| `PayrollCommissionLineRepository::listByRunId` | Explicit | Join run + org | Select by `run_id` only | **Legacy** (run id only) |
| `PayrollCommissionLineRepository::allocatedSourceRefsExcludingRun` | `payrollRunBranchOrgExistsClause('pr')` appended | Org EXISTS on `pr.branch_id` | Empty fragment → join `payroll_runs` **without** org predicate | **Legacy global** (excluded-run + status filter only) |

---

## E) FOUNDATION-16 / F-18 — `ClientRepository`

| Method | Org **resolved** | Org **unresolved** | Unresolved class |
|--------|------------------|--------------------|------------------|
| `find` / `findForUpdate` | EXISTS on `c.branch_id`; NULL branch excluded | No EXISTS → **ID + deleted** only | **Legacy ID-only** |
| `list` / `count` | EXISTS + optional search/branch | No EXISTS → legacy list/count | **Legacy global** (within filters) |

---

## F) FOUNDATION-19 / F-20 — `ClientListProvider`

| Surface | Org **resolved** | Org **unresolved** | Notes |
|---------|------------------|--------------------|-------|
| All five controllers → `ClientListProviderImpl::list` | Inherits `ClientRepository::list` | Same as **E)** `list` | F-20 **waiver**: unresolved **not** claimed isolated |

---

## G) Summary counts (for question A)

| Category | Surfaces |
|----------|----------|
| **Explicit legacy branches** (`if resolvedOrganizationId() === null`) | Marketing run/recipient repos; payroll commission lines; payroll `listRecent` null branch |
| **Implicit legacy** (empty org fragment only) | Client repo; marketing campaign repo find/list/update; payroll run/rule find/update/delete; payroll allocation query |
| **Assert no-op** (choke “off”) | F-11 assert call sites when org null |
| **Special: `createBranch`** | Uses MIN org when context null — **neither** legacy read nor assert; **data pin** behavior |
