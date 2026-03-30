# FOUNDATION-TENANT-REPOSITORY-CLOSURE-21 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-21** (PLT-TNT-01)  
**Wave id:** **FND-TNT-32** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-01** (primary): **`PaymentRepository::getByInvoiceId`** invoice-keyed list read had only **`paymentByInvoiceExistsClause`** — no **named** **`requireBranchDerivedOrganizationIdForInvoicePlane()`** at method entry (same implicit-contract class as pre-**CLOSURE-20** **`find`**).
- **ROOT-05** (secondary): parity with **`find` / `findForUpdate`** explicit invoice-plane entry on the payment repository.

## Classification

**Tenant-owned**, **branch-derived org** + **`paymentByInvoiceExistsClause('p','si')`** — SQL shape unchanged (**`ORDER BY p.created_at`** retained).

## Proof

- `system/scripts/read-only/verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- **`existsCompletedByInvoiceAndReference`**, **`getCompletedRefundedTotalForParentPayment`**, **`hasCompletedRefundForInvoice`**, **`create`**, services/controllers. (**`getCompletedTotalByInvoiceId`**: **CLOSURE-22**.)
