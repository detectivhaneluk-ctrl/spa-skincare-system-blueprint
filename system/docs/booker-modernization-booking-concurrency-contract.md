# Booking write-path inventory & concurrency contract (BKM-001)

**Status:** Normative input for BKM-002–BKM-004.  
**Source:** Repository inspection as of task BKM-001.  
**Related:** `booker-modernization-master-plan.md` §5–§6.

---

## 1. Scope of this document

This contract lists **every code path** that **inserts** an `appointments` row or **updates** columns that affect scheduling conflicts: at minimum `start_at`, `end_at`, `staff_id`, `room_id`, `service_id`, `branch_id` (when combined with times), and `status` (when it removes/adds blocking overlap per `AvailabilityService`).

Read paths (`AvailabilityService::getAvailableSlots`, `isSlotAvailable`, `hasBufferedAppointmentConflict`) are included only where they participate in write safety.

---

## 2. Availability / conflict mechanics (shared)

| Symbol | Location | Role |
|--------|----------|------|
| `isStaffWindowAvailable` | `AvailabilityService.php` | Effective **working intervals** (weekly `staff_schedules` + **BKM-006** `staff_availability_exceptions`), then breaks, blocked slots, buffered appointment overlap. |
| `hasBufferedAppointmentConflict` | `AvailabilityService.php` (private) | SQL `SELECT` over `appointments` + `services` buffers; **no row locks**. |
| `isSlotAvailable` | `AvailabilityService.php` | Computes end from service duration; calls `isStaffWindowAvailable` with buffers; `excludeAppointmentId` forwarded. |
| `checkConflicts` | `AppointmentService.php` (private) | Uses `isStaffWindowAvailable` + **`AppointmentRepository::hasRoomConflict`**; **`rooms` `FOR UPDATE`** when `room_id` is set (**FOUNDATION-14**). |

**Blocking appointment statuses** for overlap: `scheduled`, `confirmed`, `in_progress`, `completed` (`AvailabilityService::BLOCKING_STATUSES`).

**Implication:** Two concurrent writers can both pass `isSlotAvailable` / `checkConflicts` before either inserts/updates unless **additional** locking or a **DB exclusion constraint** is applied (master plan §6).

---

## 3. Write-path inventory (routes → service → persistence)

### W1 — Admin form create (full payload)

| Field | Value |
|-------|--------|
| **HTTP** | `POST /appointments` |
| **Controller** | `AppointmentController::store` |
| **Service** | `AppointmentService::create(array $data)` |
| **Transaction** | Yes — `transactional()` → `beginTransaction` / `commit` / `rollBack` on outer failure |
| **Row locks (`FOR UPDATE`)** | **Yes** (BKM-003): `lockActiveStaffAndServiceRows` — `staff` then `services` when ids are set and positive, before `checkConflicts` + insert |
| **Conflict / availability** | `validateTimes`, `assertActiveEntities`, `assertRequiredConsents`, `checkConflicts($data, 0)` |
| **Exclude id** | `0` (new row) |
| **Persistence** | `AppointmentRepository::create` → `Database::insert('appointments', …)` |
| **Audit** | `appointment_created` |

_(BKM-003: W1 aligned with staff/service serialization; still supports custom `end_at` / `room_id` unlike W2 slot pipeline.)_

### W2 — Admin slot create (core slot pipeline)

| Field | Value |
|-------|--------|
| **HTTP** | `POST /appointments/create` |
| **Controller** | `AppointmentController::storeFromCreatePath` |
| **Service** | `AppointmentService::createFromSlot(array $data)` |
| **Transaction** | Yes — explicit `$pdo->beginTransaction` when not already in transaction |
| **Row locks** | **Yes:** `lockActiveStaffAndServiceRows` (same helper as W1) |
| **Lock order (normative for this path)** | 1) `staff` FOR UPDATE 2) `services` FOR UPDATE 3) availability read 4) insert |
| **Conflict / availability** | `validateTimes`, `ConsentService::checkClientConsentsForService`, `isSlotAvailable(…, excludeId: null, …)` |
| **Exclude id** | `null` (new row) |
| **Persistence** | `AppointmentRepository::create` |
| **Audit** | `appointment_created` with `source` => `slot_booking_core` |

