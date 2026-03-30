# Staff concurrency / staff overlap — truth audit (FOUNDATION-02)

**Task:** `STAFF-CONCURRENCY-TRUTH-AUDIT-02`  
**Scope:** Appointment **staff** exclusivity, overlap, and related availability — **audit only**. **No** new settings, **no** UI, **no** runtime code changes in this wave.

**Related:** Room policy: `APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md`. Appointment settings: `APPOINTMENT-SETTINGS-BACKEND-CONTRACT-FOUNDATION-01-OPS.md` (waves **07** / **08**). Booking concurrency contract: `booker-modernization-booking-concurrency-contract.md`.

---

## 1) What “staff concurrency” means in this repo (from code)

There is **no** single flag named “staff concurrency” today. Operationally, operators care about **whether the same staff member can have two bookings overlapping in time**. In code, that guarantee is **not** one isolated check; it is bundled with **schedule shape**, **breaks**, **blocked slots**, and **buffer-expanded appointment overlap**.

### 1.A Constraints applied before a slot is “available”

| Constraint | Where | “Concurrency” or broader? |
| --- | --- | --- |
| **Service–staff eligibility** (`service_staff`, `service_staff_groups`, staff groups) | `AvailabilityService::isSlotAvailable` → `ServiceStaffGroupEligibilityService` | **Broader** — not double-booking; who may perform the service. |
| **Inactive / missing staff** | `getActiveStaff` inside `isStaffWindowAvailable` | **Broader** — identity/active gate. |
| **Staff branch vs booking branch** | `isStaffWindowAvailable` (staff row `branch_id` vs `$branchId`) | **Broader** — scope. |
| **Staff group scope** | `getActiveStaffForScope` / `getEligibleStaff` / `listStaffSelectableForService` | **Broader** — policy scope. |
| **Working intervals** (`staff_schedules` + availability exceptions via `getWorkingIntervals`) | `isStaffWindowAvailable` when `$enforceStaffScheduleConstraints` is true | **Broader schedule availability** — not the same as “two appointments at once” if interpreted narrowly. |
| **Off-day synthetic envelope** (`appointments.allow_staff_booking_on_off_days`, internal only) | `isStaffWindowAvailable` when intervals empty + internal + not `closed` exception | **Broader** (wave **08**). |
| **Breaks** | `getBreakIntervals` + `overlapsAny` | **Broader** — hard unavailability windows. |
| **Staff blocked slots** | `BlockedSlotRepository` via `getBlockedIntervals` | **Broader** — explicit blocks. |
| **Same-calendar-day window** (buffer-expanded service window must stay on one date) | `isStaffWindowAvailable` | **Technical guard** on how buffers are applied. |
| **Overlapping appointments for same staff** | `hasBufferedAppointmentConflict` (private) | **This is the core “no double booking” / exclusivity check** for the booking pipeline: uses **each existing appointment’s service** `buffer_before_minutes` / `buffer_after_minutes` to expand **their** interval, then tests overlap against the **candidate** `[windowStart, windowEnd]` (candidate expanded by the **incoming** service’s buffers). |
| **Room occupancy** (optional) | `isSlotAvailable` internal search + room id + room setting | **Room** dimension — out of scope for staff concurrency, but same `isSlotAvailable` call stack. |

### 1.B Two different SQL notions of “staff overlap” (critical)

| Implementation | Location | Interval math | Statuses | Used on booking path? |
| --- | --- | --- | --- | --- |
| **`hasBufferedAppointmentConflict`** | `AvailabilityService` (private) | **Buffered** overlap vs existing rows (`DATE_SUB/DATE_ADD` with joined `services` buffers). | `scheduled`, `confirmed`, `in_progress`, `completed` (`BLOCKING_STATUSES`) | **Yes** — via `isStaffWindowAvailable` → `isSlotAvailable` / direct `isStaffWindowAvailable` callers. |
| **`AppointmentRepository::hasStaffConflict`** | `AppointmentRepository` | **Raw** `start_at` / `end_at` overlap (no per-service buffers on existing rows). | Excludes `cancelled`, `no_show` only (`EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES`) | **No** — **no PHP callers** found in `system/` (grep March 2026); **dead for runtime enforcement**. |

