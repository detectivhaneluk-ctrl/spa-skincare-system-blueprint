# ORGANIZATION `RepositoryScope` — CONSUMER ENFORCEMENT ASYMMETRY SURFACE MATRIX (FOUNDATION-72)

**Purpose:** Code-synchronized matrix for **maintainers** after **FOUNDATION-71** doc-only wave. **Canonical** deep-dive remains **FOUNDATION-70** OPS + consumer matrix (`@see` on each class).

**Companion:** `ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-ENFORCEMENT-ASYMMETRY-DOCUMENTATION-ONLY-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-72-OPS.md`

---

## A. `OrganizationRepositoryScope` (helper surface)

| Member | Role |
|--------|------|
| `resolvedOrganizationId(): ?int` | Positive org id or `null` (unset / non-positive). |
| `branchColumnOwnedByResolvedOrganizationExistsClause($alias, $branchCol)` | Org resolved → `IS NOT NULL` + EXISTS to `branches`/`organizations`; org unresolved → **empty** SQL/params (**legacy-global** if concatenated alone). |
| `marketingCampaignBranchOrgExistsClause` | Delegates branch EXISTS on `marketing_campaigns.branch_id`. |
| `payrollRunBranchOrgExistsClause` | Delegates on `payroll_runs.branch_id`. |
| `payrollCompensationRuleBranchOrgExistsClause` | Delegates on nullable `payroll_compensation_rules.branch_id` → NULL globals **excluded** when fragment active. |

---

## B. `ClientRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `find` | **Yes** — `branchColumnOwnedByResolvedOrganizationExistsClause('c')` | |
| `findForUpdate` | **Yes** | |
| `list` | **Yes** | |
| `count` | **Yes** | |
| `lockActiveByEmailBranch` | **No** | Branch id parameter; caller/context expected. |
| `lockActiveByPhoneDigitsBranch` | **No** | Same. |
| `findActiveClientIdByPhoneDigitsExcluding` | **No** | Same. |
| `create` / `update` / `softDelete` / `restore` | **No** | **update** doc: id-only mutation; caller relies on prior scoped reads or correct ids. |
| `findDuplicates` / `searchDuplicates` | **No** | Cross-row search; no org fragment. |
| `countLinkedRecords` / `remapClientReferences` / `markMerged` | **No** | Id / table keyed. |
| `listNotes` / `createNote` / `findNote` / `softDeleteNote` / `listAuditHistory` | **No** | Client id keyed; caller enforcement. |

**Not universal org safety:** many public entry points are **unscoped** at this layer.

---

## C. `MarketingCampaignRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `find` | **Yes** — `marketingCampaignBranchOrgExistsClause` | |
| `list` | **Yes** + `resolvedOrganizationId()` affects **branch_id** filter predicate | Resolved → `mc.branch_id = ?`; unresolved → `(mc.branch_id = ? OR mc.branch_id IS NULL)`. |
| `count` | **Yes** (same branch filter split) | |
| `update` | **Yes** | |
| `insert` | **No** | Doc: callers/services enforce branch when org resolves (**F-70** service gate narrative). |

---

## D. `MarketingCampaignRunRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `find` / `findForUpdate` | **Conditional** | `resolvedOrganizationId() === null` → **id-only** (legacy); else join `marketing_campaigns` + EXISTS fragment. |
| `listByCampaign` | **Conditional** | Same split on `campaign_id`. |
| `update` | **Conditional** | Unresolved → id-only `UPDATE`; resolved → join + fragment. |
| `insert` | **No** | Always unscoped at repository level. |

---

## E. `MarketingCampaignRecipientRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `insertBatch` | **No** | Never applies scope. |
| `findForUpdate` | **Conditional** | Unresolved → id-only `FOR UPDATE`; resolved → joins runs + campaigns + fragment. Doc: use after parent run/campaign validated. |
| `listByRunWithOutbound` / `listPendingForRun` / `update` / `cancelAllPendingForRun` | **Conditional** | Same null-org vs join-scoped pattern. |

---

## F. `PayrollRunRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `find` / `listForBranch` / `update` | **Yes** — `payrollRunBranchOrgExistsClause` (empty when org unresolved) | |
| `listRecent` | **Mixed** | With `branch_id` → delegates `listForBranch` (fragment appended). With `branch_id === null` and org **resolved** → org-scoped list. With `branch_id === null` and org **unresolved** → **global** `SELECT * FROM payroll_runs` (legacy operators). |
| `create` | **No** | |
| `delete` | **Yes** with fallback | Empty fragment → **id-only** `DELETE`; else `DELETE pr ...` + fragment. |

---

## G. `PayrollCommissionLineRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `deleteByRunId` | **Conditional** | Unresolved → unscoped delete by `payroll_run_id`; resolved → join `payroll_runs` + fragment. |
| `listByRunId` | **Conditional** | Same. |
| `allocatedSourceRefsExcludingRun` | **Fragment always appended** | When org unresolved, fragment **empty** → no org EXISTS; still joins `payroll_runs` for status filter. |
| `insert` | **No** | |

---

## H. `PayrollCompensationRuleRepository`

| Public method | Repository-level org scope | Notes |
|---------------|----------------------------|--------|
| `find` / `update` | **Yes** — `payrollCompensationRuleBranchOrgExistsClause` | |
| `listActive` / `listAllForBranchFilter` | **Yes** + branch filter split | Resolved org → `pcr.branch_id = ?`; unresolved → `(pcr.branch_id IS NULL OR pcr.branch_id = ?)` when branch filter set. |
| `create` | **No** | |

---

## I. `@see` (F-70) consistency

All **seven** repositories plus **`OrganizationRepositoryScope`** reference:

- `system/docs/ORGANIZATION-REPOSITORY-SCOPE-AND-DATA-PLANE-CONSUMER-PARITY-READ-ONLY-TRUTH-AUDIT-FOUNDATION-70-OPS.md`
- `system/docs/ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-MATRIX-FOUNDATION-70.md`

Files exist under `system/docs/`.

---

## J. Explicit residual risk (post–F-71)

Repository-layer **enforcement is asymmetric**; **documentation** is the delivered control for this wave. **Hardening** (extra predicates on id-only mutators, CLI/cron paths, etc.) remains **out of scope** for F-71/F-72 and requires a **named** follow-up program.