### W3 — Public online booking create

| Field | Value |
|-------|--------|
| **HTTP** | `POST /api/public/booking/book` |
| **Controller** | `PublicBookingController::book` → `PublicBookingService::createBooking` → `AppointmentService::createFromPublicBooking` |
| **Abuse guard (before service)** | DB-backed (`PublicBookingAbuseGuardService`): `book` (IP), `book_contact`, `book_slot`, `book_fingerprint` — same order and semantics as `online-booking` README / `public-self-service-implementation-map.md` §2.4 |
| **Transaction** | **Yes** — `insertNewSlotAppointmentWithLocks` (same as W2 when not nested) |
| **Row locks** | **Yes:** `staff`, `services` `FOR UPDATE` before consent + `isSlotAvailable` + insert |
| **Pre-steps** | Branch/settings validation; `resolveClient` (**always** `ClientRepository::create` for anonymous public book; no `client_id`; PB-HARDEN-NEXT); `getActiveServiceForScope` / `getActiveStaffForScope` (non-locking); online min/max window **before** tx |
| **Inside transaction** | Consent check, `isSlotAvailable(…, excludeId: null, …)`, insert; `created_by` / `updated_by` null |
| **Exclude id** | `null` |
| **Persistence** | `AppointmentRepository::create` via `AppointmentService` |
| **Audit** | `appointment_created`, `source` => `public_booking` (`AuditService` may still coalesce null actor to session user — pre-existing) |

_(BKM-002: eliminated unauthenticated check-then-insert race.)_

**PB-HARDEN-CONCURRENCY-VERIFY-01 — duplicate slot booking (same `branch_id` + `service_id` + `staff_id` + same wall-clock start):** Under InnoDB’s default **READ COMMITTED** isolation, two near-simultaneous public `POST /book` requests **cannot** both insert overlapping **scheduled** appointments for that staff/window.

*Proof method:* Code review of `AppointmentService::createFromPublicBooking` → `insertNewSlotAppointmentWithLocks` (`system/modules/appointments/services/AppointmentService.php`) and `AvailabilityService::hasBufferedAppointmentConflict` (`system/modules/appointments/services/AvailabilityService.php`).

*Mechanism:* (1) `insertNewSlotAppointmentWithLocks` opens a transaction and calls `lockActiveStaffAndServiceRows`, which executes `SELECT … FROM staff WHERE id = ? … FOR UPDATE` **before** `isSlotAvailable` and **before** `AppointmentRepository::create`. (2) For the same `staff_id`, the second transaction **blocks** on that row lock until the first **commits** or **rolls back**. (3) After the first commits, the second holds the staff (and service) lock, runs `isSlotAvailable(…, excludeId: null, …)`, which uses `hasBufferedAppointmentConflict` with `status IN ('scheduled','confirmed','in_progress','completed')` and buffered overlap against existing rows — so it **observes** the first booking and returns unavailable. (4) `PublicBookingService::createBooking` creates the client row **outside** this transaction, which does not weaken serialization: duplicate **appointments** for the same staff/time are still prevented at the locked pipeline.

*Scope / assumptions:* Single primary MySQL (no cross-node lag assumptions). No additional guard added — existing strategy is **sufficient** for this repo’s public path.

### W4 — Waitlist → appointment conversion

| Field | Value |
|-------|--------|
| **HTTP** | `POST /appointments/waitlist/{id}/convert` (see `system/routes/web.php`) |
| **Controller** | `AppointmentController::waitlistConvertAction` → `WaitlistService::convertToAppointment` |
| **Outer transaction** | Yes — `WaitlistService::transactional()` |
| **Inner** | Calls `AppointmentService::createFromSlot` **inside** same PDO connection; `createFromSlot` **does not** start a new transaction if `inTransaction()` is true |
| **Row locks** | Same as **W2** (staff, service), held until **outer** `commit` |
| **Waitlist row** | Updated after successful `createFromSlot` (`status` `booked`, `matched_appointment_id`) |
| **Persistence** | `AppointmentRepository::create` via `createFromSlot` |
| **Audit** | Multiple waitlist + conversion events |

