# ORGANIZATION CONTEXT — POST–REPOSITORY-SCOPE-DOCUMENTATION NEXT BACKEND PROGRAM SURFACE MATRIX (FOUNDATION-73)

**Companion:** `ORGANIZATION-CONTEXT-POST-REPOSITORY-SCOPE-DOCUMENTATION-NEXT-BACKEND-PROGRAM-SELECTION-TRUTH-AUDIT-FOUNDATION-73-OPS.md`

---

## A. HTTP pipeline (baseline — compatibility only)

| Stage | Component | Role |
|-------|-----------|------|
| Global middleware | `BranchContextMiddleware` | Sets `BranchContext` from session/user/request + `BranchDirectory::isActiveBranchId` |
| Global middleware | `OrganizationContextMiddleware` | Calls `OrganizationContextResolver::resolveForHttpRequest` |
| Route | `AuthMiddleware` | After auth: `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()` (**F-25**) |
| Errors | `HttpErrorHandler::handleException` | **F-68:** four **resolver** `DomainException` messages → **403**; others → generic path |

**Source:** ```20:25:system/core/router/Dispatcher.php```, ```21:27:system/core/middleware/OrganizationContextMiddleware.php```, ```51:51:system/core/middleware/AuthMiddleware.php```, ```49:67:system/core/errors/HttpErrorHandler.php```.

---

## B. Core organization surfaces (audit scope files)

| File | Function in org story |
|------|----------------------|
| `OrganizationContextResolver` | Canonical org fill; **F-57** / **F-62** throws (whitelisted in **F-68**) |
| `OrganizationContext` | Holds resolved org id + mode; **`assertBranchBelongsToCurrentOrganization`** (used from assert helper) |
| `StaffMultiOrgOrganizationResolutionGate` | **F-25** multi-org unresolved guard; **`countActiveOrganizations`**, exemptions |
| `OrganizationRepositoryScope` | SQL fragments from context — **F-70/F-71/F-72** closed documentation posture |
| `OrganizationScopedBranchAssert` | Branch row DB check + delegate to **`OrganizationContext::assertBranchBelongsToCurrentOrganization`** |
| `BranchContextMiddleware` | Branch selection policy (not selected for next implementation) |

---

## C. `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` — runtime call sites (grep, `*.php`)

| Call site file | Notes (high level) |
|----------------|--------------------|
| `Core\Branch\BranchDirectory` | `updateBranch` / `softDeleteBranch` paths (F-11 lineage) |
| `Modules\Clients\Services\ClientService` | Multiple mutating / branch-parameter paths |
| `Modules\Sales\Services\InvoiceService` | Branch-scoped invoice mutations |
| `Modules\Sales\Services\PaymentService` | Branch-scoped payment paths |
| `Modules\Marketing\Services\MarketingCampaignService` | Create campaign when org resolved (**F-13** pattern) |
| `Modules\Payroll\Services\PayrollService` | Org-resolved gate + assert |
| `Modules\Payroll\Controllers\PayrollRuleController` | Controller-level assert when org resolved |
| `system/scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php` | **Verifier** — not HTTP runtime |

**Indirect:** `OrganizationScopedBranchAssert` alone calls **`OrganizationContext::assertBranchBelongsToCurrentOrganization`** (no other PHP callers in tree).

---

## D. `DomainException` messages vs **F-68** whitelist

**F-68 whitelist (resolver only):** `HttpErrorHandler` ```79:86``` — **four** strings from **`OrganizationContextResolver`**.

**Assert helper throws (examples — not on whitelist):**

- `Branch not found.`
- `Branch has no organization assignment.`

**`OrganizationContext::assertBranchBelongsToCurrentOrganization`:**

- `Branch does not belong to the resolved organization.`

**Next program implication:** Read-only audit documents **propagation** to **`HttpErrorHandler`** generic tail vs **F-68** branch — **no** recommendation to expand whitelist inside **F-73**.

---

## E. Recommended next program vs rejected (summary)

| Choice | Next? |
|--------|-------|
| **Read-only assert-consumer audit** (§4 OPS) | **Yes** |
| F-25 behavior change | **No** |
| Assert hardening implementation | **No** (before inventory) |
| `OrganizationRepositoryScope` SQL parity | **No** |
| `BranchContextMiddleware` implementation | **No** |
| `HttpErrorHandler` expansion | **No** (without **F-68** reopen charter) |
| Resolver / membership edits | **No** |

---

## F. STOP

**FOUNDATION-74** not opened here.
