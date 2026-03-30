# FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01

**Purpose:** Active **platform** execution charter aligned to **Backbone Closure** phases. Each layer lists **code-proved facts**, risk, scale impact, target end-state, named next tasks, risk class, and acceptance proof. ZIP/repo is source of truth.

**Backbone alignment:** Phase order and freeze rules — `BACKBONE-CLOSURE-MASTER-PLAN-01.md`. **Strict status truth** — `TASK-STATE-MATRIX.md`. **Non-current work** — `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`. **Root-cause families (tenant/data-plane):** `ROOT-CAUSE-REGISTER-01.md` — execution is **root-cause-driven**; §3 tasks should cite **ROOT-** id(s) where applicable.

| Charter § (this file) | Backbone phase |
|----------------------|----------------|
| §3 Tenant repository / data-plane | **Phase 1** — Tenant boundary closure |
| §2 Jobs / scheduler / async facts | **Phase 2** — Async backbone closure ( **`PLT-Q-01`** generalizes §2 remaining OPEN) |
| §1 Session / shared-state | **Phase 4** — Production runtime closure (**`PLT-REDIS-01`**, **`PLT-SESS-01`**) |
| §4 Storage abstraction | **Phase 4** — Production runtime closure (**`PLT-OBJ-01`**) |
| §5 Observability | **Phase 4** — Production runtime closure |
| §6 Test / release discipline | **Phase 4** — Production runtime closure (**`FND-TST-04`**) |
| §7 Doc / migration hygiene | **Phase 5** — Bootstrap / portability closure |
| Founder support-entry + MFA | **Phase 3** — Privileged plane closure (**`PLT-MFA-01`**) |

**Status vocabulary:** `CLOSED` | `PARTIAL` | `OPEN` | `REOPENED` | `AUDIT-ONLY` | `PLANNED` (same as `TASK-STATE-MATRIX.md`). Companion `*-AUDIT.md` files are **`AUDIT-ONLY`** documentation of shape/truth; paired verifiers + code changes may still justify a **`CLOSED`** wave label on the implementation slice.

**Completed waves (`CLOSED` implementation slices, with OPS + where noted AUDIT companions):** `FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01` — session config/early-release/Redis rules **`CLOSED`**; **multi-node session/sticky deployment truth** remains **`OPEN`** (`PLT-SESS-01`). `FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01` (`FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md`). Tenant repository closure **waves 01–07** — runtime slices + Tier A verifiers **`CLOSED`** per §3; `FOUNDATION-TENANT-REPOSITORY-CLOSURE-01`–`07` **`*-AUDIT.md`** = **`AUDIT-ONLY`** narrative. `FOUNDATION-STORAGE-ABSTRACTION-01`–`03` — **local** `StorageProviderInterface` seam + streaming + checksum **`CLOSED`**; **second (object/S3-compatible) provider not in repo — `OPEN`**. `FOUNDATION-OBSERVABILITY-AND-ALERTING-01` wave 01 (health collector baseline — **not** full tracing/metrics stack). **Founder support-entry** mechanics (**`PARTIAL`**): `FounderSupportEntryService`, platform routes, `FounderImpersonationAuditService` — **live**; **MFA/step-up `OPEN`** (**`PLT-MFA-01` elevated**).

---

## 1. Session / shared-state / runtime locking risk

### Current facts (code)

- Native PHP sessions: `system/core/auth/SessionAuth.php` calls `session_start()` in constructor via `startSession()`; cookie params from `system/config/session.php`.
- Save handler / path: `system/core/Runtime/Session/SessionBackendConfigurator.php` applied from `SessionAuth::configureSession()` — `SESSION_DRIVER` `files` (default, `system/storage/sessions`) or `redis` (phpredis `session.save_handler=redis`).
- Production fail-fast: `APP_ENV=production` + `redis` driver requires ext-redis + Redis URL; non-production falls back to files with `error_log` (`spa_session_*_fallback_v1`).
- Early lock release: `system/core/middleware/SessionEarlyReleaseMiddleware.php` + route option `session_early_release` (see `system/core/router/Router.php`, `system/core/router/Dispatcher.php`, `system/core/app/Application.php`); first consumer: `GET /clients/merge/job-status` in `system/routes/web/register_clients.php`.
- Global middleware may write session before auth: `system/core/middleware/BranchContextMiddleware.php` sets `$_SESSION['branch_id']` for tenant users; stack order documented in `system/core/router/Dispatcher.php`.