**Note:** Nested transaction behavior: **single** commit at end of `convertToAppointment`; extends lock duration vs standalone `createFromSlot`.

**BKM-009 verification:** Conversion is the **only** waitlist path that creates an appointment; it delegates **solely** to `createFromSlot` (W2). No separate slot-claim or repository insert. Availability (including BKM-006 date-specific exceptions via `getWorkingIntervals`) and consent are enforced inside `insertNewSlotAppointmentWithLocks`. `linkToAppointment` only updates the waitlist row; suggested-entries filtering uses `WaitlistRepository::list` only (no availability/slot logic). No bypass found.

### W5 — Admin appointment update (generic edit)

| Field | Value |
|-------|--------|
| **HTTP** | `POST /appointments/{id}` |
| **Controller** | `AppointmentController::update` |
| **Service** | `AppointmentService::update(int $id, array $data)` |
| **Transaction** | Yes — `transactional()` |
| **Row locks** | **Yes** (BKM-003): `appointments` row `FOR UPDATE`; **BKM-004:** if the patch changes scheduling fields (`start_at`, `end_at`, `staff_id`, `room_id`, `service_id`, `branch_id`), `buildServiceBasedMovePatchAfterAppointmentLock` acquires `staff` then `services` `FOR UPDATE` before `isSlotAvailable` |
| **Conflict / availability** | **Scheduling mutation + service + staff:** `validateTimes` on service-derived `end_at`, `assertRequiredConsents`, `isSlotAvailable(…, excludeId: $id, …)`; **room:** `lockRoomRowAssertCanonicallyFreeOrThrow` → canonical **`hasRoomConflict`**. **Non-scheduling edits:** `lockActiveStaffAndServiceRows`, `validateTimes` on payload, `checkConflicts($data, $id)` |
| **Persistence** | `AppointmentRepository::update` (normalized payload) |
| **Audit** | `appointment_updated` (`before`/`after` from `find()` after lock + post-update) |

**BKM-004:** Time-affecting edits with both `service_id` and `staff_id` use the same locked move pipeline as **W6** (duration from service, not form `end_at` alone). **W1** admin create may still use custom `end_at` + `checkConflicts`; that boundary is intentional until a later task narrows W1.

### W6 — Reschedule (dedicated move)

| Field | Value |
|-------|--------|
| **HTTP** | `POST /appointments/{id}/reschedule` |
| **Controller** | `AppointmentController::rescheduleAction` |
| **Service** | `AppointmentService::reschedule(int $id, string $startTime, ?int $staffId, ?string $notes)` |
| **Transaction** | Yes — explicit `beginTransaction` when not already active |
| **Row locks** | **Yes:** `appointments` row `FOR UPDATE`; then shared **`buildServiceBasedMovePatchAfterAppointmentLock`** → `staff` `FOR UPDATE`, `services` `FOR UPDATE` |
| **Lock order (this path)** | 1) `appointments` 2) `staff` 3) `services` 4) `validateTimes` 5) `isSlotAvailable(…, excludeId: $id, …)` 6) optional **`rooms` `FOR UPDATE` + `hasRoomConflict`** when `room_id` applies 7) `repo->update` |
| **Duration** | Recomputed from `getServiceDurationMinutes`; `end_at` set in service (same helper as W5 scheduling mutation) |
| **Persistence** | `AppointmentRepository::update` |
| **Audit** | `appointment_rescheduled` |

### W7 — Cancel / status / delete (scheduling impact)

| Path | Alters times? | Locks | Notes |
|------|----------------|-------|-------|
| `cancel` | No (status + notes) | `appointments` FOR UPDATE | Terminal `cancelled` removes row from blocking overlap set |
| `updateStatus` | No | `appointments` FOR UPDATE | Status transitions only |
| `delete` | Soft delete | None on appointment row in snippet | `softDelete`; overlap queries use `deleted_at` |

These are listed because they change **whether** a row participates in `hasBufferedAppointmentConflict`, not because they set `start_at`/`end_at`.

