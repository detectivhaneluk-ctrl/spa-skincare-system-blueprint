# Task State Matrix

> **ARCHITECTURE RESET - 2026-03-31:** The previous LIVE task (`PLT-TNT-01`) has been **ARCHIVED / SUPERSEDED** by the 2026 Architecture Reset. The active roadmap is now **FOUNDATION-A1..A8**. The LIVE execution queue is `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`. Strategy document: `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md`. This matrix remains the **full status inventory** - its `OPEN`/`PARTIAL`/`REOPENED` rows are evidence records, not active sprint items.

Date: 2026-03-29 (addendum **02** deep findings; backbone closure canonicalization; **ACTIVE-SPINE-TIGHTENING-02**)  
Rule: status is assigned from runtime/code truth, not narrative closure. **`BOOKER-PARITY-MASTER-ROADMAP.md` §3.1** uses the same labels (Booker doc is **deferred** for execution — see banner there). This file is the **strictest status/evidence source** (`CLOSED` / `PARTIAL` / `OPEN` / `REOPENED` / `AUDIT-ONLY` / `PLANNED`).  
**Active human execution spine (phase order, freeze rules):** `BACKBONE-CLOSURE-MASTER-PLAN-01.md`. **The only LIVE implementation queue:** `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` (**at most one LIVE row, one PARKED row**). **Deferred/historical work:** `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`. Legacy numbered queue `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` §B is **historical ID reference only** — do not treat its ordering as competing with the backbone phases. **`FOUNDATION-TRUTH-RECONCILIATION-ADDENDUM-02.md`** records the addendum pass.

### Matrix inventory vs LIVE work (anti-drift)

- **`OPEN`**, **`REOPENED`**, and listed **`PARTIAL`** rows describe **evidence state** across the repo — **not** “this is in progress now” and **not** permission to implement in parallel.
- **`FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`** is the **only** authorized **LIVE** execution list. Work is **LIVE** only when promoted to that file’s **LIVE** section.
- All other matrix rows remain **inventory** for later backbone phases, parking, or future promotion. **Do not** treat the matrix as a multi-item sprint board.
- **Root-cause register:** `ROOT-CAUSE-REGISTER-01.md` defines **ROOT-01**–**ROOT-05**. Where applicable, **new or promoted hotspot tasks** should **state which ROOT id(s)** they close or reduce. **Repeated one-off fixes** without root-family alignment are **not** treated as backbone phase closure. **Feature expansion** is not implied before **relevant** root families for the **current phase** are materially reduced (see register + `BACKBONE-CLOSURE-MASTER-PLAN-01.md` freeze rules).

## Status vocabulary (strict — use only these)

- `CLOSED`: runtime-verified and behaviorally closed (historical “DONE” = `CLOSED`).
- `PARTIAL`: implementation exists but invariant, end-to-end bar, or full operational depth is incomplete.
- `OPEN`: not started, not production-closed, or proof/control-plane gap remains — **inventory / parked** unless the ID appears as **LIVE** in `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.
- `REOPENED`: previously treated closed; evidence shows material remaining risk — **inventory / parked** unless promoted **LIVE** in `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.
- `AUDIT-ONLY`: read-only verifier, truth map, or audit memo — **not** write-path or full product closure unless a separate row is `CLOSED`.
- `PLANNED`: chartered direction without repo execution proof yet.

If implementation exists but automated proof is weak, classify as `OPEN` or `PARTIAL` (do not imply `CLOSED`).

## CLOSED (historical — not active work; proof preserved)

