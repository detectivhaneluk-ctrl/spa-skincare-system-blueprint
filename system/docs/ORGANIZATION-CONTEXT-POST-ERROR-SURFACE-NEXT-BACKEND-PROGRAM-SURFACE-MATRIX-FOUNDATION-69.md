# ORGANIZATION CONTEXT — POST–ERROR-SURFACE NEXT BACKEND PROGRAM — SURFACE MATRIX (FOUNDATION-69)

Post–**FOUNDATION-68** selection audit (read-only). **Recommended next program:** **`ORGANIZATION-REPOSITORY-SCOPE-AND-DATA-PLANE-CONSUMER-PARITY-READ-ONLY-TRUTH-AUDIT`** (see OPS doc).

## A. Baseline closures (touch only as references)

| Artifact | Role | Edit in next program? |
|----------|------|----------------------|
| **`USER-ORGANIZATION-MEMBERSHIP-AND-RUNTIME-TRUTH-LANE-CONSOLIDATED-PROGRAM-CLOSURE-TRUTH-AUDIT-FOUNDATION-64-OPS.md`** | **F-64** lane closure + **W-64-*** | **No** |
| **`RESOLVER-ORGANIZATION-DOMAINEXCEPTION-HTTP-403-CLASSIFICATION-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-68-OPS.md`** | **F-68** error-surface closure + **W-68-*** | **No** |
| **`HttpErrorHandler.php`** | **F-67** four-message **403** classification | **No** |
| **`OrganizationContextResolver.php`** | HTTP org resolution + F-57/F-62 | **No** |
| **`StaffMultiOrgOrganizationResolutionGate.php`** | **F-25** multi-org post-auth gate | **No** |
| **`BranchContextMiddleware.php`**, **`OrganizationContextMiddleware.php`** | Pipeline branch then org | **No** |

## B. Core scope helper (in-matrix for next read-only program)

| File | Symbol | Null-org behavior (code) |
|------|--------|---------------------------|
| `OrganizationRepositoryScope.php` | `resolvedOrganizationId()` | Returns **null** when context id null/≤0 (```17:21:system/core/Organization/OrganizationRepositoryScope.php```) |
| Same | `branchColumnOwnedByResolvedOrganizationExistsClause` | **Empty** SQL when org unresolved (```32:35:system/core/Organization/OrganizationRepositoryScope.php```) |
| Same | `marketingCampaignBranchOrgExistsClause` | Delegates to branch-column clause (```53:55:system/core/Organization/OrganizationRepositoryScope.php```) |
| Same | `payrollRunBranchOrgExistsClause` / `payrollCompensationRuleBranchOrgExistsClause` | Same delegation (```63:75:system/core/Organization/OrganizationRepositoryScope.php```) |

## C. Direct `OrganizationRepositoryScope` repository consumers (grep inventory)

| Repository | Scope API used (from grep) |
|------------|---------------------------|
| `Modules\Clients\Repositories\ClientRepository` | `branchColumnOwnedByResolvedOrganizationExistsClause('c')` |
| `Modules\Marketing\Repositories\MarketingCampaignRepository` | `marketingCampaignBranchOrgExistsClause`, `resolvedOrganizationId` |
| `Modules\Marketing\Repositories\MarketingCampaignRunRepository` | `marketingCampaignBranchOrgExistsClause`, `resolvedOrganizationId` |
| `Modules\Marketing\Repositories\MarketingCampaignRecipientRepository` | `marketingCampaignBranchOrgExistsClause`, `resolvedOrganizationId` |
| `Modules\Payroll\Repositories\PayrollRunRepository` | `payrollRunBranchOrgExistsClause`, `resolvedOrganizationId` |
| `Modules\Payroll\Repositories\PayrollCommissionLineRepository` | `payrollRunBranchOrgExistsClause`, `resolvedOrganizationId` |
| `Modules\Payroll\Repositories\PayrollCompensationRuleRepository` | `payrollCompensationRuleBranchOrgExistsClause`, `resolvedOrganizationId` |

**DI registration:** `register_clients.php`, `register_marketing.php`, `register_payroll.php` wire **`OrganizationRepositoryScope`** into the above repositories.

## D. Parallel org-truth pattern (not using `OrganizationRepositoryScope`)

| File | Mechanism |
|------|-----------|
| `Core\Branch\BranchDirectory` | **`OrganizationContext::getCurrentOrganizationId()`** → conditional `organization_id = ?` vs global listings / lookups (```52:113:system/core/Branch/BranchDirectory.php```) |
| `Modules\Marketing\Services\MarketingCampaignService` | `OrganizationContext` + **`OrganizationScopedBranchAssert`** (service layer) |
| `Modules\Payroll\Services\PayrollService` | `OrganizationContext` + **`OrganizationScopedBranchAssert`** |
| `Modules\Payroll\Controllers\PayrollRuleController` | `OrganizationContext` check |

## E. `OrganizationScopedBranchAssert` vs **F-67** whitelist (why hardening waits)

| `DomainException` source | Example message | In **`HttpErrorHandler`** F-67 list? |
|---------------------------|-----------------|-------------------------------------|
| `OrganizationScopedBranchAssert` | `Branch not found.` | **No** |
| Same | `Branch has no organization assignment.` | **No** |
| `OrganizationContext::assertBranchBelongsToCurrentOrganization` | `Branch does not belong to the resolved organization.` | **No** |

**Implication:** Assert “hardening” that touches HTTP mapping **collides** with **F-68** closure unless a **new** named error-surface program reopens it.

## F. Recommended next program — allowed vs forbidden edits

| Action | Allowed in first phase? |
|--------|-------------------------|
| Read-only docs / matrices / verifier-style scripts **that do not change runtime** | **Yes** (when chartered) |
| Change **`OrganizationRepositoryScope`** SQL | **No** (not in read-only phase) |
| Change **F-25** / **resolver** / **`HttpErrorHandler`** / middleware | **No** |

**Cross-reference:** `ORGANIZATION-CONTEXT-POST-ERROR-SURFACE-NEXT-BACKEND-PROGRAM-SELECTION-TRUTH-AUDIT-FOUNDATION-69-OPS.md`.