### W8 — Other modules

| Check | Result |
|-------|--------|
| Direct `AppointmentRepository::create` outside `AppointmentService` | **None** for W3 after BKM-002 (delegates to `AppointmentService`). Re-grep if new callers appear. |
| Raw SQL insert into `appointments` elsewhere | **None found** in BKM-001 grep scope. |

`AppointmentCheckoutProviderImpl` and sales/invoice flows were not found creating `appointments` rows in this pass; **re-verify** if invoice-from-appointment features are added later.

---

## 4. Read path: public slots (no write)

| **HTTP** | `GET` `/api/public/booking/slots` only (no POST route registered) |
| **Service** | `PublicBookingService::getPublicSlots` → `AvailabilityService::getAvailableSlots` |
| **Abuse guard** | `PublicBookingController::slots`: `read_slots_ip` then `read_slots_scope` (see `public-self-service-implementation-map.md` §2.4) |
| **Locks** | None |
| **Relevance** | Stale reads still possible vs final **book**; client should re-fetch slots after failed book. |

---

## 5. Normative “slot booking pipeline” (target for parity — BKM-002+)

**Definition (for implementation tasks):** Any path that **creates** a new appointment at a chosen staff/time/service MUST, within **one** database transaction:

1. Acquire **pessimistic locks** in a **fixed global order** (see §6) on rows that serialize concurrent books for the same staff (minimum: staff row; service row as today in W2).
2. Re-validate service/staff active state **after** locks.
3. Run consent and branch rules as today.
4. Call availability check with **`excludeAppointmentId` = null** for creates.
5. `INSERT` into `appointments`.
6. `COMMIT`.

**Current compliance:**

| Path | Complies |
|------|----------|
| W2 `createFromSlot` | **Yes** (baseline) |
| W3 `createBooking` | **Yes** — uses shared `insertNewSlotAppointmentWithLocks` (BKM-002) |
| W1 `create` | **Yes** (BKM-003) — staff/service locks before `checkConflicts` |

**Reschedule (W6)** is a **move** pipeline: lock appointment + staff + service, then `isSlotAvailable` with **excludeId = id**.

---

## 6. Recommended global lock order (for BKM-002–BKM-003)

To reduce deadlock risk when multiple paths align:

1. `appointments` (when updating existing row) — **ascending `id`** if multiple rows ever locked (not current code).
2. `staff` — by `staff_id`.
3. `services` — by `service_id`.
4. Availability reads (no locks).
5. Insert/update `appointments`.

**Current code deviations:** W2 locks staff then service **without** locking appointment (create). W5 scheduling mutations and W6 lock appointment then staff then service (**BKM-004**). W2/W3 create ordering remains staff → service (no appointment row yet).

---

## 7. Decision: `update` (W5) vs `reschedule` (W6)

**Status:** **RESOLVED (BKM-004)** — **Option A (delegated internally):** Any admin **scheduling mutation** on `update` (change to `start_at`, `end_at`, `staff_id`, `room_id`, `service_id`, or `branch_id` vs the locked row) **requires** positive `service_id` and `staff_id` and runs **`buildServiceBasedMovePatchAfterAppointmentLock`**: appointment `FOR UPDATE` → staff/service locks → `validateTimes` → optional consent → `isSlotAvailable` with `excludeAppointmentId = id` → service-derived `end_at`. **`reschedule`** calls the same helper (room preserved or patched; **`rooms` `FOR UPDATE`** + canonical **`hasRoomConflict`** when a positive `room_id` applies — **FOUNDATION-14**).

**Intentional boundary:** **W1** admin create may still persist a custom `end_at` and use `checkConflicts`; service-based **moves** (W5 scheduling patch, W6) are the authoritative slot pipeline for calendar-style moves and future drag/drop backends.

**Room caveat:** Conflicting **`appointments`** rows are **not** locked; exclusivity is enforced by **`rooms` `FOR UPDATE`** + recheck **`hasRoomConflict`** on write paths that set a room (**W1 `checkConflicts`**, W5/W6 move helper, internal slot create with room). See **`APPOINTMENT-ROOM-CONFLICT-CANONICALIZATION-FOUNDATION-14-OPS.md`**.