- Public commerce anonymous finalize trust-cut to `awaiting_verification` (`PublicCommerceService::finalizePurchase`).
- Public booking abuse controls and token self-service baseline (`PublicBookingController`, `PublicBookingService`).
- Platform route shell and dashboard redirect split baseline (`/platform-admin`, `/dashboard` route/controller behavior).
- FOUNDATION-100 closure gate: explicit principal split + RBAC repair + executed smoke proof (`9 passed, 0 failed`).
- TENANT-BOUNDARY-HARDENING-01 closure gate: fail-closed tenant branch/org context enforcement + executed smoke proof (`8 passed, 0 failed`).
- SETTINGS-TENANT-ISOLATION-01 closure gate: organization-scoped settings precedence + executed proof (`4 passed, 0 failed`; runtime fail-closed smoke `8 passed, 0 failed`).
- TENANT-ENTRY-FLOW-01 closure gate: safe tenant resolver (`/tenant-entry`) with single-branch auto-resolve, multi-branch chooser, zero-context blocked/help screen + executed smoke proof (`10 passed, 0 failed`).
- TENANT-OWNED-DATA-PLANE-HARDENING-01 closure gate (in-scope protected modules): repository read/write scoping + linked-id fail-closed checks for Clients/Staff/Services/Appointments + executed smoke proof (`14 passed, 0 failed`).
- SALES-TENANT-DATA-PLANE-HARDENING-01 closure gate: protected sales runtime repository scoping + foreign-id write denial + executed smoke proof (`14 passed, 0 failed`).
- PLT-REL-01 — AUTOMATED-TENANT-ISOLATION-PROOF-MANDATORY-CI-RELEASE-GATE-01: canonical runner `system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php` (Tier A static proof set, deterministic order, non-zero on failure); **Tier A enforced** before handoff ZIP build via `handoff/build-final-zip.ps1`. Tier B integration smokes (`--with-integration`) mandatory for full release proof when a seeded DB is available (same scripts as historical sales/minimal-regression waves).
- PLT-PKG-08 + FND-PKG-01 — ENFORCED-PACKAGING-ZIP-VERIFIER-AND-RELEASE-CHECKLIST-01: `handoff/build-final-zip.ps1` runs `Get-HandoffZipForbiddenEntries` (PowerShell) **and** `system/scripts/read-only/verify_handoff_zip_rules_readonly.php` on the produced ZIP; any failure **removes** the output archive. Operator acceptance path and rule table: `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § PLT-PKG-08.
- FND-MIG-02 — MIGRATION-BASELINE-ALIGNMENT-AND-DEPLOY-GATE-01: canonical wrapper `system/scripts/run_migration_baseline_deploy_gate_01.php` (strict verify-only or `--apply` with forced `--verify-baseline`); `migrate.php` docblock clarifies **executed** vs **deploy-safe**; operator sequence and failure matrix `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § FND-MIG-02.
- Outbound **email** queue baseline and dispatch operationalization (`OutboundNotificationDispatchService`, SMTP path, channel policy email-only enqueue from PHP — see §5.C **1.2** / §8).
- Appointments/sales/memberships foundational runtime slices reflected in current modules (renewal/billing **invoice** mechanics exist; **PSP / vaulted pay / auto-capture** not implied — see **`OPEN`** below).

## PARTIAL (implemented slices; not hard-closed)

