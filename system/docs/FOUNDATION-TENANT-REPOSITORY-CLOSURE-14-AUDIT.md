# FOUNDATION-TENANT-REPOSITORY-CLOSURE-14 — audit

**Scope:** **`AppointmentRepository::hasStaffConflict`**. **Verifier file serial:** **`fnd_tnt_21`** in `verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php` (see **Naming** below). **Date:** 2026-03-29.

**Root families:** **ROOT-01**, **ROOT-05**; **ROOT-02** ( **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`** excludes **`appointments.branch_id IS NULL`** rows).

## Implementation vs proof-repair

- **FOUNDATION-TENANT-REPOSITORY-CLOSURE-14 (implementation slice):** **`AppointmentRepository.php`** was changed: **`hasStaffConflict`** now uses alias **`a`**, **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`**, and **`array_merge`** of fragment params into the overlap query (same canonical org/branch proof basis as **`find` / `list`**). Docblock documents org-wide-per-staff semantics, unused **`$branchId`** in SQL, and **ROOT-02** null-**`branch_id`** exclusion.
- **FOUNDATION-TENANT-REPOSITORY-CLOSURE-14-PROOF-REPAIR:** **No** edits to **`AppointmentRepository.php`**. This pass only **executed proof** with an explicit PHP binary path and **tightened this audit** so the trail does not imply the repository changed during proof-repair.

## Naming: CLOSURE-14 vs `fnd_tnt_21` vs “FND-TNT-21” in gate comments

- **CLOSURE-14** is the **foundation closure slice ID** (charter / audit name). It does **not** have to equal the verifier filename number.
- **`verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php`** uses serial **21** in the **tenant-closure wave file series** **`fnd_tnt_07` … `fnd_tnt_19`, `fnd_tnt_21`**. Serial **20** was **skipped** in this filename series so it does not collide with another gate line already associated with **FND-TNT-20** (inventory weak list/count verifier — different script).
- **Disambiguation:** The header comment block in **`run_mandatory_tenant_isolation_proof_release_gate_01.php`** *also* prints the text **FND-TNT-21** next to a **different** inventory script (`verify_inventory_product_repository_deprecated_mutation_read_runtime_closure_readonly_13.php`). **Authoritative for `hasStaffConflict` proof:** Tier A **`label`** **`tenant_closure_wave_fnd_tnt_21_readonly`** and script **`verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php`** (step **24/53** in the current Tier A ordering).

## Risk addressed

Staff overlap scanned **`appointments`** by **`staff_id`** only — **cross-tenant global** — with **no** org/branch proof (**ROOT-01**), unlike **`find`/`list`/`hasRoomConflict`** branch-scoped path (**ROOT-05**).

## Closure

- Query uses alias **`a`** and **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`**. Overlap remains **org-wide per staff** (any live branch in the resolved org); **`$branchId`** stays **unused** in SQL (signature parity only).

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php` (Tier A **`tenant_closure_wave_fnd_tnt_21_readonly`** in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

### Executed — FOUNDATION-TENANT-REPOSITORY-CLOSURE-14-PROOF-REPAIR (2026-03-29)

From repository root, using Laragon PHP (not bare `php` on PATH):

1. `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe system\scripts\read-only\verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php` → **exit 0** (no stdout on success).
2. `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe system\scripts\run_mandatory_tenant_isolation_proof_release_gate_01.php` → **exit 0**; **Tier A 24/53** runs **`tenant_closure_wave_fnd_tnt_21_readonly`**; closing line **`PLT-REL-01: OK (Tier A complete).`** (Tier B skipped by default).

## Out of scope (unchanged)

- **`hasBufferedAppointmentConflict`** / availability buffers; wiring new callers to **`hasStaffConflict`**.
