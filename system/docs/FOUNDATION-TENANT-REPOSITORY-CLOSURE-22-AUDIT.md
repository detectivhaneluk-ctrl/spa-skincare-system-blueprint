# FOUNDATION-TENANT-REPOSITORY-CLOSURE-22 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-22** (PLT-TNT-01)  
**Wave id:** **CLOSURE-22** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-01** (primary): **`PaymentRepository::getCompletedTotalByInvoiceId`** invoice-keyed aggregate read had only **`paymentByInvoiceExistsClause`** — no **named** **`requireBranchDerivedOrganizationIdForInvoicePlane()`** at method entry (same implicit-contract class as pre-**CLOSURE-21** **`getByInvoiceId`**).
- **ROOT-05** (secondary): parity with **`getByInvoiceId` / `find`** explicit invoice-plane entry on the payment repository.

## Classification

**Tenant-owned**, **branch-derived org** + **`paymentByInvoiceExistsClause('p','si')`** — SQL aggregate shape unchanged (signed net **`CASE`** for refunds retained).

## Proof

- `system/scripts/read-only/verify_payment_repository_get_completed_total_by_invoice_id_invoice_plane_closure_22_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- **`existsCompletedByInvoiceAndReference`**, **`getCompletedRefundedTotalForParentPayment`**, **`hasCompletedRefundForInvoice`**, **`create`**, services/controllers.