**Conclusion:** The **authoritative** staff double-book prevention for scheduling is **`hasBufferedAppointmentConflict`**, not `hasStaffConflict`. Product language “staff concurrency” must specify whether bypass means **buffer-aware** overlap only, or also **raw** overlap, and must not assume a single repository primitive today.

---

## 2) Enforcement points (files / methods / classification)

### 2.A Core pipeline

| Method | File | Role |
| --- | --- | --- |
| **`isSlotAvailable`** | `AvailabilityService.php` | **Hub:** eligibility → duration/end → `isStaffWindowAvailable` → optional internal room filter. |
| **`isStaffWindowAvailable`** | `AvailabilityService.php` | **Authoritative staff window:** active staff + branch match → optional schedule/breaks/blocked → **`hasBufferedAppointmentConflict`**. |
| **`hasBufferedAppointmentConflict`** | `AvailabilityService.php` (private) | **Write/search-shared** staff **appointment** overlap (buffered, blocking statuses). |
| **`getAvailableSlots`** | `AvailabilityService.php` | **Search:** iterates candidates; calls `isSlotAvailable(..., forAvailabilitySearch: true, ...)` — interacts with wave **07** (see §7). |

### 2.B `AppointmentService` (writes)

| Method | Staff-related behavior |
| --- | --- |
| **`insertNewSlotAppointmentWithLocks`** | `isSlotAvailable(..., forAvailabilitySearch: false, forPublicBookingAvailabilityChannel: …)` — **full** `isStaffWindowAvailable` path for booking. |
| **`buildServiceBasedMovePatchAfterAppointmentLock`** | Same `isSlotAvailable` for moves/reschedule (internal vs public channel flag). |
| **`checkConflicts`** | **`isStaffWindowAvailable`** directly (not `isSlotAvailable`) with service buffers — **schedule enforced** (`true`), **internal** channel (`false` for public off-day bypass). Used for **manual** create/update paths that use `checkConflicts`. |
| **`lockActiveStaffAndServiceRows`** | **`FOR UPDATE`** on `staff` / `services` — **concurrency serialization**, not overlap calculation. |

### 2.C Other modules

| Caller | File | Notes |
| --- | --- | --- |
| Waitlist auto-offer eligibility | `WaitlistService.php` | `isStaffWindowAvailable(...)` with default schedule enforcement — **buffered conflicts** apply. |
| Public booking | `PublicBookingService` → `AppointmentService::createFromPublicBooking` | Uses **`isSlotAvailable`** with **public** channel → **no** internal off-day bypass (wave **08**). |

### 2.D Read-side / display (non-authoritative for writes)

