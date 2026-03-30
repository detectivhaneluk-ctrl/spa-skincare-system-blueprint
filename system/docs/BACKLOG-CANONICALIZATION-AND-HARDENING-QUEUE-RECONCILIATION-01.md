# Backlog canonicalization & platform hardening queue — RECONCILIATION-01

> **HISTORICAL REFERENCE ONLY** — §B numbered ordering is **superseded** by **`BACKBONE-CLOSURE-MASTER-PLAN-01.md`** phase map. This file remains an **ID and reconciliation inventory**. Strict status: **`TASK-STATE-MATRIX.md`**. Deferred work: **`DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`**.

**Date:** 2026-03-29  
**Role (historical):** Backend/platform reconciliation snapshot. **Current execution spine:** `BACKBONE-CLOSURE-MASTER-PLAN-01.md`. **Product / Booker queue** (`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C) is **DEFERRED** during backbone closure. **Status labels:** `CLOSED` | `PARTIAL` | `OPEN` | `REOPENED` | `AUDIT-ONLY` | `PLANNED` — aligned with **`TASK-STATE-MATRIX.md`** / **§3.1** (no `DONE`; no treating **`AUDIT-ONLY`** as domain **`CLOSED`**).

**Product freeze (core closure first):** Until **`BACKBONE-CLOSURE-MASTER-PLAN-01.md`** phases allow it, **do not add new pages or expand UI surfaces** — execution priority is **backend org/branch scope + proof** per backbone Phase 1. See **`BACKEND-HARDENING-WAVE-ROADMAP.md`** global stop rule and task **FOUNDATION-NO-NEW-PAGES-UNTIL-CORE-HARDENING-PLAN-CLOSURE-02**.

**Sources reconciled:** `TASK-STATE-MATRIX.md`, `TENANT-RISK-REGISTER.md`, `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`, `BACKEND-ARCHITECTURE-TRUTH-MAP-CHARTER-01.md`, `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md`, `TENANT-SAFETY-INVENTORY-CHARTER-01.md`, `BOOKER-PARITY-MASTER-ROADMAP.md` §6, matrix docs under `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md` and `MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-MATRIX.md`.

---

## A) Canonical backlog reconciliation

### Already `CLOSED` (runtime- or gate-verified — not active queue)

- Waves and gates listed under **`CLOSED`** in `TASK-STATE-MATRIX.md` (FOUNDATION-100, TENANT-BOUNDARY-HARDENING-01, SETTINGS-TENANT-ISOLATION-01, TENANT-ENTRY-FLOW-01, TENANT-OWNED-DATA-PLANE-HARDENING-01 for in-scope modules, **SALES-TENANT-DATA-PLANE-HARDENING-01**, **PLT-REL-01** Tier A static tenant-isolation proof gate, **PLT-PKG-08** + **FND-PKG-01** enforced packaging / **canonical** handoff ZIP verification + checkpoint checklist, **FND-MIG-02** migration baseline deploy gate + runbook, public commerce finalize cut, public booking abuse baseline, platform route shell baseline, **outbound email** queue + dispatch baseline (SMS **not** operationally closed — `TASK-STATE-MATRIX.md`), charter items CH01-A–F in `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`).
- §5.C Phase **1.1–1.7** shipped rows; selective §8 / §5.E archives — see `BOOKER-PARITY-MASTER-ROADMAP.md`.
- **Not** “everything in §6.1”: unified-catalog **read-model**, mixed-sales **audit waves**, and broad **inventory** themes include **`AUDIT-ONLY`** + **`PARTIAL`** rows — see reconciled **`BOOKER-PARITY-MASTER-ROADMAP.md` §6.1**.
- `OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01` — **`AUDIT-ONLY`** (matrix execution; **not** tenant product-closed).

### Remains active (platform / foundation)

| Anchor | Status | Notes |
|--------|--------|--------|
| Multi-tenant fail-closed across repositories/services | REOPENED | Universal adoption; see PARTIAL rows in `TASK-STATE-MATRIX.md` |
| Lifecycle + suspension enforcement (internal + public) | REOPENED / OPEN | Align with `TENANT-RISK-REGISTER` risks 6–7 |
| Automated tenant proof as mandatory release gate | **CLOSED (static)** / **OPEN (integration bar)** | **PLT-REL-01:** Tier A enforced in `run_mandatory_tenant_isolation_proof_release_gate_01.php` + `handoff/build-final-zip.ps1`; Tier B (`--with-integration`) = release runbook when DB seeded |
| **INVENTORY-TENANT-DATA-PLANE-HARDENING-01** | PARTIAL | `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md` — through wave 5: product scoped writes + invoice-joined settlement aggregates + scoped backfill/orphan/retire/post-tree; matrix lists remaining tooling-only deprecated paths + aggregate empty-scope fallback + detach/cross-module |
| **MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01** | OPEN | `MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-MATRIX.md` |
| **FND-PERF-03** | OPEN | Invoice sequence / per-org plan |
| **FND-TST-04** | OPEN | No PHPUnit project; smokes partial |
| **FND-TNT-05** | OPEN | Org predicates from tenant inventory |
| **FND-TNT-06** | OPEN | Residual read-path gaps |
| §6.2 Phase 1–5 programs | OPEN | Modular bootstrap/routes, org/package/onboarding/scale themes |

### Reopened (explicit)

- **Multi-tenant boundary fail-closed** across all repositories and services (`TASK-STATE-MATRIX.md`).
- **Tenant lifecycle gating** (suspended org, inactive user-staff, public exposure consistency) (`TASK-STATE-MATRIX.md`).

### New strategic tasks (architecture audit — platform only)

| ID | Item |
|----|------|
| **PLT-MFA-01** | MFA / step-up authentication — **`OPEN`**; **elevated urgency:** privileged **founder support-entry** is **live** (`FounderSupportEntryService`, `PlatformFounderSupportEntryController`, `SupportEntryController`, `FounderImpersonationAuditService`) — step-up protects a **real** path, not a hypothetical one |
| **PLT-SESS-01** | Shared session storage **or** sticky-session-aware deployment plan (file sessions insufficient multi-node) |
| **PLT-REDIS-01** | Redis as mandatory production baseline (cache, rate-limit, shared state) |
| **PLT-Q-01** | **Unified** queue/async **control-plane** (DLQ, poison jobs, cross-workload semantics) — **`OPEN`**; **fragmented** islands (image pipeline, registry, merge jobs, outbound dispatch, crons) already **`PARTIAL`** in matrix |
| **PLT-OBJ-01** | **Second storage provider** (object/S3-compatible or equivalent) — **missing**; local `StorageProviderInterface` seam **`CLOSED`**; multi-node/off-box media still **`OPEN`** |
| **PLT-DB-01** | **Selective** remaining FK / integrity / unsafe-table closure — schema already has meaningful FKs in many places; **not** greenfield “first keys” work |
| **PLT-API-01** | Public API versioning governance |
| **PLT-PKG-08** | **`CLOSED` (2026-03-28):** enforced dual ZIP verify in `build-final-zip.ps1` + checklist in `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` (with **FND-PKG-01**) |
| **PLT-PAY-01** | PSP / vaulted pay / auto-capture **charge lifecycle** — **`OPEN`**; membership **invoice/billing** mechanics **do not** close this |
| **PLT-INFRA-01** | Custom infra security/performance hardening plan (routing, auth, session, SMTP, rate-limit, DI) |

### Duplicates / noise cleaned (this pass)

- Removed **SALES-TENANT-DATA-PLANE-HARDENING-01** from the **OPEN** queue in `TASK-STATE-MATRIX.md` (it is **`CLOSED`** with smoke proof; staying in historical **`CLOSED`** list).
- Clarified **`CLOSED`** vs **active** in `TASK-STATE-MATRIX.md` so closed gates do not read as “still in queue.”

### Audit-only / proof gaps (do not treat as `CLOSED` product work)

- Per `TASK-STATE-MATRIX.md` — read-only OPS docs, non-CI verifiers, any narrative “closed” without fail-closed automated proof → downgrade to `OPEN` / `PARTIAL` / `AUDIT-ONLY`.

### Platform visibility gaps (default `OPEN` — add to charters / truth maps)

Own explicitly in **`FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md`** + **`TASK-STATE-MATRIX.md`**: load/stress testing strategy; backup/restore/DR runbooks; DB scaling/capacity strategy; **generalized** rollout/feature-flag platform (vs **`PARTIAL`** emergency public kill switches); tracing/metrics stack; sensitive-data encryption/key rotation; **unified** queue DLQ/poison-job strategy; **CI breadth** (currently **PLT-REL-01** workflow only); **artifact cleanliness** beyond canonical ZIP build; **out-of-scope module residual** (`OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01` + later waves — no single “all modules closed” truth).

---

## B) Strict backend/platform execution order (risk-first, dependency-aware)

*UI and §5.C product rows are out of scope for this list. Order assumes deploy artifacts exist before widening blast radius.*

**Urgency note (addendum 02):** **PLT-MFA-01** is **elevated** because **founder support-entry** is **`PARTIAL`** / implemented in runtime (see `TASK-STATE-MATRIX.md`). Dependency order below is unchanged; treat MFA as **high risk** alongside tenant work when scheduling.

1. ~~**PLT-REL-01**~~ — **`CLOSED` (2026-03-28):** Automated tenant isolation **Tier A** proof mandatory before handoff ZIP (`run_mandatory_tenant_isolation_proof_release_gate_01.php` + `build-final-zip.ps1`); Tier B integration documented on same runner. (**Universal** tenant fail-closed across all repos remains **`REOPENED`** — this gate is **not** that closure.)
2. ~~**PLT-PKG-08** + **FND-PKG-01**~~ — **`CLOSED` (2026-03-28):** `build-final-zip.ps1` runs `verify_handoff_zip_rules_readonly.php` after PS ZIP scan; checkpoint checklist in `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md`.
3. ~~**FND-MIG-02**~~ — **`CLOSED` (2026-03-28):** canonical deploy gate `run_migration_baseline_deploy_gate_01.php` + explicit migrate vs deploy-safe distinction + checklist in `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § FND-MIG-02.
4. **PLT-TNT-01** — Multi-tenant fail-closed guarantee across **all** repositories/services (extends partial hardening). **Wave 2026-03-28:** `client_merge_jobs` repository org predicates + Tier A proof `verify_client_merge_job_repository_org_scope_plt_tnt_01.php` (`TENANT-SAFETY-INVENTORY-CHARTER-01.md`).
5. **PLT-LC-01** — Lifecycle + suspension enforcement end-to-end (internal + public surfaces). **Wave 2026-03-28:** legacy membership-off pinned branch + **`POST /account/branch-context`** suspension choke; static proof **`verify_tenant_branch_access_legacy_suspended_org_plt_lc_01.php`** (PLT-REL-01 Tier A). **REOPENED** matrix row unchanged (workers/CLI, broader audit).
6. **FND-TNT-05** — Repository-layer org predicates from `TENANT-SAFETY-INVENTORY-CHARTER-01.md` risky list. **Wave 2026-03-28:** `PublicCommercePurchaseRepository` — invoice-keyed reads/locks correlated to tenant `InvoiceRepository` row (branch + live-invoice join); Tier A **`verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05.php`**. **OPEN** risky list otherwise unchanged.
7. **FND-TNT-06** — Residual read paths (controller → repo) per F-12 matrix / `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md`. **Wave 2026-03-28:** `InvoiceController` client loads for show / cashier / membership checkout use **`ClientRepository::findLiveReadableForProfile`** (invoice branch envelope); Tier A **`verify_invoice_client_read_envelope_fnd_tnt_06.php`**. **OPEN** for other F-12 read surfaces.
8. **INVENTORY-TENANT-DATA-PLANE-HARDENING-01** — Inventory data-plane closure (matrix-driven). **Waves:** (1) taxonomy detail/labels — `verify_inventory_taxonomy_tenant_scope_readonly_01.php`; (2) index/batch/internal — `verify_inventory_tenant_scope_followon_wave_02_readonly_01.php`; (3) tree/HQ/select lists — `verify_inventory_tenant_scope_followon_wave_03_readonly_01.php`; (4) invoice/catalog/supplier/taxonomy-repair — `verify_inventory_tenant_scope_followon_wave_04_readonly_01.php`; (5) product writes + settlement aggregates + backfill/orphan/retire scope — `verify_inventory_tenant_scope_followon_wave_05_readonly_01.php` — **PARTIAL** (matrix remaining rows).
9. **MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01** — M/G/P data-plane closure (matrix-driven).
10. **PLT-DB-01** — **Selective** remaining integrity/FK/unsafe-table closure (baseline FKs already exist in much of schema).
11. **PLT-REDIS-01** — Production Redis baseline (cache, rate limits, shared ephemeral state).
12. **PLT-SESS-01** — Shared session **or** sticky-session deployment plan (depends on **11** for typical designs).
13. **PLT-MFA-01** — MFA / step-up for founder and **live** support-entry flows (**elevated** — see note above).
14. **PLT-Q-01** — **Unified** queue/DLQ/async control-plane (fragmented islands already **`PARTIAL`**).
15. **PLT-OBJ-01** — **Non-local** storage provider (local seam **`CLOSED`**; second implementation **`OPEN`**).
16. **PH1-BOOT-01** — Modular bootstrap registration (`BOOKER-PARITY-MASTER-ROADMAP.md` §6.2 Phase 1).
17. **PH1-ROUTE-01** — Modular route registration (same).
18. **FND-TST-04** + Phase 1 **lint / minimum regression** gates — named PHPUnit or expanded parallel smoke (`BACKEND-ARCHITECTURE-TRUTH-MAP-CHARTER-01.md`).
19. **PH2-ORG-01** / **PH2-ISO-01** — Organization foundation + user↔business isolation per §6.2–6.3 **where not already wave-closed** (design: `ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md`, checklist F-07).
20. **PLT-API-01** — Public API versioning governance.
21. **PLT-PAY-01** — Payment gateway architecture decision + integration seam (no vendor lock-in narrative in code without ADR).
22. **PLT-INFRA-01** — Custom stack hardening plan (routing, auth, session, SMTP, rate-limit, DI) — umbrella after concrete seams (**12**, **13**, **11**, **14**) are owned.
23. **PH3-PKG-01** — Package/subscription enforcement engine (§6.2 Phase 3) — after **19** when limits must be real.
24. **PH4-ONB-01** — Onboarding/offboarding + tenant-scoped storage semantics (§6.2 Phase 4–5 overlap with **15**).
25. **PH5-OPS-01** — Scale/observability/job visibility (§6.2 Phase 5) — complements **14**.
26. **FND-PERF-03** — Invoice sequence hotspot / per-tenant sequences (`INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01.md`).

---

## C) Related queues (do not merge)

- **§5.C** — `BOOKER-PARITY-MASTER-ROADMAP.md` — product / Booker-parity only.
- **Founder ops** — `FOUNDER-OPS-ACTIVE-BACKLOG.md` — operator UX/guardrails; **support-entry** runtime exists — **PLT-MFA-01** / step-up is **not** a future-only dependency for that path.

---

## D) Maintenance rule

When a platform item closes, update `TASK-STATE-MATRIX.md` to **`CLOSED` (historical)** with proof, align `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md` and `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`, and follow **`BACKBONE-CLOSURE-MASTER-PLAN-01.md`**. Optionally append proof to **§8** in `BOOKER-PARITY-MASTER-ROADMAP.md` when that file is active again — **do not** duplicate closure narrative inside §5.C.