---

## 8. Failure semantics (current, by exception type)

| Exception | Typical paths | User-facing pattern |
|-----------|----------------|---------------------|
| `DomainException` | Conflicts, consent, validation, inactive entities | Caught in controllers; flash / JSON error message |
| `InvalidArgumentException` | Malformed input | Form errors / 4xx |
| `RuntimeException` | Not found | 404 / error |

**Public booking** returns `['success' => false, 'error' => string]`; generic `Throwable` maps to a safe message (`Booking could not be completed.`) while `DomainException` / `InvalidArgumentException` pass through `getMessage()` (BKM-002).

---

## 9. Cross-check vs master plan §6

| Risk in master plan | Addressed in contract |
|---------------------|------------------------|
| Concurrency / double-book | §2, §3 W1/W3 vs W2, §5 |
| Uneven create paths | §3 W1 vs W2 vs W3, §5 |
| Public booking weakness | §3 W3, §5 |
| Move vs update asymmetry | §3 W5 vs W6, §7 |
| Timezone | **§11** (BKM-005) |

---

## 10. Optional follow-ups (not BKM-001 scope)

- **Client row created** in `resolveClient` before W3 slot claim: appointment may fail after client insert; document remediation under a future idempotency/consistency task if required.
- **DB exclusion constraint** (gist/overlap): not present in schema today; mention only as possible **BKM-003+** hardening per master plan.

---

## 11. Timezone (BKM-005)

**Authoritative runtime rule:** `Core\App\ApplicationTimezone` sets PHP’s default timezone via `date_default_timezone_set` using this merge order on **each** apply:

1. **Primary:** `establishment.timezone` from `SettingsService::getEstablishmentSettings(BranchContext::getCurrentBranchId())` (branch **null** ⇒ global `branch_id = 0` merge only; non-null ⇒ branch-specific rows override globals per `SettingsService::all()`), including legacy flat `timezone` when present, **only if** the value is a non-empty valid PHP timezone identifier (e.g. `America/New_York`).
2. **Fallback:** `config/app.php` key `timezone` (env `APP_TIMEZONE`, default `UTC`).
3. **Last resort:** `UTC` if the config value is invalid.

**Pipeline:**

- **`Application::run()`** calls `ApplicationTimezone::applyForHttpRequest()` once (guarded) while `BranchContext` is usually still **unresolved** — same effective merge as guests (global-only).
- **`BranchContextMiddleware`** (end of handler) calls `ApplicationTimezone::syncAfterBranchContextResolved()` so authenticated staff requests use **branch-effective** establishment timezone after the session/request branch is resolved. Guests get a second apply with `null` context (typically idempotent with the first pass).

**Storage:** `appointments.start_at` / `end_at` (and related naive `Y-m-d` / `Y-m-d H:i:s` strings) are treated as **wall-clock datetimes in that default timezone**. There is **no** UTC migration or column type change in BKM-005.

**Scope of effect:** All booking-adjacent uses of `strtotime`, `date`, and `time()` in the same request after branch middleware (e.g. `AvailabilityService`, `PublicBookingService`, `AppointmentService::validateTimes`, move/reschedule duration math) follow the **final** applied default timezone.

**Still out of scope:**

- **MySQL session time zone:** DB connection time zone is unchanged; queries use stored naive datetimes as before.
- **CLI / one-off scripts** that load only `system/bootstrap.php` (without `Application::run()`) do **not** invoke `ApplicationTimezone`; they remain on PHP’s default unless the environment sets `date.timezone`.

---

## 12. Staff date-specific availability exceptions (BKM-006)

**Table:** `staff_availability_exceptions` (`system/data/migrations/052_create_staff_availability_exceptions.sql`). **Read path:** `StaffAvailabilityExceptionRepository::listForStaffAndDate` → `AvailabilityService` private `getWorkingIntervals` (used by `getAvailableSlots`, `isStaffWindowAvailable`, `getStaffAvailabilityForDate`, `getDayGrid`).

**`kind` values:**