### Exact risk

- Default file sessions on a single host do not scale horizontally; session file locking serializes concurrent requests per session id.
- Without shared storage, multi-node deploys log users out or split state.
- Long-held session locks amplify tail latency under concurrent tabs/API polling.

### Why it matters at ~1000 active users

- Many concurrent sessions × polling endpoints × lock duration → visible slowdowns and timeouts; multi-node without Redis (or equivalent) is a production outage class.

### Target end-state

- Production uses explicit Redis-backed sessions (or equivalent shared store), verified by automation; safe file fallback for local dev; selective early `session_write_close()` on JSON read-only routes where session is not mutated afterward.

### Next implementation task (this layer)

- **Wave 01:** **`FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01`** — **`CLOSED`** (session backend config, early release, production Redis fail-fast for `redis` driver).
- **Still `OPEN` (not closed by wave 01):** mandatory **production** Redis baseline as org policy (`PLT-REDIS-01`); **shared session store + sticky-session deployment truth** for multi-node (`PLT-SESS-01`).

### Classification

- **HIGH** (multi-node session coherence still **`OPEN`**; lock contention mitigated for configured routes only).

### Acceptance proof

- `php system/scripts/read-only/verify_session_runtime_configuration_readonly_01.php`
- `php system/scripts/read-only/verify_session_ini_after_bootstrap_readonly_01.php`
- `system/docs/FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01-OPS.md`

---

## 2. Jobs / worker / scheduler reliability risk

### Current facts (code) — execution model (audit)

**Image pipeline (`workers/image-pipeline/src/processor.mjs`, `worker.mjs`)**

- **Claim:** `claimNextJob` — transaction + `SELECT … FOR UPDATE SKIP LOCKED` on oldest eligible `media_jobs` (`process_photo_variants_v1`), then `UPDATE` job/asset to `processing` and `locked_at = NOW()`.
- **Duplicate concurrent workers:** Row-level locking prevents double claim of the same job; a second worker skips locked rows.
- **Process dies mid-run:** `reclaimStaleLocks` resets `processing` jobs (and matching assets) older than `IMAGE_JOB_STALE_LOCK_MINUTES` (default 30) to `pending` with `[stale_lock_reclaimed …]` in `error_message`. **Not automatic** for filesystem/orphan variants — business-critical promote failures are logged as `post_commit_promote_failed` (manual ops may be required).
- **Stale detection:** DB uses `locked_at` age; `failExhaustedPendingJobs` / `failDeletedMarketingLibraryJobs` cap poison-queue cases.
- **Visibility (post–FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01):** `recordWorkerHeartbeat` / `finalizeWorkerSession` in `workers/image-pipeline/src/executionRegistry.mjs` write `runtime_execution_registry` key `worker:image_pipeline`; read-only report `report_image_pipeline_runtime_health_readonly_01.php` exposes counts + `health_signal`.

**Outbound dispatch (`system/modules/notifications/services/OutboundNotificationDispatchService.php`, script `system/scripts/outbound_notifications_dispatch.php`)**

- **Claim:** `claimPendingBatchForDispatch` uses `SKIP LOCKED` (migration 082+); stale `processing` rows reclaimed via `reclaimStaleProcessingClaims` before batch.
- **Overlapping dispatchers:** Safe at row level; script adds **flock** on this host + **parallel-safe** registry timestamps (`recordParallelBatchStart` / `completeParallelBatch*`) so operators see last run without falsely blocking multi-host dispatch.
- **Dies mid-send:** Stale reclaim returns rows to pending for retry per outbound config; attempts logged in attempt repository.
- **SMS:** not operationally equivalent to email — channel policy / dispatch treats SMS as **non-operational** from PHP enqueue; **`OPEN`** for real SMS transport parity (`TASK-STATE-MATRIX.md`).

**Memberships cron (`system/scripts/memberships_cron.php`)**