| Method | File | Role |
| --- | --- | --- |
| **`getStaffAvailabilityForDate`**, **`getStaffAppointmentSlotsForDate`** | `AvailabilityService.php` | **Display/diagnostics:** lists working/break/blocked/**raw** appointment intervals (status filter matches `BLOCKING_STATUSES` for listed appointments). Does **not** replace write checks. |
| Day calendar / JSON | `AppointmentController` + views | Renders stored appointments; **does not** re-run `hasBufferedAppointmentConflict`. |

### 2.E Repository

| Method | Role today |
| --- | --- |
| **`AppointmentRepository::hasStaffConflict`** | **Unused** on booking path; **semantic drift risk** vs `hasBufferedAppointmentConflict`. |

---

## 3) Exact current runtime truth (plain English)

### 3.A Staff “double book” rule (authoritative)

For a **candidate** booking on staff `S` from service-derived **`startAt` → `endAt`**, define:

- **`windowStart`** = `startAt` minus the **candidate service’s** `buffer_before_minutes`
- **`windowEnd`** = `endAt` plus the **candidate service’s** `buffer_after_minutes`
- **`windowStart` / `windowEnd` must fall on the same calendar date** as `startAt` (buffers cannot push the logical window across midnight in this implementation).

Then **reject** if there exists another **non-deleted** appointment for the **same** `staff_id` with **status** in **`scheduled`, `confirmed`, `in_progress`, `completed`**, such that:

\[
\text{DATE\_SUB(existing.start, existing.buffer\_before)} < windowEnd
\]
\[
\text{and DATE\_ADD(existing.end, existing.buffer\_after)} > windowStart
\]

(Existing row’s buffers come from **`services`** joined to **`appointments.service_id`**.)

**Self-exclusion:** `excludeAppointmentId` omits one row (used on update/reschedule/move with current id).

**Branch:** The SQL **does not** filter `appointments.branch_id`; overlap is **global per staff_id** across branches in this query (same as documented for `hasStaffConflict` parity comment on “global per staff”).

### 3.B Additional gates (always layered unless search skips schedule — wave 07)

Unless **`forAvailabilitySearch` is true** and **`check_staff_availability_in_search` is false**, the same call also requires:

- Staff **working intervals** for the day (or internal off-day synthetic envelope per wave **08**),
- No overlap with **breaks** or **staff blocked slots**,
- Plus **eligibility / active / branch** checks as in §1.A.

**Booking paths** use `forAvailabilitySearch: false` → **full** stack.

### 3.C Public vs internal

- **Public** `isSlotAvailable` / `getAvailableSlots(..., 'public')`: **`forPublicBookingChannel: true`** → **never** applies internal off-day synthetic intervals (wave **08**).
- **Internal:** off-day bypass **can** apply when setting + empty working intervals + not `closed` exception.

### 3.D Search vs write (drift)

- **Search** can **omit** schedule/breaks/blocked when **`appointments.check_staff_availability_in_search`** is **false** (wave **07**), but **still** runs **`hasBufferedAppointmentConflict`**.
- **Write** always runs the **full** schedule + breaks + blocked + buffered conflict stack (unless future code changes).

So **search can already show** times that **fail on write** for schedule reasons; **buffered appointment overlap** is still enforced on both when using `isSlotAvailable`.

---

## 4) Semantic risk analysis (required questions)

### A. If we added “allow staff concurrency”, what would be bypassed?

From current coupling in **`isStaffWindowAvailable`**, a **naive** single boolean is ambiguous:

| If bypass targets only `hasBufferedAppointmentConflict` | Same staff could get **raw overlapping appointments**; buffers would no longer prevent adjacent-service collisions; **schedule/breaks/blocked** would still block unless separately bypassed. |
| If bypass also skipped breaks/blocked/schedule | Effectively “always bookable staff” — **unsafe** and unrelated to screenshot wording. |

**Minimum honest scope** for a “concurrency” product line: **bypass only the buffered appointment-overlap check** (`hasBufferedAppointmentConflict`), keeping schedule/breaks/blocked/eligibility/branch/active/room rules — and still requiring **search/write parity** for that bypass (like room overbooking).

### B. What must remain enforced (typical product expectation)

Even if double-book were allowed:

- **Inactive staff**, **wrong branch**, **service–staff eligibility**, **staff group scope** — still enforced by current gates.
- **Branch closed day / operating hours** on appointment writes — enforced in `AppointmentService` (`assertWithinBranchOperatingHours`, wave **06** end-after-close setting) — **separate** from `isStaffWindowAvailable` but still blocks bad writes.
- **Room** rules — independent setting (`allow_room_overbooking`).
- **Breaks / blocked slots / working hours** — should remain unless product explicitly defines otherwise (they are **not** the same as “two appointments at once” in a narrow sense).

### C. One clean enforcement point?

**No.** Staff exclusivity for booking is **centralized in logic** as **`hasBufferedAppointmentConflict`**, but it is **inseparable** in the public API **`isStaffWindowAvailable`** from schedule/break/blocked behavior. **`checkConflicts`** duplicates the **call pattern** (direct `isStaffWindowAvailable`) vs slot pipeline (`isSlotAvailable`). **`hasStaffConflict`** is a **second, unused** SQL definition.

### D. Separate internal vs public behavior?

**Likely yes**, by analogy to wave **08** (off-days): public channel already differs. Any future toggle should state explicitly whether **public online booking** may double-book staff, or **internal only**.

### E. Downstream consumers assuming non-concurrent staff?

- **Calendar displays** show whatever rows exist; they do **not** prevent overlap if DB allowed it.
- **Waitlist auto-offer** uses `isStaffWindowAvailable` — would **offer** into “free” windows; if writes allowed overlap, offers could still be consistent with **buffered** truth unless offer path diverges.
- **Reports** not exhaustively audited here; anything that counts “busy” staff from raw appointments may **not** include buffer expansion unless it reuses the same SQL.

---

## 5) Interaction with existing settings (waves 07 / 08)

| Setting | Key | Interaction with staff overlap |
| --- | --- | --- |
| **Check staff availability in search** | `appointments.check_staff_availability_in_search` (wave **07**) | When **false**, **search only** skips **working intervals / breaks / blocked** inside `isStaffWindowAvailable`; **`hasBufferedAppointmentConflict` still runs**. Does **not** implement “concurrency”. |
| **Staff booking on off days** | `appointments.allow_staff_booking_on_off_days` (wave **08**) | **Internal only**; when working intervals empty, may substitute **branch open–close** envelope; **never** on public channel; **does not** skip buffered overlap. |

These settings prove the codebase already treats **“search breadth”** and **“off-day shape”** as **orthogonal** to **appointment overlap** — a future **concurrency** toggle should be designed **equally explicitly**, not folded into 07/08.

---

## 6) Implementation readiness verdict

### **VERDICT B — NEEDS NARROWER CHARTER FIRST**

**Reasons (summary):**

1. **Ambiguous product mapping:** “Allow staff concurrency” bundles **buffered double-booking** with **schedule/break/blocked** reality in one mental checkbox; code does **not** expose one toggle-sized primitive.
2. **Duplicate SQL truth:** `hasStaffConflict` vs `hasBufferedAppointmentConflict` — **canonicalization** (or removal/wiring) should precede a setting.
3. **Search/write parity:** Wave **07** already allows **search/write drift** on **schedule**; adding overlap bypass only on one side would be **dangerous** without an explicit parity charter (as done for room).
4. **Channel policy:** Public vs internal already differs for off-days; concurrency needs an explicit **channel matrix**.

**VERDICT A** is **not** supported: there is **not** exactly one clean, unambiguous enforcement point exposed as a single policy switch without further design.

---

## 7) Recommended next charters (in suggested order)

1. **`STAFF-OVERLAP-CANONICALIZATION-01`** — Declare **`hasBufferedAppointmentConflict`** the single normative staff overlap primitive for booking; **delete or implement callers for `hasStaffConflict`** to remove drift; document status/buffer/branch rules in one ops doc (mirror room F14 style).
2. **`STAFF-OVERLAP-SEARCH-WRITE-PARITY-01`** — If a bypass is ever added, require **internal search and write** to share the same **overlap** decision (analogous to room overbooking), independent of wave **07** schedule skipping.
3. **`STAFF-DOUBLE-BOOK-SETTING-01`** (name TBD with product) — Only after (1)–(2): branch-aware bool, default **false**, semantics **explicitly** “bypass **buffered appointment overlap** only” (or narrower/wider per product), plus **internal vs public** matrix.

**Do not** reuse generic **“resource”** naming; staff is not room.

---

## 8) Intentionally out of scope (this audit)

Room overbooking, off-day redesign, end-after-closing, packages, check-in, staff–room lock, print, class/course settings, UI, schema changes, new settings keys (audit-time charter; see **§9** for later SETTING-01).

---

## 9) Implementation update (SETTING-01)

**Implemented:** `APPOINTMENT-STAFF-CONCURRENCY-SETTING-01-OPS.md` — canonical key **`appointments.allow_staff_concurrency`**; internal channel skips **`hasBufferedAppointmentConflict`** only when **true**; public channel unchanged. Verifier: `system/scripts/verify_appointment_allow_staff_concurrency_setting_01.php`.

---

## 10) Proof (audit wave)

This wave is **documentation-only** (static code inspection). Re-grep after changes to:

- `AvailabilityService.php` (`isSlotAvailable`, `isStaffWindowAvailable`, `hasBufferedAppointmentConflict`, `getAvailableSlots`)
- `AppointmentService.php` (`checkConflicts`, `insertNewSlotAppointmentWithLocks`, `buildServiceBasedMovePatchAfterAppointmentLock`)
- `AppointmentRepository.php` (`hasStaffConflict`)
- `WaitlistService.php` (`isStaffWindowAvailable`)
