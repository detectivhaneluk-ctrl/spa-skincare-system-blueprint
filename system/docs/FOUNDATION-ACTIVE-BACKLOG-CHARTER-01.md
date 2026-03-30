# Foundation active backlog — CHARTER-01 (normalized)

**Backbone Closure Mode — live execution queue:** This file is the **only** place that may list **LIVE** implementation work.  
**Master execution plan:** `BACKBONE-CLOSURE-MASTER-PLAN-01.md`  
**Full truth inventory (not a work-in-progress permit):** `TASK-STATE-MATRIX.md`  
**Platform facts + layer proofs:** `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md`  
**Deferred product/polish and Phase 1 inventory not promoted here:** `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`

**Anti-drift (mandatory):**

- **At most one LIVE task at a time** — concurrent implementation threads against multiple backbone IDs without charter promotion are **out of policy**.
- **At most one PARKED / NEXT task** — the single approved successor when the LIVE task closes or is explicitly swapped.
- **`TASK-STATE-MATRIX.md` is the truth inventory** — many **`OPEN`** / **`REOPENED`** / **`PARTIAL`** rows exist simultaneously; that is **not** permission to implement them in parallel. It is **not** “implementation concurrency allowed.” Promotion into **this** charter is required before work is **LIVE**.
- **Root-cause register** — `ROOT-CAUSE-REGISTER-01.md`: backbone work is **root-family-driven**. **Repeated bug-instance fixes** without naming a **ROOT-** id do **not** count as real closure. **No feature expansion** ahead of materially reducing the **relevant** root families for the **current phase** (see register + master plan freeze rules).

**Statuses (aligned with `TASK-STATE-MATRIX.md`):** `CLOSED` | `PARTIAL` | `OPEN` | `REOPENED` | `AUDIT-ONLY` | `PLANNED` — plus charter-local **`PROVISIONAL`** / **`DROPPED/OBSOLETE`** where useful. Legacy **`COMPLETED`** = **`CLOSED`**.

---

## Phase 0 — **CLOSED** (2026-03-29)

Planning cleanup, backlog freeze, legacy banners, and **BACKBONE-CLOSURE-ACTIVE-SPINE-TIGHTENING-02** (single LIVE / single PARKED charter) are **complete**. **Execution is in Phase 1** with **`PLT-TNT-01`** as the sole **LIVE** task (see below).

| ID | Item | Evidence |
|----|------|----------|
| BC-PH0-01 | Backbone canonicalization + active spine tightening | `BACKBONE-CLOSURE-MASTER-PLAN-01.md`, `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`, matrix header, legacy doc banners, this charter structure |

---

## CLOSED (historical charter evidence — not Phase 0)

| ID | Item | Evidence |
|----|------|----------|
| CH01-A | Repo truth map (backend) | `BACKEND-ARCHITECTURE-TRUTH-MAP-CHARTER-01.md` |
| CH01-B | Cross-platform ZIP rule verifier | `system/scripts/read-only/verify_handoff_zip_rules_readonly.php` (parity with `handoff/HandoffZipRules.ps1`) |
| CH01-C | Migration baseline report CLI (+ strict alignment, shared `MigrationBaseline`) | `system/scripts/read-only/verify_migration_baseline_readonly.php`, `system/core/app/MigrationBaseline.php` — full contract: `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` |
| CH01-D | Request-scope memo: `SessionAuth::user()` | `system/core/auth/SessionAuth.php` |
| CH01-E | Request-scope memo: `SettingsService::get` / `all` + branch→org lookup | `system/core/app/SettingsService.php` |
| CH01-F | Tenant safety inventory (read-only) | `TENANT-SAFETY-INVENTORY-CHARTER-01.md` |
| FND-PKG-01 | Enforced handoff ZIP gate + release checklist | `handoff/build-final-zip.ps1` invokes `verify_handoff_zip_rules_readonly.php` after PS ZIP scan (PLT-PKG-08 + FND-PKG-01); operator checklist `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § PLT-PKG-08 |
| FND-MIG-02 | Migration baseline deploy gate + operator runbook | `run_migration_baseline_deploy_gate_01.php`; `migrate.php --verify-baseline` documented; checklist `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § FND-MIG-02 |

---

## LIVE (exactly one)

| ID | Item | Notes |
|----|------|-------|
| **PLT-TNT-01** | Universal tenant fail-closed / mechanical repository closure | **Root-family targets (Phase 1):** **ROOT-01**–**ROOT-05** per `ROOT-CAUSE-REGISTER-01.md` — **not** parallel threads; classification only. **Latest closure:** **CLOSURE-24** — ROOT-04 strict-vs-repair split for membership invoice-plane helpers (`MembershipSaleRepository`, `MembershipBillingCycleRepository`, `OrganizationRepositoryScope`) plus fail-closed `findByMembershipAndPeriod()`. **Next slice:** remaining explicit global/control-plane compatibility inventory outside the membership split, not new product work. Tier A: `run_mandatory_tenant_isolation_proof_release_gate_01.php`. **CLOSURE-18**–**24** closed. |

---

## PARKED / NEXT (exactly one — do not start until Phase 1 closure policy allows)

| ID | Item | Notes |
|----|------|-------|
| **PLT-Q-01** | Unified async / queue control-plane | **Phase 2** entry; **do not implement** while **`PLT-TNT-01`** is **LIVE** unless master plan Phase 1 done criteria are met and this row is rotated per policy. |

---

## DROPPED / OBSOLETE (for this queue)

| ID | Item | Reason |
|----|------|--------|
| — | Duplicate “foundation hardening” bullets scattered in multiple audit docs | **Superseded** by this file + truth map + backbone plan; source docs remain historical |

---

## Related canonical references

- `ROOT-CAUSE-REGISTER-01.md` — **ROOT-01**–**ROOT-05** recurring backbone families; tie LIVE slices and inventory rows to **ROOT** ids  
- `TASK-STATE-MATRIX.md` — **full** status inventory; **`OPEN` ≠ LIVE** (see matrix header)  
- `BACKBONE-CLOSURE-MASTER-PLAN-01.md` — phase order, Phase 0 **CLOSED**, Phase 1 scope definition  
- `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` — deferred work + Phase 1 items **not** in the live charter  
- `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` — ZIP/build/migrate checkpoint  
- `FOUNDATION-HARDENING-WAVE-REPAIR-CLOSURE-OPS.md` — prior wave closure truth  
- `ORGANIZATION-SCOPED-REPOSITORY-COVERAGE-MATRIX-FOUNDATION-12.md` — repo vs F-11 matrix  
- `REPO-CLEANUP-NOTES.md` — env / ZIP / local tree policy  