- **Concurrency:** `flock` on `storage/locks/memberships_cron.lock` (exit 11); **plus** `RuntimeExecutionRegistry::beginExclusiveRun` for cross-host stale recovery.
- **Stale run:** If a previous holder never completed, after configured minutes the next run clears the active slot and records `stale_exclusive_run_cleared…` in `last_error_summary` (honest, not silent).
- **Heartbeats:** `heartbeatExclusive` between major steps.

**Marketing automations (`system/scripts/marketing_automations_execute.php`)**

- **Concurrency:** `flock` per `--key` + exclusive registry row `php:marketing_automations:{key}` with same stale semantics as memberships.

**Operator gaps (remaining)**

- No in-repo supervisor; **SIGKILL** on Node worker leaves heartbeat stale until restart — verifier reports `backlog_no_recent_worker_heartbeat` when pending jobs exist.
- FIFO backlog behavior for media (large `id` waits) unchanged — see `workers/image-pipeline/README.md`.

### Exact risk

- Cron/worker absence = stuck notifications, memberships, automations, image variants; process manager still required outside repo.
- Without execution ledger, operators cannot distinguish “never scheduled” vs “failed silently” vs “running now”.

### Why it matters at scale

- Queue depth and overlapping crons amplify tail risk; cross-host duplicate cron without row-safe claims could double-load DB (outbound mitigated by `SKIP LOCKED`; memberships/marketing mitigated by registry + flock).

### Target end-state

- Observable queue metrics, stale-row reclamation guarantees, runbooks, and deployment-level scheduling contracts (systemd/K8s cron) aligned with code assumptions.

### Next implementation task (this layer) — **`CLOSED` for wave 01**

- **FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01** — **`CLOSED`** (`runtime_execution_registry`, script wrappers, worker heartbeat, verifiers, `system/docs/FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md`). **Still `OPEN`:** supervisor contracts, **unified DLQ / poison-job** platform beyond per-workload stale reclaim, **generalized queue control-plane** (`PLT-Q-01`). **Fragmented** async/job islands in this charter = **`PARTIAL`** — **not** “queue code is absent.”

### Classification

- **HIGH**

### Acceptance proof

- `php system/scripts/read-only/verify_runtime_jobs_execution_registry_readonly_01.php`
- `php system/scripts/read-only/report_image_pipeline_runtime_health_readonly_01.php`
- `system/docs/FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md`
- `system/scripts/read-only/report_operational_readiness_summary_readonly_01.php` (includes the above when run)

---

## 3. Tenant repository / data-plane closure risk

### Current facts (code)