| kind | Meaning | Times |
|------|---------|--------|
| `closed` | Full day off | `start_time` / `end_time` ignored |
| `open` | Working segment for that **calendar** `exception_date` | Required; multiple rows merge |
| `unavailable` | Time off inside the effective working day | Required; subtracted from intervals |

**Precedence (same date, after branch filter on rows):**

1. If any row is `closed` → **no** working intervals that day.
2. Else if any `open` row → working intervals = **merged** `open` segments only (weekly `staff_schedules` ignored for that date).
3. Else → weekly `staff_schedules` for the DOW.
4. Then each `unavailable` window is **subtracted** from those intervals.
5. Unchanged thereafter: recurring `staff_breaks`, `appointment_blocked_slots`, buffered appointment overlap (`isStaffWindowAvailable`).

**Branch:** `branch_id` NULL applies in all contexts when querying; non-null rows match when `listForStaffAndDate` is called with that `branch_id` (same idea as `appointment_blocked_slots`). No admin UI or routes in BKM-006 — rows are expected via SQL/migration/ops until a future UI task.

---

## 13. Day calendar JSON (`GET /calendar/day`) — BKM-008

**Handler:** `AppointmentController::dayCalendar`. **Auth:** `appointments.view`. **Query:** `date` (`YYYY-MM-DD`, default today), `branch_id` (optional; via existing branch query helper).

**Contract identifier (every successful and error JSON body):**

| Field | Type | Meaning |
|-------|------|--------|
| `day_calendar_contract.name` | string | Fixed: `spa.day_calendar` |
| `day_calendar_contract.version` | int | **1** = current stable shape; bump only on breaking changes (prefer additive fields first) |

**Capabilities (additive, for future drag/move consumers):**

| Field | Type | Meaning |
|-------|------|--------|
| `capabilities.move_preview` | bool | **false** in v1; reserved for server-side move validation payloads later |

**v1 payload (success, HTTP 200):**

| Field | Type | Notes |
|-------|------|--------|
| `date` | string | Requested calendar date |
| `branch_id` | int\|null | Scope echoed from request context |
| `staff` | array | Active staff rows: `id`, `first_name`, `last_name`, `branch_id` (nullable) |
| `appointments_by_staff` | object | Keys = staff id as **string** in JSON; values = arrays of appointment objects: `id`, `staff_id`, `client_id`, `client_name`, `service_id`, `service_name`, `start_at`, `end_at`, `status` (naive local datetimes) |
| `blocked_by_staff` | object | Same keying; values = blocked slot segments: `id`, `staff_id`, `title`, `start_at`, `end_at`, `notes` |
| `time_grid` | object | `date`, `slot_minutes`, `day_start`, `day_end` (`HH:MM`) |

**v1 error (HTTP 422):** includes the same `day_calendar_contract` + `capabilities` objects plus `error` (string). Existing consumers that only read legacy keys remain valid; unknown top-level keys are ignored by tolerant clients.

---

## Document history

| Date | Change |
|------|--------|
| (BKM-001) | Initial inventory and contract from repo trace. |
| (BKM-004) | W5/W6 unified locked service move helper; §7 resolved; room overlap remains non-locking. |
| (BKM-005) | §11: `ApplicationTimezone` at `Application::run()` + `syncAfterBranchContextResolved()` after `BranchContextMiddleware`; establishment TZ + `APP_TIMEZONE` fallback. |
| (CORE-RUNTIME-SCHEMA-TRUTH-SEAL-01) | §11: branch-effective TZ sync documented; aligns with runtime. |
| (BKM-006) | §12: `staff_availability_exceptions` + `getWorkingIntervals` precedence; §2 `isStaffWindowAvailable` row updated. |
| (BKM-008) | §13: versioned `GET /calendar/day` JSON (`day_calendar_contract`, `capabilities`, `branch_id`). |
| (BKM-009) | W4: repo verification — waitlist conversion uses only `createFromSlot`; no bypass; BKM-006 availability applies. |
| (BKM-010) | Single-salon branch position: `booker-modernization-single-salon-branch-position.md` (keep branch foundation; no removal in BKM-010). |
