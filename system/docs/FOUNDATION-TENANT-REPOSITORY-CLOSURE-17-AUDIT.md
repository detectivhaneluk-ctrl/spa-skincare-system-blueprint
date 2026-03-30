# FOUNDATION-TENANT-REPOSITORY-CLOSURE-17 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-17** (PLT-TNT-01)  
**Wave id:** **FND-TNT-28** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-01** (primary): invoice **index total** (`count`) is a high-value aggregate over **`invoices`** keyed by caller filters; without an **explicit** named branch-derived entrypoint it is easier to treat as “just a query” vs **`find` / `findForUpdate`**.
- **ROOT-05** (primary): **`InvoiceController::index`** uses **`count`** then **`list`** with the same filters — **`count`** states the invoice-plane contract explicitly; **`list()`** parity: **FOUNDATION-TENANT-REPOSITORY-CLOSURE-18** / **FND-TNT-29**.
- **ROOT-04** (secondary): documentation that **`count`** must not depend on ambiguous empty-scope behavior; **`requireBranchDerivedOrganizationIdForInvoicePlane()`** + **`invoiceClause('i')`** align with **`allocateNextInvoiceNumber`** / **`find`** family (**`find` / `findForUpdate`** explicit entry: **CLOSURE-19** / **FND-TNT-30**).

## Classification

**Tenant-owned**, **canonically scoped** — branch-derived org + **`invoiceClause`** on **`i`**.

## Proof

- `system/scripts/read-only/verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- **`InvoiceRepository::list`** — closed **CLOSURE-18** (do not re-expand here).
- **`appendListFilters`** shape / client PII search semantics beyond **join elision** when **`c.*`** not used.
