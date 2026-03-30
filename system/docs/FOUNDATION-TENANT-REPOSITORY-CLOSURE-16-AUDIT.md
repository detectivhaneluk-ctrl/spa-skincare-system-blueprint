# FOUNDATION-TENANT-REPOSITORY-CLOSURE-16 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-16** (PLT-TNT-01)  
**Wave id:** **FND-TNT-27** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-01** (primary): **`register_session_id`**-only aggregate over **`payments`** without an intrinsic **register session row** predicate in SQL (caller-trusted id).
- **ROOT-05** (primary): **`RegisterSessionService::closeSession`** locked the session via **`RegisterSessionRepository::findForUpdate`** ( **`registerSessionClause`** ) while the cash aggregate used only **`paymentByInvoiceExistsClause`** — read/aggregate basis could diverge from the register-session plane.
- **ROOT-04** (secondary): when **`invoiceClause`** / tenant fragments are **empty** (non-branch-derived org), **`paymentByInvoiceExistsClause`** returns an empty fragment — risk of **unscoped** aggregate without an explicit fail-closed guard.

## Classification

**Tenant-owned**, **canonically scoped**: branch-derived org required; **`register_sessions`** row joined and **`registerSessionClause('rs')`** applied; payments remain tied to tenant invoices via **`paymentByInvoiceExistsClause`**. **Not** GlobalOps.

## Proof

- `system/scripts/read-only/verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- Other **`PaymentRepository`** methods (**`find`**, **`getByInvoiceId`**, …) and **`InvoiceRepository`** list surfaces — one hotspot only.
