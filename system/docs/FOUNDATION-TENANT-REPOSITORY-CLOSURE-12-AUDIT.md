# FOUNDATION-TENANT-REPOSITORY-CLOSURE-12 — audit

**Scope:** Single appointment-core hotspot — **`AppointmentRepository::lockRoomRowForConflictCheck`**. **Wave ID:** **FND-TNT-18**. **Date:** 2026-03-29.

**Root families:** **ROOT-01** (id-only **`rooms`** lock), **ROOT-05** (lock basis aligned with **`appointments`** **`find`/`update`** org scope); **ROOT-02** adjacent (**`rooms.branch_id` must be non-null** for the standard EXISTS arm — null-branch room rows do not participate in this lock).

## Risk addressed

**`FOR UPDATE`** on **`rooms`** used **only** `id = ?` — no proof the room belonged to the **resolved tenant organization**, so a caller supplying another tenant’s **`room_id`** could still take a row lock / participate in the conflict pipeline without canonical org/branch proof.

## Closure

- **`lockRoomRowForConflictCheck`:** **`SELECT r.id FROM rooms r … FOR UPDATE`** + **`branchColumnOwnedByResolvedOrganizationExistsClause('r')`** (same predicate family as **`appointments`** reads/writes in this repository).

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_18_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (unchanged at wave 12)

- **`hasRoomConflict`** org anchoring — closed **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-13`** (**FND-TNT-19**). **`hasStaffConflict`** remains separate.
- Public booking paths that do not set **`room_id`** (unchanged).
