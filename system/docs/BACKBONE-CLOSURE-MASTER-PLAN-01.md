# Backbone closure — master execution plan — 01

**Date:** 2026-03-29  
**Mode:** Backbone Closure — backend-first, proof-first, ZIP/repo truth only.  
**Single human-readable execution spine:** this file defines **phase order** and **freeze rules**.  
**Strict status / evidence vocabulary:** `TASK-STATE-MATRIX.md` (authoritative for `CLOSED` / `PARTIAL` / `OPEN` / `REOPENED` / `AUDIT-ONLY` / `PLANNED`).  
**The only LIVE implementation queue:** `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` — **exactly one LIVE task** and **exactly one PARKED next task** at a time.  
**Non-current work:** `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`.

### Matrix `OPEN` vs LIVE execution

- Rows in `TASK-STATE-MATRIX.md` that are **`OPEN`**, **`REOPENED`**, or **`PARTIAL`** are **full truth inventory** — they do **not** mean “currently being implemented.”
- **LIVE** work exists **only** when an ID is listed under **LIVE** in `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`. Everything else is **later phase**, **parked**, or **unpromoted inventory** until explicitly promoted there.
- Parallel backbone implementation on multiple unpromoted matrix rows is **out of policy**.

Do not use product roadmaps, Booker parity queues, or legacy hardening wave docs as **active** execution order; they are **historical or deferred** until backbone phases complete (see banners on those files).

---

## Root-cause register (canonical truth)

**File:** `ROOT-CAUSE-REGISTER-01.md`

Backbone execution is **root-cause-driven**, not bug-instance-driven: recurring failures are grouped into **ROOT-01**–**ROOT-05** families (id-only repositories, null-branch semantics, public bootstrap inconsistency, repair/global fallback ambiguity, service-layer scope drift). **LIVE** slices, inventory rows, and audits should **tie work to the ROOT id(s)** it reduces.

**Anti-drift:** Fixing the same symptom repeatedly without naming a **ROOT** family does **not** count as phase closure. **No feature expansion** ahead of materially reducing the **relevant** root families for the **current phase** (see register + Phase **Frozen until closed** rules below).

This register is a **truth/control tool** only — it does **not** add parallel LIVE tasks; **`FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`** still allows **exactly one LIVE** and **exactly one PARKED** row.

---

## Phase 0 — Planning cleanup and backlog freeze

**Status:** **CLOSED** (2026-03-29) — canonicalization wave plus **BACKBONE-CLOSURE-ACTIVE-SPINE-TIGHTENING-02** (single LIVE / single PARKED charter). **Phase 1** is active; sole **LIVE** implementation task is **`PLT-TNT-01`** per `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.

### Why it exists

Eliminate competing execution sources, freeze feature/parity/polish queues, and align all charters to one backbone spine so implementation work cannot drift across multiple “active” roadmaps.

### In scope

- Canonicalize planning docs: this plan, updates to `TASK-STATE-MATRIX.md`, `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md`, `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.
- Publish `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` and mark legacy roadmaps **HISTORICAL REFERENCE ONLY** or **DEFERRED UNTIL BACKBONE CLOSURE** with pointers here and to the matrix.
- No product code, schema, route, or feature changes as part of this phase.

### Out of scope

- Any repository, tenant, async, MFA, runtime, or bootstrap implementation.
- Re-scoping or redesigning product/UI direction.

### Done criteria

- This file exists and is referenced from the three reconciled charters and the matrix header.
- Deferred registry exists and lists deferred/historical/obsolete items with reasons.
- Legacy execution-queue docs carry the standard banner and no longer present themselves as the **current** spine without opening this plan.
- **BACKBONE-CLOSURE-ACTIVE-SPINE-TIGHTENING-02:** `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` holds **exactly one LIVE** (**`PLT-TNT-01`**) and **exactly one PARKED** (**`PLT-Q-01`**); matrix vs LIVE semantics documented in this plan and `TASK-STATE-MATRIX.md`.

### Frozen until this phase is closed

- Declaring new “master” roadmaps or parallel active backlogs outside this file + matrix + slim active charter.

---

## Phase 1 — Tenant boundary closure

**LIVE charter rule:** Only **`PLT-TNT-01`** may be **LIVE** until rotated by explicit charter update. **`PLT-LC-01`**, **`FND-TNT-05`**, **`FND-TNT-06`**, inventory/MGP tenant matrices, and **`PLT-DB-01`** slices stay **`OPEN`** / **`REOPENED`** / **`PARTIAL`** in `TASK-STATE-MATRIX.md` (and Phase 1 inventory in `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`) until **promoted** into the **LIVE** row — they are **not** concurrent implementation permission.

### Why it exists

Multi-tenant integrity and lifecycle enforcement are **REOPENED** / **OPEN** at the platform level (`TASK-STATE-MATRIX.md`). Until tenant boundaries are fail-closed and consistently enforced across repositories, services, and public/internal surfaces, downstream platform work compounds leakage and proof debt.

