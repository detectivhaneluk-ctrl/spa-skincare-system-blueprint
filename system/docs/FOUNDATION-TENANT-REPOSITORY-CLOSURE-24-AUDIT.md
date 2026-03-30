# FOUNDATION-TENANT-REPOSITORY-CLOSURE-24 — audit memo

**Task:** **FOUNDATION-TENANT-REPOSITORY-CLOSURE-24** (PLT-TNT-01)  
**Wave id:** **CLOSURE-24** (2026-03-30)

## Primary / secondary ROOT ids

- **ROOT-04** (primary): tenant-looking membership invoice-plane helper paths could still catch `AccessDeniedException` or delegate into empty/unscoped SQL behavior, making strict and repair/global behavior look identical at the call site.
- **ROOT-05** (secondary): mixed helper contracts in membership reconcile/read paths obscured whether a given method was fail-closed tenant scope or repair/global fallback.

## Classification

**Strict tenant helper family**

- `MembershipSaleRepository::strictTenantInvoicePlaneBranchScope()`
- `MembershipBillingCycleRepository::strictTenantInvoicePlaneBranchScope()`
- `MembershipBillingCycleRepository::findByMembershipAndPeriod()` now fails closed and no longer drops to raw id-only SQL

**Explicit repair/global helper family**

- `MembershipSaleRepository::resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable()`
- `MembershipBillingCycleRepository::resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable()`
- `OrganizationRepositoryScope::globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause()`

Legacy `OrganizationRepositoryScope::globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped()` no longer widens silently; it delegates to the strict global-admin helper instead of returning an empty fragment.

## Proof

- `system/scripts/read-only/verify_root_04_strict_repair_split_membership_invoice_plane_readonly_01.php`
- `system/scripts/read-only/verify_cross_module_invoice_payment_read_guard_readonly_01.php`
- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php`
- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php`
- Tier A gate: `system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`

## Out of scope (explicit)

- UI, controllers, and product feature work
- non-membership modules except compatibility behavior already present in `OrganizationRepositoryScope`
- ZIP packaging / checkpoint build