- HTTP boundary: `system/core/middleware/TenantProtectedRouteMiddleware.php`, `system/core/Tenant/TenantRuntimeContextEnforcer.php`, `system/core/Organization/OrganizationContext.php` / resolver (see `OrganizationContextMiddleware` in `system/core/router/Dispatcher.php`).
- Inventory charter: `system/docs/TENANT-SAFETY-INVENTORY-CHARTER-01.md` tracks closed slices (tenant-closure **`verify_tenant_closure_wave_fnd_tnt_*`** series through **FND-TNT-21**, plus **FND-TNT-26**–**32** sales invoice/payment index / sequence / register aggregate / payment read verifiers) and remaining hotspots; rows tag **ROOT-** families via `ROOT-CAUSE-REGISTER-01.md`.
- Execution audits: `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-01-AUDIT.md` (Tier A + historical), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-02-AUDIT.md` (Tier B membership sale reads), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-03-AUDIT.md` (refund-review + adjacent catalog), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-04-AUDIT.md` (membership definition list/find + billing cycle invoice-plane reads), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-05-AUDIT.md` (client membership id-read/lock + renewal branch pin), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-06-AUDIT.md` (client membership catalog removal + issuance overlap + cycle period read), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-07-AUDIT.md` (client membership scoped UPDATE + GlobalOps renewal scan), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-08-AUDIT.md` (expiry pass cron listing + tenant-scoped lock), `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-09-AUDIT.md` (anonymous public commerce invoice read branch pin).
- Additional slice memos: `FOUNDATION-TENANT-REPOSITORY-CLOSURE-10-AUDIT.md` through `FOUNDATION-TENANT-REPOSITORY-CLOSURE-21-AUDIT.md` (public client locks, appointments overlap scans, invoice sequence, register aggregate, invoice index count/list, invoice + payment read paths, etc.); titles inside each file.
- Stale audit: `system/docs/ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md` predates current organization layer (see contradictions section in parent report).
- **Wave 01 (2026-03-29):** `PublicCommercePurchaseRepository` mutating SQL + `MembershipSaleRepository::update` use `OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause` (fail-closed tenant plane).
- **Wave 02 (2026-03-29):** `MembershipSaleRepository::find` / `findForUpdate` / `findBlockingOpenInitialSale` + `MembershipSaleService` invoice-branch locks (**FND-TNT-08**).
- **Wave 03 (2026-03-29):** `MembershipSaleRepository::listRefundReview` + `MembershipBillingCycleRepository::listRefundReviewQueue` (resolved-org binding); `MembershipDefinitionRepository::listActiveForInvoiceBranch` org EXISTS (**FND-TNT-09**).
- **Wave 04 (2026-03-29):** `MembershipDefinitionRepository` branch-owned find/list/count + `findForClientMembershipContext`; `MembershipBillingCycleRepository` invoice-plane find/findForUpdate + invoice-correlated helpers; billing + sale + assign services (**FND-TNT-10**).
- **Wave 05 (2026-03-29):** `ClientMembershipRepository` tenant-scoped find/findForUpdate/lock paths + `listDueClientMembershipIds` branch pin; `MembershipBillingService` / `MembershipService` / `MembershipLifecycleService` / `MembershipSaleService` pass-through (**FND-TNT-11**).
- **Wave 06 (2026-03-29):** `ClientMembershipRepository` unscoped **`list` / `count` removed**; **`findBlockingIssuanceRowInTenantScope`**; **`MembershipBillingCycleRepository::findByMembershipAndPeriod`** org-bound when branch pin resolves (**FND-TNT-12**).
- **Wave 07 (2026-03-29):** `ClientMembershipRepository` **`updateInTenantScope`** / **`updateRepairOrUnscopedById`**; **`listActiveNonExpiredForRenewalScanGlobalOps`**; **`MembershipBillingService`** + **`MembershipLifecycleService`** call-site alignment (**FND-TNT-13**).
- **Wave 08 (2026-03-29):** **`ClientMembershipRepository::listExpiryTerminalCandidatesForGlobalCron`** + **`OrganizationRepositoryScope::clientMembershipRowAnchoredToLiveOrganizationSql`**; **`MembershipLifecycleService::runExpiryPass`** uses anchored listing + **`findForUpdateInTenantScope`** only (**FND-TNT-14** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-08**).
- **Wave 09 (2026-03-29):** **`InvoiceRepository::findForPublicCommerceCorrelatedBranch`** + **`PublicCommercePurchaseRepository::findBranchIdPinByInvoiceId`**; anonymous **`PublicCommerceService`** paths use branch-pin invoice load; **`PublicCommerceFulfillmentReconciler`** / recovery load invoice via tenant **`find`** or **`AccessDeniedException`** fallback to pin + correlated read (**FND-TNT-15** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-09**).
- **Wave 10 (2026-03-29):** **`ClientRepository::lockActiveByEmailBranch`** — positive branch pin + **`publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause`** (**FND-TNT-16** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-10**); **ROOT-03** + **ROOT-01** reduction.
- **Wave 11 (2026-03-29):** **`ClientRepository::lockActiveByPhoneDigitsBranch`** + **`findActiveClientIdByPhoneDigitsExcluding`** — same contract as wave 10 (**FND-TNT-17** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-11**); **ROOT-03**, **ROOT-05**, **ROOT-01**.
- **Wave 12 (2026-03-29):** **`AppointmentRepository::lockRoomRowForConflictCheck`** — tenant-scoped **`rooms`** **`FOR UPDATE`** (**FND-TNT-18** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-12**); **ROOT-01**, **ROOT-05**, **ROOT-02** (null-branch room rows excluded).
- **Wave 13 (2026-03-29):** **`AppointmentRepository::hasRoomConflict`** — branch-scoped path **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`** (**FND-TNT-19** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-13**); **ROOT-01**, **ROOT-05**, **ROOT-02** (null **`$branchId`** arm explicit).
- **Wave 14 (2026-03-29):** **`AppointmentRepository::hasStaffConflict`** — **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`** (**FND-TNT-21** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-14**); **ROOT-01**, **ROOT-05**, **ROOT-02**.
- **Wave 15 (2026-03-30):** **`InvoiceRepository::allocateNextInvoiceNumber`** — branch-derived org basis (**FND-TNT-26** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-15**).
- **Wave 16 (2026-03-30):** **`PaymentRepository::getCompletedCashTotalsByCurrencyForRegisterSession`** — branch-derived guard + **`register_sessions`** JOIN + **`registerSessionClause`** + **`paymentByInvoiceExistsClause`** (**FND-TNT-27** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-16**).
- **Wave 17 (2026-03-30):** **`InvoiceRepository::count`** — **`requireBranchDerivedOrganizationIdForInvoicePlane()`** + **`invoiceClause('i')`** + conditional **`clients`** join (**FND-TNT-28** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-17**).
- **Wave 18 (2026-03-30):** **`InvoiceRepository::list`** — parity with **wave 17** + correlated subselects for client display columns when join elided (**FND-TNT-29** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-18**).
- **Wave 19 (2026-03-30):** **`InvoiceRepository::find`** / **`findForUpdate`** — explicit **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before read/lock SQL + **`invoiceClause('i')`** (**FND-TNT-30** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-19**).
- **Wave 20 (2026-03-30):** **`PaymentRepository::find`** / **`findForUpdate`** — explicit **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before read/lock SQL + **`paymentByInvoiceExistsClause('p','si')`** (**FND-TNT-31** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-20**).
- **Wave 21 (2026-03-30):** **`PaymentRepository::getByInvoiceId`** — explicit **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before list SQL + **`paymentByInvoiceExistsClause('p','si')`** (**FND-TNT-32** / **FOUNDATION-TENANT-REPOSITORY-CLOSURE-21**).

### Exact risk

- Any repository `find($id)` / `update($id)` without intrinsic org/branch predicate is ID-guessing surface if called from wrong context (**ROOT-01**).
- Drift between docs and code creates false confidence.

### Why it matters at scale

- Higher traffic increases exploit attempts and accidental cross-tenant reads/writes; harder to detect without closure proofs.

### Target end-state

- Mechanical closure on remaining “risky” repositories per charter; Tier-A verifiers extended; doc retired or rewritten to match code.

### Next implementation task (this layer) — **partial (waves 01–21 done)**

- Next **`TENANT-SAFETY-INVENTORY-CHARTER-01.md`** “Highest-risk areas” row (e.g. **`PaymentRepository`** aggregate/existence helpers) — **one hotspot** per PLT-TNT-01 slice. **CLOSURE-18**–**21** closed: includes **`verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php`** (Tier A). Universal tenant bar remains **`REOPENED`** in `TASK-STATE-MATRIX.md`. Root families: **`ROOT-CAUSE-REGISTER-01.md`**.

### Classification

- **HIGH**

### Acceptance proof

- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_13_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_14_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_15_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_18_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_19_readonly_01.php`
- `php system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php`
- `php system/scripts/read-only/verify_invoice_number_sequence_hotspot_readonly_01.php` (**FND-TNT-26** / **CLOSURE-15** invoice sequence branch-derived basis)
- `php system/scripts/read-only/verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php` (**FND-TNT-27** / **CLOSURE-16** register cash aggregate)
- `php system/scripts/read-only/verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php` (**FND-TNT-28** / **CLOSURE-17** `InvoiceRepository::count`)
- `php system/scripts/read-only/verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php` (**FND-TNT-29** / **CLOSURE-18** `InvoiceRepository::list`)
- `php system/scripts/read-only/verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php` (**FND-TNT-30** / **CLOSURE-19** `InvoiceRepository::find` / `findForUpdate`)
- `php system/scripts/read-only/verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php` (**FND-TNT-31** / **CLOSURE-20** `PaymentRepository::find` / `findForUpdate`)
- `php system/scripts/read-only/verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php` (**FND-TNT-32** / **CLOSURE-21** `PaymentRepository::getByInvoiceId`)
- `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-01-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-02-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-03-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-04-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-05-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-06-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-07-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-08-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-09-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-10-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-11-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-12-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-13-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-14-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-15-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-16-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-17-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-18-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-19-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-20-AUDIT.md` + `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-21-AUDIT.md`
- `run_mandatory_tenant_isolation_proof_release_gate_01.php` Tier A includes tenant-closure **`verify_tenant_closure_wave_fnd_tnt_07`** through **`19`** plus **`21`** (series **FND-TNT-07–19**, **FND-TNT-21**; **FND-TNT-20** file number skipped vs inventory **FND-TNT-20** label), plus **`verify_invoice_number_sequence_hotspot_readonly_01.php`** (**FND-TNT-26**), plus **`verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php`** (**FND-TNT-27**), plus **`verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php`** (**FND-TNT-28**), plus **`verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php`** (**FND-TNT-29**), plus **`verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php`** (**FND-TNT-30**), plus **`verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php`** (**FND-TNT-31**), plus **`verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php`** (**FND-TNT-32**)