- FOUNDATION-98 home-routing split: functional but still permission-string dependent for plane boundary truth.
- FOUNDATION-99 runtime guard set: useful but not sufficient for tenant-grade separation closure.
- Organization context resolution: hardened for tenant runtime and entry flow, but repository-level enforcement is still not universal across all modules.
- Repository scoping: protected tenant runtime is hardened for Clients/Staff/Services/Appointments, but project-wide adoption remains incomplete in out-of-scope modules.
- PLT-TNT-01 (2026-03-28 wave): `ClientMergeJobRepository` org-keyed find/update for tenant HTTP + explicit worker id-only API; static proof `verify_client_merge_job_repository_org_scope_plt_tnt_01.php` in PLT-REL-01 Tier A — **does not** close the REOPENED “all repositories/services” bar.
- PLT-LC-01 (2026-03-28 wave): `TenantBranchAccessService` legacy pin requires non-suspended org; `BranchContextController` lifecycle gate on branch switch; static proofs `verify_tenant_branch_access_legacy_suspended_org_plt_lc_01.php` + extended `verify_lifecycle_suspension_hardening_wave_01_readonly.php` — **does not** close the REOPENED “tenant lifecycle gating / public exposure consistency” bar.
- FND-TNT-05 (2026-03-28 wave): `PublicCommercePurchaseRepository` invoice-keyed load/lock correlated to tenant invoice row + services/recovery updated; static proof `verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05.php` in Tier A — **does not** close full charter “highest-risk areas” / universal repo coverage.
- FND-TNT-06 (2026-03-28 wave): `InvoiceController` client reads use `findLiveReadableForProfile` with branch envelope; proof `verify_invoice_client_read_envelope_fnd_tnt_06.php` in Tier A — **does not** close all F-12 residual read paths (e.g. `ClientController::show`, list surfaces, register).
- FND-TNT-16 (2026-03-29 wave): `ClientRepository::lockActiveByEmailBranch` positive branch pin + `publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause`; proof `verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php` in Tier A — **does not** close sibling public phone / excluding paths or universal repo coverage.
- FND-TNT-17 (2026-03-29 wave): `ClientRepository::lockActiveByPhoneDigitsBranch` + `findActiveClientIdByPhoneDigitsExcluding` aligned to same anonymous-public contract as FND-TNT-16; proof `verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php` in Tier A — **does not** close appointment core / universal repo coverage.
- FND-TNT-18 (2026-03-29 wave): `AppointmentRepository::lockRoomRowForConflictCheck` tenant org-scoped `rooms` `FOR UPDATE`; proof `verify_tenant_closure_wave_fnd_tnt_18_readonly_01.php` in Tier A — **does not** close `hasRoomConflict` / universal repo coverage.
- FND-TNT-19 (2026-03-29 wave): `AppointmentRepository::hasRoomConflict` branch-scoped path uses `branchColumnOwnedByResolvedOrganizationExistsClause('a')`; proof `verify_tenant_closure_wave_fnd_tnt_19_readonly_01.php` in Tier A — **does not** close `hasStaffConflict` / universal repo coverage.
- BIG-04 (2026-03-31 / **FOUNDATION-A7 PHASE-1 + FOUNDATION-A2**): `PolicyAuthorizer` installed as real `AuthorizerInterface` binding (FOUNDER full-allow, SUPPORT_ACTOR read-only, TENANT permission-map, deny-by-default). `AppointmentService`, `BlockedSlotService`, `WaitlistService`, `AppointmentSeriesService` migrated from `BranchContext` to `RequestContextHolder` + canonical scoped repository API. `AppointmentRepository` (`loadVisible`, `loadForUpdate`), `BlockedSlotRepository` (`loadOwned`), `WaitlistRepository` (`loadOwned`) canonical methods installed. Both guardrail scripts expanded to Appointments domain. 79/79 verification assertions pass: `system/scripts/read-only/verify_big_04_appointments_migration_01.php`. **does not** close `AvailabilityService` (service DB usage retained — APPOINTMENTS_P2) or `AppointmentSeriesRepository` (no canonical methods yet).