### In scope

- **Universal tenant fail-closed** program: **`PLT-TNT-01`** and mechanical **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-*`** slices (inventory: `TENANT-SAFETY-INVENTORY-CHARTER-01.md`, verifiers under `system/scripts/read-only/`, Tier A gate in `run_mandatory_tenant_isolation_proof_release_gate_01.php`). Slices target **ROOT-01**–**ROOT-05** families per `ROOT-CAUSE-REGISTER-01.md`.
- **Lifecycle and suspension** end-to-end: **`PLT-LC-01`** (suspended organization, inactive user/staff, public exposure consistency) aligned with matrix **REOPENED** rows.
- **Residual org-scoped predicates and read paths** where they are tenant-boundary work: **`FND-TNT-05`**, **`FND-TNT-06`** (and equivalents) until rolled into closed proof or explicitly superseded by a closed wave.
- **Inventory tenant data plane** remainder: **`INVENTORY-TENANT-DATA-PLANE-HARDENING-01`** per **`INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md`** (**PARTIAL** → closed bar per matrix).
- **Memberships / gift cards / packages tenant data plane** closure: **`MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01`** per **`MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-MATRIX.md`** (**OPEN** in matrix until closed with proof).
- **Selective DB integrity** only where it is **blocking** tenant-boundary or data-plane closure (**`PLT-DB-01`** slices explicitly tied to Phase 1 risks — not the full remainder).

### Out of scope

- New catalog, storefront, mixed-sales, or Booker-parity product lanes.
- UI/polish, new admin pages, settings feature expansion.
- Unified queue product (**Phase 2**).
- MFA/step-up program (**Phase 3**).
- General observability stack, second storage driver, full CI breadth (**Phase 4**).
- Modular bootstrap/route decomposition (**Phase 5**).

### Done criteria

- `TASK-STATE-MATRIX.md` no longer lists **REOPENED** for universal multi-tenant boundary and tenant lifecycle gating, **or** a consciously documented exception matrix exists with CLOSED proofs per remaining slice (same evidentiary standard as matrix).
- Phase 1 matrix charters (**inventory**, **M/G/P**) reach **CLOSED** (or matrix-updated **PARTIAL** with no remaining **OPEN** rows for in-scope modules) with executed smokes/verifiers as defined in those matrices.
- Tier A (and documented Tier B when required) tenant isolation gate remains green on canonical build path.

### Frozen until this phase is closed

- **Phase 2–5** implementation starts (except Phase 0 doc maintenance).
- Product execution queues (Booker parity, modernization, founder ops feature work).
- Non-essential performance work (e.g. invoice sequence partitioning) — see deferred registry.

---

## Phase 2 — Async backbone closure

### Why it exists

Async and job execution exist as **fragmented islands** (image pipeline, `runtime_execution_registry`, merge jobs, outbound dispatch, crons) — **PARTIAL** in the matrix. A **unified** control-plane for queues, DLQ, and poison-job semantics is **OPEN** (**`PLT-Q-01`**) and required for predictable operations at scale.

### In scope

- **Unified queue / async control-plane**: **`PLT-Q-01`** — cross-workload semantics, DLQ/poison policy, operator visibility consistent with existing `runtime_execution_registry` and workload-specific claim patterns.
- Hardening that **generalizes** patterns already proven per workload (e.g. `SKIP LOCKED`, stale reclaim) without inventing a new product surface unrelated to backbone.

### Out of scope

- New marketing automation features, new notification product channels beyond backbone needs.
- SMS as full operational channel (remains **`OPEN`** per matrix unless explicitly moved into a later phase charter).
- Tenant repository closure remainder (**Phase 1**).
- MFA (**Phase 3**).

### Done criteria

- Matrix classifies generalized async/queue control-plane as **`CLOSED`** or documented **`PARTIAL`** with explicit remaining **OPEN** rows owned by IDs — no silent “islands only” posture for operators.
- Acceptable proof: verifiers + runbook pointers consistent with `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md` §2 style (execution registry + workload hooks).

### Frozen until this phase is closed

- **Phase 3–5** implementation (except documentation updates).
- Feature expansion that adds new background job families without control-plane alignment.

---

## Phase 3 — Privileged plane closure

### Why it exists

Founder / platform **support-entry** is **live** (**`PARTIAL`** in matrix); **MFA / step-up** is **`OPEN`** (**`PLT-MFA-01`**, elevated urgency). Privileged entry without strong authentication is a production-class risk.

### In scope

- **MFA / step-up authentication** for privileged and support-entry flows: **`PLT-MFA-01`**.
- Tightening **audit and guardrails** that are **security-closure** (not UX polish) when required for **`CLOSED`** proof.

### Out of scope

- Founder ops **copy tuning**, playbook libraries, or dashboard polish (deferred).
- General product settings expansion.

### Done criteria

- Matrix: **`PLT-MFA-01`** **`CLOSED`** with proof tied to live code paths (`FounderSupportEntryService`, platform routes, session/support-entry state) or explicit **`PARTIAL`** with bounded **OPEN** follow-ups listed by ID.

### Frozen until this phase is closed

- Treating support-entry-adjacent work as “cosmetic” or deferring MFA in favor of product parity work.

---

## Phase 4 — Production runtime closure

### Why it exists

Production scale and multi-node posture require explicit baselines: Redis (**`PLT-REDIS-01`**), shared session / sticky deployment truth (**`PLT-SESS-01`**), second storage implementation (**`PLT-OBJ-01`**), API versioning governance (**`PLT-API-01`**), payment rail architecture (**`PLT-PAY-01`**), CI/regression breadth, observability depth, artifact hygiene beyond canonical ZIP, and remaining **`PLT-DB-01`** not already closed in Phase 1.

### In scope

- **Mandatory production Redis baseline** — **`PLT-REDIS-01`**.
- **Session federation / deployment truth** — **`PLT-SESS-01`**.
- **Non-local `StorageProviderInterface` implementation** — **`PLT-OBJ-01`**.
- **Observability** follow-on (e.g. **FOUNDATION-OBSERVABILITY-AND-ALERTING-02**) per production charter.
- **Test/release discipline** — **FOUNDATION-TEST-AND-RELEASE-DISCIPLINE-01** / **`FND-TST-04`** (named PHPUnit or equivalent harness + CI hook).
- **Public API versioning** — **`PLT-API-01`**.
- **PSP / vaulted pay / charge lifecycle** — **`PLT-PAY-01`** (distinct from membership invoice mechanics).
- **CI / automated regression breadth** — matrix **OPEN** row until owned.
- **Verified clean shipped-artifact discipline** beyond canonical handoff ZIP — matrix **PARTIAL**/**OPEN** until owned.
- **Remaining `PLT-DB-01`** integrity work not completed in Phase 1.
- **Operational resilience** items the matrix lists as default **OPEN** (backup/DR runbooks, load/stress strategy, tracing/metrics stack, encryption/key rotation, generalized feature flags) **when** scheduled into this phase as named tasks.

### Out of scope

- Booker parity and catalog expansion.
- Phase 5 bootstrap decomposition unless blocking runtime proof (then document exception).

### Done criteria

- Each **OPEN** / **PARTIAL** item in this phase either **`CLOSED`** with proof in matrix or explicitly **`PLANNED`** with an ID and acceptance test — no orphan narrative.

### Frozen until this phase is closed

- Declaring “production-ready SaaS” scale posture without closing **PLT-REDIS-01**, **PLT-SESS-01**, and **PLT-OBJ-01** (or documented single-node exception with matrix update).

---

## Phase 5 — Bootstrap / portability closure

### Why it exists

Modular bootstrap and route registration (**`OPEN`** in matrix), migration hygiene, and doc/truth alignment affect safe upgrades and operator portability. These are **backbone** concerns for long-term maintenance, not product features.

### In scope

- **Modular bootstrap / routes** implementation aligned with **`BOOKER-PARITY-MASTER-ROADMAP.md` §6.2** *design intent* (execution deferred until this phase): **PH1-BOOT-01**, **PH1-ROUTE-01** (IDs preserved from historical reconciliation doc; proof in matrix when closed).
- **FOUNDATION-DOC-TRUTH-AND-MIGRATION-HYGIENE-01** (duplicate migration ordinals, stale audit supersession).
- **Organization / onboarding/offboarding** programs (**PH2-ORG-01**, **PH4-ONB-01**, etc.) **only** where they are portability/bootstrap closure, not product growth — scoped in matrix when activated.

### Out of scope

- Package/subscription **commercial enforcement** product depth (**PH3-PKG-01**) until explicitly pulled from deferred registry into a future charter (not part of backbone closure unless matrix says otherwise).

### Done criteria

- Matrix reflects **`CLOSED`** or bounded **PARTIAL** for bootstrap/modularity **OPEN** row; migration hygiene verifier exists and passes; stale audits cross-linked.

### Frozen until this phase is closed

- Large-scale module splitting or “framework migration” without closure proof.

---

## Execution discipline (all phases)

1. **ZIP/repo truth** — evidence from code, scripts, and canonical `handoff/build-final-zip.ps1` path; see `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md`.
2. **One spine** — phase order here; **full inventory** in **`TASK-STATE-MATRIX.md`**; **only LIVE queue** in **`FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`** (one LIVE + one PARKED max).
3. **No duplicate masters** — deferred work lives in **`DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`**.
4. **Matrix is not a sprint board** — many **`OPEN`** rows; **one** LIVE task unless the charter is explicitly updated.

---

## Phase map (quick reference)

| Phase | Name |
|-------|------|
| 0 | Planning cleanup and backlog freeze |
| 1 | Tenant boundary closure |
| 2 | Async backbone closure |
| 3 | Privileged plane closure |
| 4 | Production runtime closure |
| 5 | Bootstrap / portability closure |