---

## 4. Storage abstraction / multi-node readiness risk

### Current facts (code) — waves 01–03 (2026-03-29)

- **Provider:** `Core\Storage\Contracts\StorageProviderInterface` + `LocalFilesystemStorageProvider` (`system/core/Storage/`). Factory **rejects** `storage.driver` other than `local` until a second implementation exists.
- **Config:** `system/config/storage.php` — `STORAGE_DRIVER`, optional `STORAGE_LOCAL_SYSTEM_PATH` (same physical `system/` root as `SYSTEM_PATH` when set).
- **Wave 01 callers:** `MediaAssetUploadService` (quarantine), `DocumentService` (documents volume persist), `MarketingGiftCardTemplateService` (purge quarantine / processed / legacy).
- **Wave 02 serving:** `Dispatcher::tryServePublicProcessedMedia()` uses `StorageKey::publicMedia` + `readStreamToOutput`. `DocumentService::deliverAuthenticatedDownload()` uses `readStreamToOutput`.
- **Wave 03 checksum / validation:** `supportsContentHashing`, `computeSha256HexForKey` (stream `hash_update_stream`); `DocumentService` / `MediaAssetUploadService` use provider for SHA-256 + size; `MediaImageSignatureValidator::validateFromStream` (`finfo_buffer`); marketing variant purge uses `isReadableFile` not `localFilesystemPathFor`.
- **Contract hooks:** prior wave 02 hooks + **wave 03** hashing capability.
- **Worker parity:** `workers/image-pipeline/src/processor.mjs` `resolveSystemRoot()` accepts `STORAGE_LOCAL_SYSTEM_PATH` when `MEDIA_SYSTEM_ROOT` is unset.
- **Tier B backlog:** `getimagesize($tmpPath)` in media upload; second storage driver + CDN/signed URLs.

