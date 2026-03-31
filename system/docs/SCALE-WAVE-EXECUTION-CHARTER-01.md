# Scale Wave Execution Charter — 01

**Date:** 2026-03-31  
**Status:** WAVE-01 DONE · WAVE-02 DONE · WAVE-03 DONE · WAVE-04 DONE · WAVE-05 DONE · WAVE-06 DONE  
**Authority:** Architect verdict (2026-03-31 scalability & load assessment).  
**Relationship to FOUNDATION-A series:** Foundation A1–A7 established the tenant/auth/service kernel. This charter launches on top of that kernel to address the **runtime, infrastructure, and scale** layer. These waves do **not** reopen or contradict Foundation work — they build on it.  
**Active queue:** This file. **No wave is LIVE** until a new wave is explicitly opened below the next gate.  
**Deferred registry:** `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` (items parked per this charter).  
**Full status inventory:** `TASK-STATE-MATRIX.md` (Foundation spine); scale-campaign truth is **this charter + git main**.

---

## CURRENT CANONICAL HARDENING STATUS (scale campaign)

| Wave | State | Proof script (closes wave) |
|------|--------|----------------------------|
| WAVE-01 Production runtime foundation | **DONE** | `system/scripts/read-only/verify_wave01_production_runtime_foundation_01.php` |
| WAVE-02 Queue throughput hardening | **DONE** | `system/scripts/read-only/verify_wave02_queue_throughput_hardening_01.php` |
| WAVE-03 Scale readiness + observability | **DONE** | `system/scripts/read-only/verify_wave03_scale_readiness_observability_01.php` |
| WAVE-04 Safe scale operations | **DONE** | `system/scripts/read-only/verify_wave04_safe_scale_ops_01.php` |
| WAVE-05 Live enforcement foundations | **DONE** | `system/scripts/read-only/verify_wave05_live_enforcement_foundations_01.php` |
| WAVE-06 Hot path cache effectiveness | **DONE** | `system/scripts/read-only/verify_wave06_hot_path_cache_effectiveness_01.php` |

**Runtime/code alignment:** `main` includes commits `feat(wave01)` … `feat(wave06)` (see `git log --oneline`).

**What WAVE-06 proved:** Permission cross-request cache (`SharedCacheInterface`, TTL 120s, key `perm_v1:u{userId}:b{branchId|null}`) with explicit invalidation on staff-group permission/membership changes and tenant provisioning / founder access repair paths; day-calendar appointment read cache (`cal_v1:day_apts:{branchId|null}:{date}`, TTL 30s) with invalidation on appointment and blocked-slot mutations; booking conflict path (`isSlotAvailable` with `forAvailabilitySearch=false`) **not** cached.

---

## NEXT GATE TO OPEN NEW WAVE

1. **WAVE-06 proof script passes** (`verify_wave06_hot_path_cache_effectiveness_01.php`).  
2. **Prior wave proofs** (01–05) remain green in the release/regression gate you use for this repo.  
3. **No new LIVE wave** is declared in this file until the next wave is named, scoped, and given its own proof script name in a charter edit.

**Documented next candidate (do not start until gate above is satisfied):** **WAVE-07 — Read/write routing + ProxySQL runtime proof** — code and docs from WAVE-03 established packages and patterns; the next scale step is **proving** read/write routing and ProxySQL (or equivalent) in a real runtime path, not only artifacts. **Not chosen for this gate:** shard-readiness-only hot spots (WAVE-04 already started guardrails; routing proof is the higher-leverage next step).

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

These are in order of production urgency. The waves execute exactly in this order.

---

## Anti-drift rules

- **Exactly one LIVE wave** at a time when a wave is active. When no wave is LIVE, all listed waves are **DONE** until the next is opened.
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
**Exit condition:** `verify_wave02_queue_throughput_hardening_01.php` passes.

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
**Exit condition:** `verify_wave03_scale_readiness_observability_01.php` passes.

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
**Exit condition:** `verify_wave04_safe_scale_ops_01.php` passes.

---

## WAVE-05 — LIVE ENFORCEMENT FOUNDATIONS

**Status:** DONE (2026-03-31)

**Why this wave exists:**  
WAVE-01 through WAVE-04 built the foundations. WAVE-05 wires two foundations that were created but left dead (not connected to the runtime pipeline):

| Foundation | Previous gap | Resolution |
|---|---|---|
| `RequestLatencyMiddleware` | Registered in DI but not in global middleware | Wired as first entry in `Dispatcher::$globalMiddleware` |
| `RuntimeProtectedPathRateLimiter` booking buckets | Not called on public booking routes | `PublicBookingRateLimitMiddleware` on `/api/public/booking/slots` and `/api/public/booking/book` |

