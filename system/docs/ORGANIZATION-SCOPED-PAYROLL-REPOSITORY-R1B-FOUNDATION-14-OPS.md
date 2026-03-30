# Organization-scoped payroll repository R1B (FOUNDATION-14)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-14 — ORGANIZATION-SCOPED-REPOSITORY-MINIMAL-ENFORCEMENT-R1B-PAYROLL  
**Source of truth:** FOUNDATION-06 through FOUNDATION-13; extends **`OrganizationRepositoryScope`** (shared `branchColumnOwnedByResolvedOrganizationExistsClause` + payroll entry points).  
**Scope:** Payroll repository family only — **no** invoices, payments, clients, appointments, public flows, memberships, packages, settings, RBAC, UI.

---

## What was added

| Piece | Role |
|--------|------|
| `OrganizationRepositoryScope` | Internal **`branchColumnOwnedByResolvedOrganizationExistsClause`**; **`marketingCampaignBranchOrgExistsClause`** delegates to it (F-13 behavior unchanged). New **`payrollRunBranchOrgExistsClause`**, **`payrollCompensationRuleBranchOrgExistsClause`**. |
| `PayrollRunRepository` | `find`, `listForBranch`, `listRecent` (org-wide when branch filter null + org resolved), `update`, `delete` append payroll run org predicate when context resolved. |
| `PayrollCompensationRuleRepository` | `find`, `listActive`, `listAllForBranchFilter`, `update` — when org resolved, **`branch_id IS NULL` “global” rules are excluded** (fail-closed); with branch filter, **`branch_id = ?` only** (no `OR branch_id IS NULL`). |
| `PayrollCommissionLineRepository` | `deleteByRunId`, `listByRunId`, `allocatedSourceRefsExcludingRun` join `payroll_runs` and apply run org clause when resolved. |
| `PayrollService::createRun` | **`OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization`** when org resolved. |
| `PayrollRuleController::store` | When org resolved: non-null **`branch_id`** required + same assert before **`rules->create`**. |
| `register_payroll.php` | DI for repositories, `PayrollService`, `PayrollRuleController`. |

---

## Public flow check

**Proved:** `system/routes/web/register_payroll.php` — all paths **`AuthMiddleware`** + **`payroll.view`** / **`payroll.manage`**. No guest payroll surface.

---

## INSERT paths (intentionally not WHERE-scoped in repo)

| Method | Enforcement |
|--------|-------------|
| `PayrollRunRepository::create` | **`PayrollService::createRun`** branch assert when org resolved. |
| `PayrollCompensationRuleRepository::create` | **`PayrollRuleController::store`** branch required + assert when org resolved. |
| `PayrollCommissionLineRepository::insert` | Only after **`runs->find`** / calculate path in **`PayrollService`** (scoped run). |

---

## Runtime behavior

- **Org unresolved:** Prior SQL semantics preserved (including `(branch_id IS NULL OR branch_id = ?)` on compensation rule lists when a branch filter is set; global payroll run list when branch filter null).
- **Org resolved:** Runs and commission lines tied to runs outside the org do not load/update/delete. Rules with **`branch_id` NULL** do not appear and cannot be loaded/updated by id; creating a rule requires an in-org branch.

---

## Proof commands (read-only)

From `system/`:

```bash
php scripts/verify_organization_context_resolution_readonly.php
php scripts/verify_organization_branch_ownership_readonly.php
php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php
php scripts/verify_marketing_repository_org_scope_foundation_13_readonly.php
php scripts/verify_payroll_repository_org_scope_foundation_14_readonly.php
```

---

## Non-goals (this wave)

FOUNDATION-15, non-payroll repositories, UI, schema.

---

## Checkpoint ZIP

Exclude `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip`.
