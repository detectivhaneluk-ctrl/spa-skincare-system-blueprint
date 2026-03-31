# Scale Wave Execution Charter — 01

**Date:** 2026-03-31  
**Status:** WAVE-01 DONE · WAVE-02 DONE · WAVE-03 DONE · WAVE-04 DONE · WAVE-05 DONE · WAVE-06 DONE  
**Authority:** Architect verdict (2026-03-31 scalability & load assessment).  
**Relationship to FOUNDATION-A series:** Foundation A1–A7 established the tenant/auth/service kernel. This charter launches on top of that kernel to address the **runtime, infrastructure, and scale** layer. These waves do **not** reopen or contradict Foundation work — they build on it.  
**Active queue:** This file. **No wave is LIVE** until explicitly promoted in the “Next gate” section below.  
**Deferred registry:** `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` (items parked per this charter).  
**Full status inventory:** `TASK-STATE-MATRIX.md` (evidence rows; scale-campaign truth for waves **WAVE-01–WAVE-06** is **this charter**, not informal matrix rows).

---

## CURRENT CANONICAL HARDENING STATUS (single source of truth)

| Wave | Runtime state | Proof that closed the wave |
|------|----------------|---------------------------|
| WAVE-01 | **CLOSED** — production runtime foundation (Redis mandatory path, sessions, distributed lock, `ProductionRuntimeGuard`, shared cache contract) | `system/scripts/read-only/verify_wave01_production_runtime_foundation_01.php` |
| WAVE-02 | **CLOSED** — queue throughput (`SKIP LOCKED`, stale reclaim off hot poll, metrics) | `system/scripts/read-only/verify_wave02_queue_throughput_hardening_01.php` |
| WAVE-03 | **CLOSED** — ProxySQL package, observability hooks, slow-query / latency foundations | `system/scripts/read-only/verify_wave03_scale_readiness_observability_01.php` |
| WAVE-04 | **CLOSED** — safe scale ops (DDL policy, audit archival, booking rate limits, shard-readiness guardrail) | Commit `d167acf` family + policy docs |
| WAVE-05 | **CLOSED** — `RequestLatencyMiddleware` + `PublicBookingRateLimitMiddleware` wired into global pipeline | `system/scripts/read-only/verify_wave05_live_enforcement_foundations_01.php` |
| WAVE-06 | **CLOSED** — hot-path shared cache for permissions + day-calendar appointment list; invalidation on RBAC/staff-group and appointment/blocked-slot mutations; booking write path uncached | `system/scripts/read-only/verify_wave06_hot_path_cache_effectiveness_01.php` |

**Code vs paperwork rule:** If this table disagrees with an older paragraph elsewhere in the repo, **this table wins** unless a new commit updates both.

---

## NEXT GATE TO OPEN NEW WAVE

- **WAVE-06 is CLOSED** — do not reopen without a new charter amendment.
- **WAVE-07 is NOT OPEN.** Next session must **choose one** candidate below and promote it explicitly (proof script + charter row) before implementation.
- **Recommended next candidate:** **WAVE-07 — READ/WRITE ROUTING + PROXYSQL RUNTIME PROOF** — WAVE-03 delivered artifacts; proving **safe** read/write split in runtime (no correctness regression) is the natural scale step before sharding hotspots. Alternative: **WAVE-07 — HIGH-RISK SHARD-READINESS HOTSPOTS** — only if read/write routing is deferred; addresses `organization_id` hotspots under load. **Pick one** to avoid parallel “wave 7” drift.

---

## Why this charter exists

The Foundation A1–A7 series hardened tenant isolation, authorization, and the request kernel.  
The architect verdict (2026-03-31) identified the **next order of breakage at 1000+ salons**:

| Failure mode | Severity | Root |
|---|---|---|
| `NoopSharedCache` as silent production fallback | CRITICAL | Missing startup enforcement |
| File-based PHP sessions | CRITICAL | Blocks horizontal scaling |
| MySQL `GET_LOCK()` in `WaitlistService` | HIGH | Server-scoped, fails in HA/multi-server |
| DB-backed queue `SELECT … FOR UPDATE` without `SKIP LOCKED` | HIGH | Worker serialization under concurrency |
| `reclaimStaleProcessingLocked()` in hot polling path | HIGH | Locks shared on every worker cycle |
| No ProxySQL / read-write split | MEDIUM | Single DB endpoint saturates |
| No query latency / queue depth observability | MEDIUM | Blind to scale degradation |

These are in order of production urgency. The waves executed in order **WAVE-01 → WAVE-06**.

---

## Anti-drift rules