**Scope:**

| ID | Deliverable | Proof |
|----|-------------|-------|
| W5-A | `RequestLatencyMiddleware` wired as first entry in `Dispatcher::$globalMiddleware` | Every HTTP request is now timed; slow requests emit `endpoint_latency` slog |
| W5-B | `PublicBookingRateLimitMiddleware` — new middleware consuming `BUCKET_BOOKING_SUBMIT` (20/600s) and `BUCKET_BOOKING_AVAILABILITY_READ` (120/60s) | Middleware fails-open on rate-limiter errors; wired to `/api/public/booking/slots` and `/api/public/booking/book` |
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
WAVE-01 established `SharedCacheInterface` (Redis-backed in production). WAVE-05 wired observability and rate-limiting. WAVE-06 activates the first real runtime efficiency wins by injecting `SharedCacheInterface` into the two highest-impact hot paths that still hit the DB on every request:

| Hot path | Previous state | Scale impact |
|---|---|---|
| `PermissionService::getForUser()` | Per-request in-memory array + DB | Cross-request cache with TTL + explicit invalidation on RBAC/staff-group and provisioning mutations |
| `AvailabilityService::listDayAppointmentsGroupedByStaff()` | Fresh DB query on every calendar poll | Short-TTL read cache for the **appointment** slice of the day JSON; blocked slots / hours / grid remain uncached |

**Scope:**

| ID | Deliverable | Proof |
|----|-------------|-------|
| W6-A | `PermissionService` cross-request cache — `SharedCacheInterface`-backed, TTL 120s, cache key `perm_v1:u{userId}:b{branchId\|null}`, fail-open on cache error, invalidation helpers (`clearCachedForUser`, `clearSharedCacheForUserAllBranchContexts`, `invalidateStaffGroupPermissionCachesForGroupMembers`) | Wired from staff-group services, tenant provisioning, founder access repair |
| W6-B | `AvailabilityService::listDayAppointmentsGroupedByStaff()` short-TTL cache — TTL 30s, cache key `cal_v1:day_apts:{branchId\|null}:{date}`, fail-open on cache error | Calendar JSON uses cached appointment list on repeat reads |
| W6-C | Explicit invalidation — `AvailabilityService::invalidateDayCalendarCache()` from appointment mutations (`AppointmentService`) and blocked-slot mutations (`BlockedSlotService`); permission invalidation on staff-group permission replace, attach/detach, deactivate; role assignment via `TenantUserProvisioningService`; founder branch/role repair via `FounderAccessManagementService` | Calendar + permission caches stay consistent with mutation paths |
| W6-D | Final booking revalidation preserved — `isSlotAvailable()` in booking write path (`forAvailabilitySearch=false`) is **never cached**; always hits DB | Booking conflicts still caught; no double-booking risk introduced |
| W6-E | Proof script `verify_wave06_hot_path_cache_effectiveness_01.php` passes all assertions | Machine-verifiable proof of wiring and structure |

**Cache key / TTL / invalidation contract:**
- `perm_v1:u{userId}:b{branchId|null}` — 120s TTL — invalidated via `clearCachedForUser` / `clearSharedCacheForUserAllBranchContexts` / `invalidateStaffGroupPermissionCachesForGroupMembers` on the paths above.
- `cal_v1:day_apts:{branchId|null}:{date}` — 30s TTL — invalidated by `invalidateDayCalendarCache(date, branchId)` from appointment and blocked-slot mutations.

**Note:** Day JSON also loads `blocked_by_staff`, `branch_operating_hours`, and `time_grid` from **uncached** services. The cached slice is **appointments by staff** for the day list only.

**Must not touch:**
- Booking conflict check path (`isSlotAvailable` with `$forAvailabilitySearch=false`)
- Auth middleware enforcement logic
- Session / tenant isolation kernel

**Entry condition:** WAVE-05 proof passes.  
**Exit condition:** `verify_wave06_hot_path_cache_effectiveness_01.php` passes; HTTP regression sweep clean.

---

## DEFERRED items (moved from active lanes by this charter)

See `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` for full list. Summary of what was explicitly parked:

- Synchronous complex reporting features
- Extra marketing automation complexity before queue hardening is complete  
- UI/UX polish not tied to runtime truth
- Database-backed feature-flag expansion before mandatory Redis caching is enforced
- FND-PERF-03 (invoice sequence partitioning) — deferred past WAVE-02
- APPOINTMENTS-P2 (`AppointmentService::findForUpdate` migration) — deferred, low urgency
