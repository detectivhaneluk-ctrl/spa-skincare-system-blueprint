# FOUNDATION-TENANT-REPOSITORY-CLOSURE-13 — audit

**Scope:** **`AppointmentRepository::hasRoomConflict`** — overlap scan tenant basis. **Wave ID:** **FND-TNT-19**. **Date:** 2026-03-29.

**Root families:** **ROOT-01** (unscoped **`appointments`** overlap read), **ROOT-05** (align with **`find`/`list`/`update`** + **`lockRoomRowForConflictCheck`**); **ROOT-02** (**`$branchId === null`** arm unchanged — explicit legacy **`a.branch_id IS NULL`** only, **no** org **`EXISTS`**).

## Risk addressed

Branch-scoped room conflict used **`appointments`** rows with **`branch_id = ?`** only — **no** **`OrganizationRepositoryScope`** proof, so the scan was not on the same canonical tenant plane as **`find()`** / **`list()`**.

## Closure

- When **`$branchId !== null`:** query uses alias **`a`**, **`a.branch_id = ?`**, and **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`** (branch-derived org required; **`AccessDeniedException`** when unresolved).
- When **`$branchId === null`:** unchanged contract — **`a.branch_id IS NULL`** only (documented legacy/repair slice).

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_19_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (unchanged at wave 13)

- **`hasStaffConflict`** — closed **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-14`** (**FND-TNT-21**). Availability buffer logic, public booking room policy unchanged.
