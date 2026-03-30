# FOUNDATION-TENANT-REPOSITORY-CLOSURE-15 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-15** (PLT-TNT-01)  
**Wave id:** **FND-TNT-26** (2026-03-30) — label chosen to avoid collision with inventory Tier A **FND-TNT-22**–**25** verifier naming.

## Primary / secondary ROOT ids

- **ROOT-05** (primary): sequence bump used **`assertProtectedTenantContextResolved()`** (any non-null org id) while invoice read/mutate paths use **`invoiceClause()`** → **branch-derived** org only — read/allocate basis drift.
- **ROOT-04** (secondary): without an explicit contract, ambiguous org-resolution modes could be treated as sufficient for **tenant-owned** counter mutation; allocator now **fails closed** unless **`OrganizationContext::MODE_BRANCH_DERIVED`**. Legacy **`invoice_number_sequences`** row **`organization_id = 0`** remains **out of allocator path** (documented; not widened).

## Classification

**Tenant-owned**, **canonically scoped**: per-**organization** sequence rows; org id taken only after **`requireBranchDerivedOrganizationIdForDataPlane()`** / **`requireBranchDerivedOrganizationIdForInvoicePlane()`** (branch-derived). **Not** GlobalOps.

## Proof

- `system/scripts/read-only/verify_invoice_number_sequence_hotspot_readonly_01.php` (Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (explicit)

- Schema/partitioning / hot-row scale (**FND-PERF-03**).
- Broad sales/invoice repository sweep beyond this allocator.
