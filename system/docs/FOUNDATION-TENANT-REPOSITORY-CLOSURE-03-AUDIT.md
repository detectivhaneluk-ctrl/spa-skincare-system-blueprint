# FOUNDATION-TENANT-REPOSITORY-CLOSURE-03 — Membership refund-review + adjacent catalog (lane audit)

**Scope:** Backlog after CLOSURE-02: `listRefundReview` / `listRefundReviewQueue` global risk, small F-12-adjacent membership catalog read used from sales/cashier. **Not repeated:** FND-TNT-07/08 surfaces, `PublicCommercePurchaseRepository` mutations, `MembershipSaleRepository::update` / id read/lock closure.

---

## 1. `MembershipSaleRepository::listRefundReview`

| # | Before | After (wave) | Proof |
|---|--------|----------------|-------|
| 1 | `SELECT * … WHERE status = refund_review` with optional branch / `global` null-branch only — **cross-tenant** when unfiltered | `resolvedOrganizationId() === null` → **[]**; else `LEFT JOIN invoices i` + `(ms.branch_id NOT NULL + branch-in-org EXISTS) OR (ms.branch_id NULL + invoice_id + i.branch_id in org)` | `verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php` |

**Intentional:** No deployment-global union when org unset (fail-closed). Repair/cron that needed unscoped behavior must use a different explicit entry (none in-repo today).

---

## 2. `MembershipBillingCycleRepository::listRefundReviewQueue`

| # | Before | After (wave) | Proof |
|---|--------|----------------|-------|
| 1 | Same pattern: optional `cm.branch_id` filter only — **cross-tenant** when wide | Same org binding on `cm.branch_id` or `i.branch_id`; **[]** without resolved org id | FND-TNT-09 verifier |

---

## 3. `MembershipDefinitionRepository::listActiveForInvoiceBranch`

| # | Before | After (wave) | Proof |
|---|--------|----------------|-------|
| 1 | `branch_id = ?` only — wrong-org branch id could surface definitions | `md.branch_id = ?` + `branchColumnOwnedByResolvedOrganizationExistsClause('md')` (throws when tenant org context missing, same as other data-plane fragments) | FND-TNT-09 verifier |

---

## 4. Deferred (documented; not this wave)

| Surface | Reason |
|---------|--------|
| `MembershipDefinitionRepository::find` / `list` / `count` | **Closed in CLOSURE-04 (FND-TNT-10)** — see `FOUNDATION-TENANT-REPOSITORY-CLOSURE-04-AUDIT.md` |
| `MembershipBillingCycleRepository::find` / `findForUpdate` id-only | **Closed in CLOSURE-04** — invoice-plane JOIN + `findForInvoice` / `findForUpdateForInvoice` |
| `MembershipService` / `MembershipBillingService` `definitions->find($id)` | **Closed in CLOSURE-04** — `findForClientMembershipContext` / `findInTenantScope` / `findBranchOwnedInResolvedOrganization` |