- **At most one LIVE wave** at a time; **currently none** (all listed waves DONE).
- No UI polish, product feature expansion, or marketing automation within these waves.
- No fake done. Every wave closes with a proof script that is machine-runnable.
- Booking transaction correctness must remain intact. Any read-path change must not weaken final in-transaction revalidation.
- Session/cache/lock changes must be fail-closed, not fail-open.
- If external infra (Redis, ProxySQL) cannot be literally deployed from the repo, create deploy-ready artifacts: env contract, sample config, ops runbook, readiness checks, failure modes, health scripts.

---

## WAVE-01 — PRODUCTION RUNTIME FOUNDATION

**Status:** DONE (2026-03-31)  
**Scope:**

| ID | Deliverable | Proof |
|----|-------------|-------|
| W1-A | Redis mandatory in production; `NoopSharedCache` blocked as production runtime path | Startup guard fails with 503 if `APP_ENV=production` and Redis unavailable |
| W1-B | Redis session handler — multi-server session readiness | Sessions stored in Redis (`{prefix}:sess:{id}`) when Redis available; production fails if not |
| W1-C | Replace `WaitlistService` MySQL `GET_LOCK()` with `DistributedLockInterface` (Redis primary, MySQL fallback for non-production) | `GET_LOCK` calls removed from hot paths |
| W1-D | `ProductionRuntimeGuard` — formalized startup failure on missing/broken Redis config | Guard called at bootstrap eager-resolution; refuses HTTP with 503+JSON in production |
| W1-E | Cache key conventions, invalidation contract, ops notes | `WAVE-01-PRODUCTION-RUNTIME-FOUNDATION-OPS.md` |

**Entry condition:** Foundation A1–A7 CLOSED (confirmed). CI green on main.  
**Exit condition:** All of W1-A through W1-E delivered with proof script `verify_wave01_production_runtime_foundation_01.php` passing.

**Must not touch:**
- Booking correctness / `AppointmentService` write paths
- Sales / invoice / payment service layer
- Tenant isolation kernel (A1–A7 contracts)

---

## WAVE-02 — QUEUE THROUGHPUT HARDENING

**Status:** DONE (2026-03-31)

**Scope:**

| ID | Deliverable |
|----|-------------|
| W2-A | `FOR UPDATE SKIP LOCKED` in `RuntimeAsyncJobRepository::reserveNext()` |
| W2-B | `reclaimStaleProcessingLocked()` removed from hot polling cycle |
| W2-C | Standalone stale-reclaim cron/command (runs on schedule, not per-poll) |
| W2-D | Queue health metrics / logging / truth surface |
| W2-E | Durability preserved — job correctness and lock safety maintained |

**Entry condition:** WAVE-01 proof script passes.

---

## WAVE-03 — SCALE READINESS + OBSERVABILITY

**Status:** DONE (2026-03-31)

**Scope:**

| ID | Deliverable |
|----|-------------|
| W3-A | ProxySQL / read-write split deployment package (env contract, config examples, ops docs, health checks) |
| W3-B | Read/write routing abstraction (safe, no correctness regression) |
| W3-C | Endpoint latency / queue depth / connection saturation observability foundations |
| W3-D | Tenant-aware slow query logging / tracing correlation |

**Entry condition:** WAVE-02 proof passes.

---

## WAVE-04 — SAFE SCALE OPERATIONS

**Status:** DONE (2026-03-31)  

**Scope:**

| ID | Deliverable |
|----|-------------|
| W4-A | Online-DDL migration policy and wrapper path for large tables |
| W4-B | Audit log archival / retention foundation |
| W4-C | Rate limiting foundation for auth / booking sensitive endpoints |
| W4-D | Shard-readiness guardrails: `organization_id` enforcement audit + documentation |

**Entry condition:** WAVE-03 proof passes.

---

## WAVE-05 — LIVE ENFORCEMENT FOUNDATIONS

**Status:** DONE (2026-03-31)

**Why this wave exists:**  
WAVE-01 through WAVE-04 built the foundations. WAVE-05 wires two foundations that were created but left dead (not connected to the runtime pipeline):

| Foundation | Previous gap | Resolution |
|---|---|---|
| `RequestLatencyMiddleware` | Registered in DI but not in global middleware pipeline | Wired as first entry in `Dispatcher::$globalMiddleware` |
| `RuntimeProtectedPathRateLimiter` booking buckets | Implemented but not invoked on booking routes | `PublicBookingRateLimitMiddleware` on public booking endpoints |

**Scope:**