- FND-TNT-26 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-15**): `InvoiceRepository::allocateNextInvoiceNumber` requires **branch-derived** org via `requireBranchDerivedOrganizationIdForInvoicePlane()` (same basis as `invoiceClause`); proof `verify_invoice_number_sequence_hotspot_readonly_01.php` in Tier A — **does not** close per-org sequence **contention/scale** (deferred **FND-PERF-03**) or residual **`PaymentRepository`** id-only F-12 surfaces. (**`InvoiceRepository::find` / `findForUpdate`**: **CLOSURE-19** / **FND-TNT-30**.) (**FND-TNT-26** label avoids collision with inventory Tier A **FND-TNT-22**–**25** script family names.)
- FND-TNT-27 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-16**): `PaymentRepository::getCompletedCashTotalsByCurrencyForRegisterSession` requires branch-derived org, joins **`register_sessions`** with **`registerSessionClause`**, and keeps **`paymentByInvoiceExistsClause`**; proof `verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php` in Tier A — **does not** close other **`PaymentRepository`** / **`InvoiceRepository`** id-only or list surfaces.
- FND-TNT-28 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-17**): `InvoiceRepository::count` calls **`requireBranchDerivedOrganizationIdForInvoicePlane()`**, keeps **`invoiceClause('i')`**, and uses **`invoiceListRequiresClientsJoinForFilters`** for conditional **`clients`** join; proof `verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php` in Tier A — **`list`** parity: **CLOSURE-18** / **FND-TNT-29**.
- FND-TNT-29 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-18**): `InvoiceRepository::list` matches **`count`** explicit branch-derived entry + **`invoiceListRequiresClientsJoinForFilters`** (subselect client names when join elided); proof `verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php` in Tier A — **`find` / `findForUpdate`**: **CLOSURE-19** / **FND-TNT-30**; **does not** close **`PaymentRepository`** id paths.
- FND-TNT-30 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-19**): `InvoiceRepository::find` and **`findForUpdate`** call **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL and keep **`invoiceClause('i')`**; proof `verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php` in Tier A — **`PaymentRepository::find` / `findForUpdate`**: **CLOSURE-20** / **FND-TNT-31**.
- FND-TNT-31 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-20**): `PaymentRepository::find` and **`findForUpdate`** call **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL and keep **`paymentByInvoiceExistsClause('p','si')`**; proof `verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php` in Tier A — **`getByInvoiceId`**: **CLOSURE-21** / **FND-TNT-32**.
- FND-TNT-32 (2026-03-30 wave / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-21**): `PaymentRepository::getByInvoiceId` calls **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL and keeps **`paymentByInvoiceExistsClause('p','si')`** + **`ORDER BY p.created_at`**; proof `verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php` in Tier A — **does not** close other **`PaymentRepository`** aggregates / existence helpers.
- Tenant audit model: strong branch-level traces, weaker organization-grade forensic framing.
- **Founder / platform privileged support-entry (impersonation-style) runtime:** `FounderSupportEntryService`, `PlatformFounderSupportEntryController`, `SupportEntryController`, `SessionAuth` support-entry state, `FounderImpersonationAuditService` — **path is live**; without proven step-up/MFA this is **`PARTIAL`** (mechanics shipped, **strong-auth bar `OPEN`** — **`PLT-MFA-01` elevated urgency**).
- **Fragmented async / job islands:** image pipeline worker + `media_jobs` claims, `runtime_execution_registry` + script heartbeats, client merge jobs, outbound dispatch `SKIP LOCKED`, memberships/marketing cron exclusivity — real **islands** exist; **not** a unified queue product — see **`OPEN`** for generalized platform.
- **Deployment “public surface” kill switches:** founder/platform controls (e.g. `PlatformFounderSecurityController` / safe-action previews) — **`PARTIAL`** implemented slice; **not** a generalized feature-flag / canary / per-tenant gradual rollout platform.
- **Handoff ZIP rule enforcement on canonical build output:** **`CLOSED`** (PLT-PKG-08 / `build-final-zip.ps1` + PHP verifier). **Arbitrary shipped/uploaded artifacts** (ad-hoc ZIPs, misconfigured CI) can still contain operator-local secrets or logs — artifact cleanliness beyond the **canonical** build path is **`PARTIAL` / `OPEN`** until a verified clean-artifact bar is owned.
- **`OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01` residual:** reports, documents, notifications, marketing, payroll, intake (and related) — **no** single matrix row proves **end-to-end** tenant/data-plane closure for all modules; treat as **`PARTIAL` / `OPEN`** residual risk, especially **reports**, **documents**, **notifications**, **intake**, alongside waves that landed later.

## REOPENED (critical integrity path)

- Multi-tenant boundary fail-closed guarantee across all repositories and services.
- Tenant lifecycle gating (suspended organization / inactive user-staff / public exposure consistency).

## OPEN (large bodies of work)

- Tenant-owned data-plane hardening (cross-module repository consistency).
- Lifecycle and suspension enforcement end-to-end for internal and public surfaces.
- `INVENTORY-TENANT-DATA-PLANE-HARDENING-01` (`PARTIAL`, waves 1–5) — Tier A includes `verify_inventory_tenant_scope_followon_wave_05_readonly_01.php` (product scoped writes, invoice-joined settlement aggregates, scoped backfill/orphan/retire/post-tree); matrix lists remaining deprecated tooling + empty-invoice-clause aggregate fallback + detach/cross-module surfaces — `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md`.
- `MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01` (`OPEN`) — see `MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-MATRIX.md`.
- New platform-scale/hardening tasks (**PLT-***) — see `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` §A.

