# FOUNDATION-TENANT-REPOSITORY-CLOSURE-11 — audit

**Scope:** Public client resolution **phone** path parity with **email** lock. **Wave ID:** **FND-TNT-17** (tenant repository closure series). **Date:** 2026-03-29.

**Root families:** **ROOT-03** (anonymous public vs staff scope), **ROOT-05** (read/lock basis aligned with **`lockActiveByEmailBranch`**), **ROOT-01** (ambiguous branch-only lock/read surfaces).

## Risk addressed

**`lockActiveByPhoneDigitsBranch`** and **`findActiveClientIdByPhoneDigitsExcluding`** used **`branch_id <=> ?`** only — no live **branch/org** **`EXISTS`**, no **`$branchId <= 0`** guard — inconsistent with **`lockActiveByEmailBranch`** and vulnerable to dangling **`branch_id`** FKs.

## Closure

- Both methods: **`$branchId <= 0`** → empty array / **`null`**; alias **`c`**; append **`publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c')`** on normalized and legacy SQL paths (same as email).

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (unchanged)

- **`AppointmentRepository`** and other client non-public surfaces; **`PublicClientResolutionService`** logic beyond repository contract.