| ID | Deliverable | Proof |
|----|-------------|-------|
| W5-A | `RequestLatencyMiddleware` wired as first entry in `Dispatcher::$globalMiddleware` | Every HTTP request is timed; slow requests emit `endpoint_latency` slog |
| W5-B | `PublicBookingRateLimitMiddleware` — `BUCKET_BOOKING_SUBMIT` (20/600s) and `BUCKET_BOOKING_AVAILABILITY_READ` (120/60s) | Wired to `/api/public/booking/slots` and `/api/public/booking/book`; fails-open on rate-limiter errors |
| W5-C | Proof script confirming both wired paths are live and middleware chain is correct | `verify_wave05_live_enforcement_foundations_01.php` passes |

**Must not touch:**
- Booking transaction correctness / `AppointmentService` write paths
- Session / auth / tenant isolation kernel
- Existing `PublicBookingController` rate-limit logic (supplemental layer, not a replacement)

**Entry condition:** WAVE-04 proof passes.  
**Exit condition:** `verify_wave05_live_enforcement_foundations_01.php` passes; HTTP regression sweep clean.

---

## WAVE-06 — HOT PATH CACHE EFFECTIVENESS

**Status:** DONE (2026-03-31)

**Why this wave exists:**  
WAVE-01 established `SharedCacheInterface` (Redis-backed in production). WAVE-05 wired observability and rate limiting. WAVE-06 adds cross-request caching on the two hot paths that were still hitting the DB on every request:

| Hot path | Before | After |
|---|---|---|
| `PermissionService::getForUser()` | DB + in-request memo | Shared cache TTL 120s + explicit invalidation on RBAC/staff-group mutations |
| `AvailabilityService::listDayAppointmentsGroupedByStaff()` | DB every call | Shared cache TTL 30s + invalidation on appointment/blocked-slot mutations affecting that day/branch |

**Scope:**

| ID | Deliverable | Proof |
|----|-------------|-------|
| W6-A | `PermissionService` cross-request cache — `SharedCacheInterface`, TTL 120s, key `perm_v1:u{userId}:b{branchId\|null}`, fail-open on cache errors | `clearCachedForUser()`, `clearSharedPermissionCacheForStaffUser()` |
| W6-B | `listDayAppointmentsGroupedByStaff()` cache — TTL 30s, key `cal_v1:day_apts:{branchId\|null}:{date}`, fail-open | Repeat reads use shared cache when backend healthy |
| W6-C | Invalidation — calendar: `invalidateDayCalendarCache()` from `AppointmentService` (create/cancel/reschedule/updateStatus/delete) and `BlockedSlotService` (create/delete). Permissions: `TenantUserProvisioningService`, `FounderAccessManagementService`, `StaffGroupPermissionService`, `StaffGroupService` (attach/detach/deactivate / is_active off) | No stale privilege grants; calendar list refreshed on writes |
| W6-D | Final booking revalidation — `isSlotAvailable(..., $forAvailabilitySearch=false)` **not** cached | Conflict checks always authoritative |
| W6-E | Proof script | `verify_wave06_hot_path_cache_effectiveness_01.php` |

**Cache key / TTL / invalidation contract:**
- `perm_v1:u{userId}:b{branchId|null}` — 120s TTL — invalidated via `clearCachedForUser` and `clearSharedPermissionCacheForStaffUser` (covers `users.branch_id` and each `staff.branch_id` plus `bnull`).
- `cal_v1:day_apts:{branchId|null}:{date}` — 30s TTL — invalidated by `invalidateDayCalendarCache(date, branchId)`.

**Note (read-model scope):** The day-calendar cache applies only to **`listDayAppointmentsGroupedByStaff`** (appointment rows for the grid). It does **not** cache `isSlotAvailable`, working-hours resolution, or blocked-slot listing helpers. Therefore **working-hours / schedule-only** mutations are **not** required to invalidate this key; **blocked slots** are covered by `BlockedSlotService` invalidation. If a future change merges more read models into one cached blob, the invalidation contract must be expanded in the same commit.

**Must not touch:**
- Booking conflict check path (`isSlotAvailable` with `$forAvailabilitySearch=false`)
- Auth middleware enforcement logic
- Session / tenant isolation kernel

**Entry condition:** WAVE-05 proof passes.  
**Exit condition:** `verify_wave06_hot_path_cache_effectiveness_01.php` passes; release-law Tier A green.

---

## DEFERRED items (moved from active lanes by this charter)

See `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` for full list. Summary of what was explicitly parked:

- Synchronous complex reporting features
- Extra marketing automation complexity before queue hardening is complete  
- UI/UX polish not tied to runtime truth
- Database-backed feature-flag expansion before mandatory Redis caching is enforced
- FND-PERF-03 (invoice sequence partitioning) — deferred past WAVE-02
- APPOINTMENTS-P2 (`AppointmentService::findForUpdate` migration) — deferred, low urgency
