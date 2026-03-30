# FOUNDATION-TENANT-REPOSITORY-CLOSURE-19 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-19** (PLT-TNT-01)  
**Wave id:** **FND-TNT-30** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-01** (primary): **`InvoiceRepository::find` / `findForUpdate`** relied on **`invoiceClause('i')`** alone; the **named** **`requireBranchDerivedOrganizationIdForInvoicePlane()`** entry used on **`list`**, **`count`**, and **`allocateNextInvoiceNumber`** was not visible at method entry (implicit contract only).
- **ROOT-05** (secondary): single-record invoice-plane reads/locks should match the **canonical** invoice-plane entry contract for auditability and static proof.

## Classification

**Tenant-owned**, **branch-derived org** + **`invoiceClause('i')`** — query shape unchanged (existing **`LEFT JOIN clients`** on **`find`** only).

## Proof

- `system/scripts/read-only/verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- **`InvoiceRepository::findForPublicCommerceCorrelatedBranch`** (alternate contract; unchanged).
- **`PaymentRepository`**, **`InvoiceRepository::update` / `softDelete`** (separate hotspots).
- Broad query refactors or UI.
