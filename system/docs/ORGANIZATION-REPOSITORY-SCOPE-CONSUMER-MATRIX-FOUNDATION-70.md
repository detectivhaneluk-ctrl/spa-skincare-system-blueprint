# ORGANIZATION `RepositoryScope` — CONSUMER MATRIX (FOUNDATION-70)

Read-only inventory. **Cross-reference:** `ORGANIZATION-REPOSITORY-SCOPE-AND-DATA-PLANE-CONSUMER-PARITY-READ-ONLY-TRUTH-AUDIT-FOUNDATION-70-OPS.md`.

## Legend

| Tag | Meaning |
|-----|---------|
| **always+append** | Helper invoked every time; fragment appended (may be **empty** when org null). |
| **conditional** | **Different** SQL path when `resolvedOrganizationId() === null` vs resolved. |
| **bypass** | Helper **not** used for this method (legacy global or other keying). |

---

## `ClientRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `find` | **always+append** | `branchColumnOwnedByResolvedOrganizationExistsClause('c')` (```20:26```) |
| `findForUpdate` | **always+append** | Same alias `c` (```33:35```) |
| `lockActiveByEmailBranch` | **always+append** | `publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c')` + `branch_id <=> ?`; positive `branch_id` only; **no** resolved-org context (public contract) |
| `lockActiveByPhoneDigitsBranch` | **always+append** | Same anonymous-public fragment as email lock: `publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c')` + positive `branch_id` |
| `findActiveClientIdByPhoneDigitsExcluding` | **always+append** | Same |
| `list` | **always+append** | `c` + filters (```134:149```) |
| `count` | **always+append** | Same (```159:172```) |
| `create`, `update`, `softDelete`, `restore` | **bypass** | ID-based DML; **no** org fragment |
| `findDuplicates`, `searchDuplicates` | **bypass** | Global `clients` queries |
| `countLinkedRecords`, `remapClientReferences`, `markMerged` | **bypass** | Related tables by `client_id` |
| `listNotes`, `createNote`, `findNote`, `softDeleteNote`, `listAuditHistory` | **bypass** | Notes/audit by id / `client_id` |

---

## `MarketingCampaignRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `find` | **always+append** | `marketingCampaignBranchOrgExistsClause('mc')` (```20:22```) |
| `list` | **always+append** + branch filter split | If org resolved + `branch_id` filter: **`mc.branch_id = ?`**. If org **not** resolved + filter: **`(mc.branch_id = ? OR mc.branch_id IS NULL)`** (```37:45```). Then always append marketing fragment (```51:53```). |
| `count` | Same as `list` | ```66:82``` |
| `insert` | **bypass** | Raw insert (```91:96```); service may assert branch |
| `update` | **always+append** | `marketingCampaignBranchOrgExistsClause('mc')` on UPDATE WHERE (```112:116```) |

---

## `MarketingCampaignRunRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `find` | **conditional** | Org null: unscoped `marketing_campaign_runs` by id (```20:21```). Org resolved: JOIN `marketing_campaigns c` + `marketingCampaignBranchOrgExistsClause('c')` (```23:27```). |
| `findForUpdate` | **conditional** | Same pattern + `FOR UPDATE` (```34:41```) |
| `listByCampaign` | **conditional** | Same (```52:64```) |
| `insert` | **bypass** | (```70:75```) |
| `update` | **conditional** | Org null: unscoped UPDATE by `id` (```85:95```). Org resolved: JOIN + SET + fragment (```97:109```) |

---

## `MarketingCampaignRecipientRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `insertBatch` | **bypass** | Loop insert (```21:26```) |
| `findForUpdate` | **conditional** | Org null: unscoped (```34:38```). Org resolved: JOIN runs + campaigns + fragment on `c` (```40:47```). |
| `listByRunWithOutbound` | **conditional** | (```56:80```) |
| `listPendingForRun` | **conditional** | (```88:104```) |
| `update` | **conditional** | (```115:140```) |
| `cancelAllPendingForRun` | **conditional** | (```145:162```) |

---

## `PayrollRunRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `find` | **always+append** | `payrollRunBranchOrgExistsClause('pr')` (```20:22```) |
| `listForBranch` | **always+append** | `WHERE pr.branch_id = ?` + fragment (```34:37```) |
| `listRecent` | **conditional** | If `branchId !== null` → `listForBranch`. Else if org resolved → fragment only (```54:60```). Else **global** `SELECT * FROM payroll_runs …` (```63:66```). |
| `create` | **bypass** | (```69:74```) |
| `update` | **always+append** | (```82:92```) |
| `delete` | **conditional** | If fragment empty: `DELETE FROM payroll_runs WHERE id = ?` (```98:101```). Else `DELETE pr FROM payroll_runs pr WHERE pr.id = ?` + fragment (```103:104```). |

---

## `PayrollCommissionLineRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `deleteByRunId` | **conditional** | Org null: unscoped delete by `payroll_run_id` (```20:23```). Org resolved: JOIN `payroll_runs pr` + `payrollRunBranchOrgExistsClause('pr')` (```25:29```). |
| `insert` | **bypass** | (```35:40```) |
| `listByRunId` | **conditional** | (```47:66```) |
| `allocatedSourceRefsExcludingRun` | **always+append** | `payrollRunBranchOrgExistsClause('pr')` appended (```74:80```); when org null fragment **empty** → **no** org filter on allocation scan |

---

## `PayrollCompensationRuleRepository`

| Method | Scope pattern | Fragment / notes |
|--------|---------------|------------------|
| `find` | **always+append** | `payrollCompensationRuleBranchOrgExistsClause('pcr')` (```20:22```) |
| `listActive` | **always+append** + branch filter split | If `branchId !== null` and org resolved: `pcr.branch_id = ?`. If org **not** resolved: **`(pcr.branch_id IS NULL OR pcr.branch_id = ?)`** (```37:44```). Then fragment (```46:48```). |
| `listAllForBranchFilter` | Same pattern | ```65:77``` |
| `create` | **bypass** | (```85:90```) |
| `update` | **always+append** | (```98:108```) |

---

## `BranchDirectory` (reference — does not inject `OrganizationRepositoryScope`)

Uses **`OrganizationContext::getCurrentOrganizationId()`** only. See OPS §6 for parity vs EXISTS-based scope.

---

## Downstream DI (scoped data dependence)

| Service / controller | Depends on scoped repos / context |
|----------------------|-----------------------------------|
| `MarketingCampaignService` | Repos + **`OrganizationContext`** + **`OrganizationScopedBranchAssert`** — **`createCampaign`** requires branch when org resolved (see OPS §5.1). |
| `ClientService` | **`ClientRepository`** + **`OrganizationScopedBranchAssert`** (no direct `OrganizationRepositoryScope`). |
| `PayrollService` / `PayrollRuleController` | Payroll repos + **`OrganizationContext`** + assert (per `register_payroll.php`). |

**Wiring:** `register_clients.php`, `register_marketing.php`, `register_payroll.php` (lines cited in OPS §3).
