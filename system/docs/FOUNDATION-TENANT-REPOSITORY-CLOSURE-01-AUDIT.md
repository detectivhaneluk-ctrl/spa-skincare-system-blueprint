# FOUNDATION-TENANT-REPOSITORY-CLOSURE-01 — Tenant closure audit + Tier A wave

**Method:** Code-truth review of listed surfaces. **Tier A** = HTTP-visible or money-adjacent **writes** still id-only at repository layer. **Tier B** = id-only reads/locks that have safer alternates but broader call-graph churn. **Tier C** = already intrinsically scoped or documented intentional global/repair paths.

---

## 1. `system/modules/sales/repositories/InvoiceRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | `find` / `findForUpdate` / `list` / `count` / `update` / `softDelete` append `SalesTenantScope::invoiceClause` → `OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause` | — | Reads/writes: **scoped** | FND-TNT-06 addressed cashier client reads elsewhere. |

**Tier:** **C** (for this audit slice).

---

## 2. `system/modules/sales/repositories/PaymentRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | All listed methods use `paymentByInvoiceExistsClause` (invoice-plane EXISTS in org) | — | **Scoped** | `create()` is insert; invoice_id must be valid in service layer. |

**Tier:** **C** (for this audit slice).

---

## 3. `system/modules/clients/repositories/ClientRegistrationRequestRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | `find` / `list` / `count` / `update` use `clientRegistrationRequestTenantExistsClause` | — | **Scoped** | |

**Tier:** **C**.

---

## 4. `system/modules/clients/repositories/ClientIssueFlagRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | `find` / `listByClient` / `update` use `clientIssueFlagTenantJoinSql` | — | **Scoped** | |

**Tier:** **C**.

---

## 5. `system/modules/appointments/repositories/AppointmentRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | `find` / `list` / `count` / `update` / `softDelete` / `markCheckedIn` use `branchColumnOwnedByResolvedOrganizationExistsClause('a')` | — | **Scoped** | |

**Tier:** **C**.

---

## 6. `system/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | Correlation reads: `findCorrelatedToInvoiceRow`, `findForUpdateCorrelatedToInvoiceRow`, branch+invoice variants | — | **Scoped / correlated** | FND-TNT-05 |
| 2 | Token paths: `findByTokenHash`, `findForUpdateByTokenHash` | Public secret | **By design** (opaque token) | |
| 3 | **`update(int $id, …)`**, **`setFulfillmentReconcileRecovery`**, **`clearFulfillmentReconcileRecovery`** | Previously none | **Tier A — FIXED in wave 01** | Raw `WHERE id = ?` allowed cross-tenant row mutation if `purchase_id` leaked/guessed. **Closure:** `UPDATE public_commerce_purchases p … WHERE p.id = ?` + `branchColumnOwnedByResolvedOrganizationExistsClause('p','branch_id')`. |

**Closure target:** Intrinsic org-owned branch predicate on all mutating paths that accepted bare `id`.

**Acceptance proof:** `verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php` + manual grep of `UPDATE public_commerce_purchases`.

---

## 7. `system/modules/memberships/Repositories/MembershipSaleRepository.php`

| # | Repository enforcement | Caller-only | Id-only risk | Notes |
|---|------------------------|-------------|--------------|--------|
| 1 | `findInTenantScope`, `findForUpdateInTenantScope`, `listByInvoiceId`, `listRefundReviewInTenantScope`, reconcile lists | — | Safer paths exist | |
| 2 | **`find` / `findForUpdate`** | Was id-only | **Tier B — closed in CLOSURE-02 (FND-TNT-08)** | Org EXISTS on `ms.branch_id`; service uses `*InTenantScope` when invoice branch known. |
| 3 | **`update(int $id, …)`** | None | **Tier A — FIXED in wave 01** | Raw `WHERE id = ?`. **Closure:** `UPDATE membership_sales ms … WHERE ms.id = ?` + `branchColumnOwnedByResolvedOrganizationExistsClause('ms')`. |
| 4 | **`findBlockingOpenInitialSale`** | Client+def+branch only | **Tier B — closed in CLOSURE-02 (FND-TNT-08)** | Branch path: org EXISTS on **`ms`**; null-branch path: **invoice-plane** join + reconcile fragment. |
| 5 | **`listRefundReview`** | Was unscoped wide list | **Closed CLOSURE-03 (FND-TNT-09)** | **`resolvedOrganizationId()`** + org binding (`ms` branch or **`i.branch_id`**); empty without org. |

**Legitimacy:** Same tenant context contract as other branch-scoped writes (`membership_sales.branch_id` must belong to resolved org). Repair CLI that already lacked org context remains unable to use tenant invoice/sale paths (pre-existing); single-org legacy resolution still provides org id.

**Acceptance proof:** `verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php` (UPDATE) + `verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php` (read/lock/blocking) + `verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php` (refund-review lists).

---

## 8. Custom fields / merge / profile (clients)

Brief scan: merge jobs closed in PLT-TNT-01; custom-field repositories not enumerated as bare `update(id)` in this wave — **Tier B** for follow-up grep-driven closure.

---

## 9. Docs

- **`TENANT-SAFETY-INVENTORY-CHARTER-01.md`:** Still valid for methodology; invoice/payment row updated post-wave.
- **`ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`:** **Stale** vs current `OrganizationContext` / middleware (already noted in hardening charter).

---

## Tier summary (this pass)

| Tier | Items |
|------|--------|
| **A implemented (wave 01)** | `PublicCommercePurchaseRepository` scoped updates + recovery helpers; `MembershipSaleRepository::update` scoped |
| **B next** | ~~Membership sale Tier B reads~~ **done FND-TNT-08**; ~~`listRefundReview*` global~~ **done FND-TNT-09**; remaining F-12 definition `find`/`list` / billing-cycle id locks per matrix |
| **C** | Invoice, Payment, Client registration/issue flags, Appointment repositories (listed methods) |