## AUDIT-ONLY / proof gaps (must not be treated as product-closed)

- Multiple read-only OPS/audit docs in `system/docs/*-OPS.md` that report shape/truth but do not enforce runtime invariants.
- Existing CLI audits are useful observability but not CI-enforced tenant isolation proof.
- Any prior “closed” narrative lacking fail-closed runtime assertions and automated proof → downgrade to `OPEN`, `PARTIAL`, or `AUDIT-ONLY` (this matrix is stricter than informal roadmap wording).
- **Unified catalog** tail verifiers, **mixed sales** line/invoice audit waves, **inventory** read-only drilldowns — **`AUDIT-ONLY`** unless paired with a real write-path closure task.
- `OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01` — matrix execution is **`AUDIT-ONLY`** (not tenant product closure) per `OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01-MATRIX.md`.

## OPEN (platform / control-plane — not production-closed)

- **MFA / step-up authentication** — **`OPEN`**; **urgency elevated (2026-03-29):** privileged **founder support-entry** already runs in production code paths (`FounderSupportEntryService`, platform routes) — step-up protects a **real** surface, not a future-only design.
- **Mandatory Redis production baseline** — config hooks may exist; **mandatory** prod baseline + enforcement **`OPEN`** (`PLT-REDIS-01`).
- **Full shared session + sticky-session deployment truth** — session hardening wave may be `CLOSED`; multi-node/session **federation truth** **`OPEN`** (`PLT-SESS-01`).
- **Generalized async/queue control-plane** (unified DLQ, poison-job policy, cross-workload semantics) — **`OPEN`** (`PLT-Q-01`). **Do not** read as “no async”: **fragmented** job/queue islands are **`PARTIAL`** above.
- **Second `StorageProviderInterface` implementation (object/S3-compatible or equivalent)** — **missing in repo**; local filesystem provider + seam = **`CLOSED`** waves documented in `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md` (storage abstraction sections); **non-local driver** **`OPEN`** (`PLT-OBJ-01`).
- **Public API versioning control plane** — **`OPEN`** (`PLT-API-01`).
- **PSP integration / vaulted payments / auto-capture charge lifecycle** — **`OPEN`** (`PLT-PAY-01`). Membership **renewal/billing invoice** foundations remain acknowledged under historical product slices; they **do not** close payment-rail architecture.
- **Outbound SMS as operational channel** — **`OPEN`** (enqueue blocked / terminal skip patterns per channel policy; not production-complete alongside email).
- **CI / automated regression breadth** — **`OPEN`**: `.github/workflows/tenant-isolation-gate.yml` runs **PLT-REL-01 Tier A** only; dozens of read-only verifiers and smokes exist **outside** CI — do not equate “scripts exist” with “CI proves full regression surface.”
- **Named PHPUnit suite / proper test harness** — **`OPEN`** (`FND-TST-04`); current proof model is **script- and verifier-heavy**, not equivalent to a **standard** Composer/PHPUnit platform.
- **Selective DB integrity / FK closure (`PLT-DB-01`)** — **`OPEN`** as **remaining** unsafe-table / missing-contract work; schema **already** includes meaningful FKs in many areas — **not** a zero-base “add first FKs” program.
- **Full package/quota/subscription enforcement platform** — **`PLANNED` / `OPEN`** (§6 Phase 3).
- **Full provision/suspend/archive/purge lifecycle engine** — **`OPEN`** (§6 Phase 4).
- **Modular bootstrap/routes as implemented decomposition** — topology docs **`AUDIT-ONLY`**; code split **`OPEN`**.
- **Operational resilience backlog (visible gaps):** load/stress testing strategy; backup/restore/DR runbooks; DB scaling/capacity strategy; **generalized** feature flags / staged rollout / canary / per-tenant rollout (distinct from **`PARTIAL`** emergency kill switches); tracing/metrics stack; encryption at rest / key rotation; unified queue DLQ/poison-job strategy — default **`OPEN`** until owned tasks close them.
- **Verified clean shipped-artifact discipline (beyond canonical ZIP build rules)** — **`PARTIAL` / `OPEN`** (see **`PARTIAL`** row above).
