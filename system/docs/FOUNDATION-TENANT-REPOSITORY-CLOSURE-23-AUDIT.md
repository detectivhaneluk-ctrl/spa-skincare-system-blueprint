# FOUNDATION-TENANT-REPOSITORY-CLOSURE-23 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-23** (PLT-TNT-01)  
**Wave id:** **CLOSURE-23** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-05** (primary): `PaymentRepository` helper drift inside the same invoice-plane aggregate family — helper methods still relied on implicit `paymentByInvoiceExistsClause()` while sibling methods already required explicit `requireBranchDerivedOrganizationIdForInvoicePlane()`.
- **ROOT-01** (secondary): invoice/payment helper reads keyed by `invoice_id` / `parent_payment_id` / reference string still lacked a named fail-closed tenant entry at method boundary.

## Classification

**Tenant-owned**, **branch-derived org** + unchanged `paymentByInvoiceExistsClause('p','si')` SQL:

- `existsCompletedByInvoiceAndReference()`
- `getCompletedRefundedTotalForParentPayment()`
- `hasCompletedRefundForInvoice()`

All three now fail closed at method entry with `requireBranchDerivedOrganizationIdForInvoicePlane()`.

## Proof

- `system/scripts/read-only/verify_payment_repository_helper_invoice_plane_closure_23_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`)

## Out of scope (explicit)

- ROOT-04 helper split work (`OrUnscoped` / empty-fragment structural separation)
- controllers, services, public flows
- membership/package/inventory repositories
