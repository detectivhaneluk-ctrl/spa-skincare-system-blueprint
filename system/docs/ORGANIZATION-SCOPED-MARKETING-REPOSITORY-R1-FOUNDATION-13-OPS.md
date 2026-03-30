# Organization-scoped marketing repository R1 (FOUNDATION-13)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-13 — ORGANIZATION-SCOPED-REPOSITORY-MINIMAL-ENFORCEMENT-R1-MARKETING  
**Source of truth:** FOUNDATION-06 through FOUNDATION-12 (R1 perimeter = marketing repositories only).  
**Scope:** First repository-level org predicate; **no** payroll, invoices, payments, clients, appointments, public flows, memberships, packages, settings overhaul, RBAC, UI.

---

## What was added

| Piece | Role |
|--------|------|
| `Core\Organization\OrganizationRepositoryScope` | When `OrganizationContext::getCurrentOrganizationId()` is non-null, supplies `marketingCampaignBranchOrgExistsClause($alias)` — `branch_id IS NOT NULL` + `EXISTS` on `branches` + active `organizations`. Empty fragment when org unresolved. |
| `MarketingCampaignRepository` | Injects scope. `find`, `list`, `count`, `update` append org clause when resolved. `list`/`count`: when org resolved, branch filter is **strict** (`branch_id = ?` only — no `OR branch_id IS NULL`). |
| `MarketingCampaignRunRepository` | `find`, `findForUpdate`, `listByCampaign`, `update` join `marketing_campaigns` and apply org clause when resolved; legacy SQL when unresolved. |
| `MarketingCampaignRecipientRepository` | `findForUpdate`, `listByRunWithOutbound`, `listPendingForRun`, `update`, `cancelAllPendingForRun` join run → campaign and apply org clause when resolved. **`insertBatch`** unchanged (see below). |
| `MarketingCampaignService` | When org resolved: `createCampaign` requires non-null `branch_id` and `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization`. `dispatchOneRecipient` uses `MarketingCampaignRecipientRepository::findForUpdate` instead of raw SQL. |
| `system/bootstrap.php` | Registers `OrganizationRepositoryScope` singleton. |
| `register_marketing.php` | Repositories + service constructor wiring. |

---

## Public / semi-public check

All marketing routes are **`AuthMiddleware` + `marketing.view` / `marketing.manage`** (`system/routes/web/register_marketing.php`). **No** public marketing endpoints found — safe to scope without guest-flow changes.

---

## INSERT / batch paths (intentionally not SQL-scoped in repo)

| Method | Reason |
|--------|--------|
| `MarketingCampaignRepository::insert` | No `WHERE`; org enforced in **`MarketingCampaignService::createCampaign`** when org context is resolved (branch required + F-11 assert). |
| `MarketingCampaignRunRepository::insert` | Only after scoped `campaigns->find` in **`freezeRecipientSnapshot`**. |
| `MarketingCampaignRecipientRepository::insertBatch` | Only after validated campaign in the same transactional flow; no direct public caller. |

---

## Runtime behavior

- **Org resolved:** ID loads/updates/lists for campaigns/runs/recipients only hit rows whose campaign **`branch_id`** points at a **non-deleted** branch whose **`organization_id`** matches context. **`branch_id IS NULL`** campaigns are **invisible** in this mode (fail-closed for ambiguous global rows).
- **Org unresolved:** Marketing repositories behave as before (including `(branch_id = ? OR branch_id IS NULL)` in list/count when a branch filter is present).
- **Single-org deployments:** Org id resolves; staff must use branched campaigns for them to appear when context is resolved.

---

## Proof commands (read-only)

From `system/`:

```bash
php scripts/verify_organization_context_resolution_readonly.php
php scripts/verify_organization_branch_ownership_readonly.php
php scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php
php scripts/verify_marketing_repository_org_scope_foundation_13_readonly.php
```

---

## Non-goals (this wave)

- Payroll R1, any non-marketing repository, FOUNDATION-14, UI, schema migrations.

---

## Checkpoint ZIP

Exclude `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip`.
