# FOUNDATION-TENANT-REPOSITORY-CLOSURE-18 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-18** (PLT-TNT-01)  
**Wave id:** **FND-TNT-29** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-05** (primary): **`InvoiceRepository::list`** lacked the same **explicit** branch-derived invoice-plane entry and **`clients`** join policy as **`count()`** after **CLOSURE-17**.
- **ROOT-01** (secondary): invoice **index read** path relied on **`invoiceClause`** alone without the **named** **`requireBranchDerivedOrganizationIdForInvoicePlane()`** contract used elsewhere on the invoice plane.
- **ROOT-04** (secondary): removes ambiguity that **list** was a “lighter” tenant path than **count** for the same filters.

## Classification

**Tenant-owned**, **canonically scoped** — branch-derived org + **`invoiceClause('i')`** + shared **`invoiceListRequiresClientsJoinForFilters`**.

## Proof

- `system/scripts/read-only/verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- **`appendListFilters`** predicate semantics (unchanged).
- **`InvoiceRepository::find` / `findForUpdate`** — **CLOSURE-19** / **FND-TNT-30**; **`PaymentRepository`**.