### Exact risk

- Waves 01–03 **do not** ship a non-local driver or CDN signing. Multi-node still needs shared disk or a future S3-compatible provider + signed/public URL strategy for processed media where appropriate.

### Why it matters at scale

- Horizontal scaling breaks uploads/downloads until a non-local driver and CDN/signing or app-proxy reads exist; worker and PHP must keep a single canonical root until then.

### Target end-state

- Additional `StorageProviderInterface` implementation (S3-compatible or NFS-mounted local with explicit contract); optional `resolvePublicUrl` for edge delivery.

### Next implementation task name

- **FOUNDATION-STORAGE-ABSTRACTION-04** (non-local driver and/or CDN/signed URL alignment). Tenant repository follow-on after **FOUNDATION-TENANT-REPOSITORY-CLOSURE-06** is tracked in §3 (membership `update`/cron scan deferrals), not as a duplicate CLOSURE-04 remainder.

### Classification

- **HIGH** for true multi-node; **MEDIUM** for single-node (waves 01–03 reduce fragile path coupling).

### Acceptance proof

- `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_01_readonly_01.php`
- `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_02_readonly_01.php`
- `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_03_readonly_01.php`
- `system/docs/FOUNDATION-STORAGE-ABSTRACTION-01-OPS.md` + `system/docs/FOUNDATION-STORAGE-ABSTRACTION-02-OPS.md` + `system/docs/FOUNDATION-STORAGE-ABSTRACTION-03-OPS.md`

