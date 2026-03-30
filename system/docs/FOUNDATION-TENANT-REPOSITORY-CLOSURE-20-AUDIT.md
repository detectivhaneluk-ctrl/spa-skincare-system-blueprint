# FOUNDATION-TENANT-REPOSITORY-CLOSURE-20 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-20** (PLT-TNT-01)  
**Wave id:** **FND-TNT-31** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-01** (primary): **`PaymentRepository::find` / `findForUpdate`** keyed only by **`payments.id`** relied on **`paymentByInvoiceExistsClause`** (therefore **`invoiceClause`**) without a **named** **`requireBranchDerivedOrganizationIdForInvoicePlane()`** at method entry — same implicit-contract gap as pre-**CLOSURE-19** invoice id reads.
- **ROOT-05** (secondary): payment **lock/read** parity with **invoice-plane** explicit entry used on **`InvoiceRepository::find` / `findForUpdate`** and **`getCompletedCashTotalsByCurrencyForRegisterSession`**.

## Classification

**Tenant-owned**, **branch-derived org** + **`paymentByInvoiceExistsClause('p','si')`** — SQL shape unchanged.

## Proof

- `system/scripts/read-only/verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- **`PaymentRepository::getByInvoiceId`**, aggregates, **`existsCompletedByInvoiceAndReference`**, **`create`**, controllers, services.
- **`InvoiceRepository`** (already **CLOSURE-19**).
