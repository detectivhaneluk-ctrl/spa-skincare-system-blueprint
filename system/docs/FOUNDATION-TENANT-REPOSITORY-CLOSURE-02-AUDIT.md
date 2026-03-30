# FOUNDATION-TENANT-REPOSITORY-CLOSURE-02 — Tier B audit (membership_sales read/lock)

**Scope:** Refresh only for `MembershipSaleRepository` Tier B items from CLOSURE-01 backlog. **Not repeated:** FND-TNT-07 (`PublicCommercePurchaseRepository` update; `MembershipSaleRepository::update`).

---

## `system/modules/memberships/Repositories/MembershipSaleRepository.php`

| Method | Intrinsic scope (after wave) | Caller-only (before) | Closure target | Proof |
|--------|------------------------------|----------------------|----------------|-------|
| `find(int $id)` | `WHERE ms.id = ?` + `branchColumnOwnedByResolvedOrganizationExistsClause('ms')` | Raw `SELECT * … WHERE id = ?` | Same org plane as `update()` | `verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php` |
| `findForUpdate(int $id)` | Same + `FOR UPDATE` | Raw id-only lock | Tenant-owned row before lock | same |
| `findBlockingOpenInitialSale` (branch > 0) | `ms.branch_id = ?` + org EXISTS on `ms` | Client+def+branch only | Block duplicate issuance only in resolved org | same |
| `findBlockingOpenInitialSale` (branch null) | `INNER JOIN invoices i` + `invoicePlaneExistsClauseForMembershipReconcileQueries('i')` | `branch_id IS NULL` only | Legacy null-branch: invoice-plane tenant or repair OrUnscoped | same; **note:** rows with `branch_id` NULL and no `invoice_id` no longer participate in this probe |

---

## `system/modules/memberships/Services/MembershipSaleService.php`

| Path | Change | Proof |
|------|--------|-------|
| `operatorReevaluateRefundReviewSale` | Requires `branchId`; uses `findForUpdateInTenantScope` / `findInTenantScope` | Verifier + `MembershipRefundReviewService` passes tenant branch |
| `settleSingleSaleLocked` / `settleSaleForDeletedInvoice` | When `invoice.branch_id > 0`, `findForUpdateInTenantScope` | Verifier |

---

## Deferred (not this wave)

- **`listRefundReview` / `listRefundReviewQueue`** — **closed in CLOSURE-03 (FND-TNT-09)**; tenant HTTP still prefers `*InTenantScope` for single-branch pin.
- **F-12 catalog / other membership-linked repos** — partial in CLOSURE-03 (`listActiveForInvoiceBranch`); remaining `MembershipDefinitionRepository::find` / `list` / unscoped billing `find` — future wave.