---

## 5. Observability / logging / ops-readiness risk

### Current facts (code) — wave 01 (2026-03-29)

- **Structured logging:** `system/core/app/StructuredLogger.php` (JSON lines via `error_log`, correlation id).
- **Consolidated health:** `Core\Observability\BackendHealthCollector` + `report_backend_health_critical_readonly_01.php` — **exit 0/1/2** (`healthy` / `degraded` / `failed`), stable `BackendHealthReasonCodes`, optional single `observability.backend_health.issue_v1` log when not healthy.
- **Probed layers:** session (redis rules aligned with session verifier), `StorageProviderInterface` runtime, `runtime_execution_registry` (table + stale exclusive + recent failure), image pipeline (stale processing + backlog vs worker heartbeat), shared cache (`redis_effective` vs `REDIS_URL`, production fail-closed).
- **Readiness bundle:** `report_operational_readiness_summary_readonly_01.php` now runs the consolidated report after the image pipeline line report.
- **Remaining:** tenant isolation release gate stays **manual/CI** (`run_mandatory_tenant_isolation_proof_release_gate_01.php`); no in-repo log sink shipping.

### Exact risk

- Per-process cache metrics are still not long-window SLIs without an external sink; HTTP path latency histograms not in this wave.

### Why it matters at scale

- Cron/supervisor can now fail on **degraded** backlog or **failed** production Redis/session/storage schema gaps before user-visible outage.

### Target end-state

- External log/metrics sink + optional **FOUNDATION-OBSERVABILITY-AND-ALERTING-02** (tenant gate hook, RED-style counters, request-path probes).

### Next implementation task name

- **FOUNDATION-OBSERVABILITY-AND-ALERTING-02**

### Classification

- **MEDIUM** (wave 01); elevates with multi-node + central observability stack.

### Acceptance proof

- `php system/scripts/read-only/verify_foundation_observability_backend_health_readonly_01.php`
- `php system/scripts/read-only/report_backend_health_critical_readonly_01.php`
- `system/docs/FOUNDATION-OBSERVABILITY-AND-ALERTING-01-OPS.md`

---

## 6. Test / release discipline gap

### Current facts (code)

- Repository root: no standard `composer.json` / PHPUnit baseline at root (per mission statement); quality gates rely on PHP scripts under `system/scripts/read-only/` and release gates (e.g. tenant isolation gate referenced in docs).
- **CI:** `.github/workflows/tenant-isolation-gate.yml` invokes **PLT-REL-01 Tier A** only — the broader verifier bank is **not** continuously executed in GitHub Actions (`TASK-STATE-MATRIX.md`).

### Exact risk

- Regressions slip without automated unit/integration layer; refactors lack fast feedback.

### Why it matters at scale

- More contributors and features increase regression rate; manual verification does not scale.

### Target end-state

- Minimal Composer + PHPUnit (or Pest) baseline scoped to core libraries (session configurator, URL builder, tenant guards) + CI hook.

### Next implementation task name

- **FOUNDATION-TEST-AND-RELEASE-DISCIPLINE-01**

### Classification

- **MEDIUM**

### Acceptance proof

- `composer test` (or equivalent) exits 0 in clean checkout; CI config optional but preferred.

---

## 7. Doc-truth drift / migration hygiene gap

### Current facts (code)

- **Foreign keys:** many tables already carry FK constraints from historical migrations; **`PLT-DB-01`** = **selective** remaining integrity work (unsafe tables, missing contracts), **not** a zero-base FK program.
- Duplicate ordinal migration filenames: `system/data/migrations/112_clients_contact_address_foundation.sql` and `system/data/migrations/112_invoice_number_sequence_hotspot_documentation.sql` — both use prefix `112_` (tooling/order ambiguity risk).
- Tenant charter: `system/docs/TENANT-SAFETY-INVENTORY-CHARTER-01.md` (current-ish).
- Stale tenant scope audit: `system/docs/ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md` — asserts no organization enforcement; code now includes `OrganizationContext` and related middleware.

### Exact risk

- Operators may apply migrations in wrong order or duplicate stamps; engineers trust outdated audits.

### Why it matters at scale

- Migration incidents are full-site outages; wrong doc leads to wrong security review conclusions.

### Target end-state

- Unique migration ordinals; automated check for duplicates; supersede or archive stale audits with explicit “replaced by” pointers.

### Next implementation task name

- **FOUNDATION-DOC-TRUTH-AND-MIGRATION-HYGIENE-01**

### Classification

- **MEDIUM** (migration collision **HIGH** if deployment uses naive lexical ordering only).

### Acceptance proof

- Read-only verifier fails on duplicate ordinal prefixes; doc header added linking to current truth map.

---

## Prioritized backlog (remaining) — ordered by backbone phase

Backbone phases — `BACKBONE-CLOSURE-MASTER-PLAN-01.md`. Until **Phase 1** closes, do not prioritize items below **Phase 2** for implementation.

**Phase 1 — Tenant boundary (active now)**  
1. **`PLT-TNT-01` / FOUNDATION-TENANT-REPOSITORY-CLOSURE-10+** — **HIGH** — §3 mechanical continuation (next inventory hotspot after **CLOSURE-09** / **FND-TNT-15**); universal bar **`REOPENED`** in `TASK-STATE-MATRIX.md`.  
2. **`PLT-LC-01`** — lifecycle + suspension end-to-end (**REOPENED** companion).  
3. **`FND-TNT-05` / `FND-TNT-06`** — remaining org predicates and read paths per matrix/checkpoint.  
4. **`INVENTORY-TENANT-DATA-PLANE-HARDENING-01`** — matrix-driven remainder (**PARTIAL**).  
5. **`MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01`** — matrix-driven (**OPEN** in matrix).  
6. **`PLT-DB-01`** — only slices **blocking** Phase 1 closure (selective integrity).

**Phase 2 — Async backbone**  
7. **`PLT-Q-01`** — unified queue/DLQ/async control-plane (§2 remaining **OPEN**).

**Phase 3 — Privileged plane**  
8. **`PLT-MFA-01`** — MFA/step-up (**elevated:** support-entry **live**).

**Phase 4 — Production runtime**  
9. **FOUNDATION-STORAGE-ABSTRACTION-04** / **`PLT-OBJ-01`** — non-local provider, CDN/signed URLs.  
10. **FOUNDATION-OBSERVABILITY-AND-ALERTING-02** — sink, metrics/tracing depth.  
11. **FOUNDATION-TEST-AND-RELEASE-DISCIPLINE-01** / **`FND-TST-04`**.  
12. **`PLT-REDIS-01`**, **`PLT-SESS-01`** (with §1 wave 01 **CLOSED** context).  
13. **`PLT-API-01`**, **`PLT-PAY-01`**, CI breadth, artifact hygiene beyond canonical ZIP, default **OPEN** resilience rows in matrix.

**Phase 5 — Bootstrap / portability**  
14. **FOUNDATION-DOC-TRUTH-AND-MIGRATION-HYGIENE-01** — §7.  
15. **PH1-BOOT-01 / PH1-ROUTE-01** — modular bootstrap/routes per historical §6.2 intent (IDs per deferred registry / reconciliation doc).

**Deferred (not backbone-active):** **`FND-PERF-03`**, Booker §5.C, founder ops polish, settings expansion — `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`.

**`CLOSED` waves (this charter):** `FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01` (wave 01 only — see §1), `FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01` (wave 01), tenant repository closure **implementation waves 01–09** + listed verifiers, `FOUNDATION-STORAGE-ABSTRACTION-01`–`03`, `FOUNDATION-OBSERVABILITY-AND-ALERTING-01` wave 01.

---

## Next best task (single — Phase 1 only)

**`FOUNDATION-TENANT-REPOSITORY-CLOSURE-10`** (next **`PLT-TNT-01`** mechanical slice): pick the next **`TENANT-SAFETY-INVENTORY-CHARTER-01.md`** highest-risk row (client + appointment core, or F-12 slice) after **CLOSURE-09** (**public commerce** branch-pin invoice read + **`FND-TNT-15`**). **`PLT-MFA-01`** remains **Phase 3** per master plan.
