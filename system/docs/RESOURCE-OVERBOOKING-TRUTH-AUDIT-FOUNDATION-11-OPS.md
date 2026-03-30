# Resource overbooking — truth audit (FOUNDATION-11)

**Task:** `RESOURCE-OVERBOOKING-TRUTH-AUDIT-11`  
**Scope:** Appointment / room / availability / conflict behavior only. **No settings, no UI, no schema changes** in this wave.

---

## 1) What “resource” means in this repo (exact domain truth)

### Tables / fields tied to schedulable exclusivity

| Artifact | Exists? | Used for scheduling conflict? |
| --- | --- | --- |
| **`appointments.room_id`** → `rooms` | Yes (`025_create_appointments_table.sql`, FK to `rooms`) | **Yes** — only field driving **room double-booking** checks. |
| **`service_rooms`** (`service_id`, `room_id`) | Yes (`023_create_service_mappings.sql`) | **Eligibility / catalog** — which rooms a service may use. Loaded in `ServiceRepository` as `room_ids`. **Not** used in `hasRoomConflict` or `AvailabilityService` overlap logic. |
| **`equipment`**, **`service_equipment`** | Yes | **Service catalog / requirements only.** Appointments have **no** `equipment_id` (or similar). **No** equipment exclusivity or overlap queries in the appointment pipeline. |
| **Chair / bed / station** | No dedicated entities | N/A — not modeled separately from **rooms**. |

### Dead / unused for conflict purposes

- **`service_equipment`**: not referenced from appointment create/update/conflict code paths audited here (appointment module does not join equipment for conflicts).
- **Generic “resource” row** for bookings: **does not exist**; “resource overbooking” in product language maps, in code, to **room overlap on `appointments.room_id`** only.

---

## 2) Enforcement points (files / methods / role)

### Authoritative room overlap (write-time)

| Location | Role |
| --- | --- |
| **`AppointmentRepository::hasRoomConflict`** (`AppointmentRepository.php`) | **Authoritative SQL** (normative docblock + **`EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES`**). **FOUNDATION-14:** `APPOINTMENT-ROOM-CONFLICT-CANONICALIZATION-FOUNDATION-14-OPS.md`. |
| **`AppointmentRepository::lockRoomRowForConflictCheck`** | **Serialization:** `SELECT … FROM rooms … FOR UPDATE` before `hasRoomConflict` (same file). |
| **`AppointmentService::checkConflicts`** (`AppointmentService.php`) | **Write-time orchestration** for manual create/update (non–service-move branch): locks room row when `room_id` set; calls `hasRoomConflict`. Also runs **staff** availability via `AvailabilityService::isStaffWindowAvailable` (with service buffers). |
| **`AppointmentService::buildServiceBasedMovePatchAfterAppointmentLock`** | **Write-time** moves: `assertRoomInScope` + **`lockRoomRowAssertCanonicallyFreeOrThrow`** (delegates to `lockRoomRowForConflictCheck` + `hasRoomConflict`). |

### Staff overlap (not “resource” but adjacent)

| Location | Role |
| --- | --- |
| **`AvailabilityService::hasBufferedAppointmentConflict`** | **Staff** overlapping appointments using **buffer-expanded** window and statuses **`scheduled`, `confirmed`, `in_progress`, `completed`**. Used by `isStaffWindowAvailable` → `isSlotAvailable` / slot search. |
| **`AppointmentRepository::hasStaffConflict`** | **Repository-level** staff overlap (`start_at`/`end_at`, excludes `cancelled`/`no_show`). **Not** the primary path for slot checks; documented as global per `staff_id`. |

### Search / read-side (slots)

| Location | Role |
| --- | --- |
| **`AvailabilityService::isSlotAvailable` / `isStaffWindowAvailable` / `getAvailableSlots`** | **Staff- and schedule-centric** (+ buffered staff appointment conflicts). **FOUNDATION-12:** optional **`room_id`** on **internal** slot search only — when set, **`hasRoomConflict`** is evaluated for each candidate window (same SQL as writes). **Public** audience ignores room for slot lists. See **`APPOINTMENT-ROOM-OCCUPANCY-IN-SLOTS-FOUNDATION-12-OPS.md`**. |

### Booking channels and room assignment

| Path | Room at insert |
| --- | --- |
| **`AppointmentService::insertNewSlotAppointmentWithLocks`** (used by **public booking**, **internal slot create**, **series occurrence**) | **Public / series:** **`room_id` => `null`**; **no** room conflict on insert. **Internal `createFromSlot`:** optional **`room_id`** → **`lockRoomRowAssertCanonicallyFreeOrThrow`** (**FOUNDATION-13** + **14**). |
| **`AppointmentService::create`** (internal form create) | Uses **`checkConflicts`** → room enforced when `room_id` is set. |
| **`PublicBookingService`** | Surfaces safe error string **`Room is booked for another appointment at this time.`** (from `AppointmentService` move/reschedule paths); **anonymous book** itself does not assign a room at create. |

### Other modules

| Area | Room conflict? |
| --- | --- |
| **`WaitlistService`** | **No** room/conflict references found in audited file. |
| **Day calendar JSON / UI** | Renders data; **does not** enforce room exclusivity (no duplicate of `hasRoomConflict`). |
| **Reports** | **No** `hasRoomConflict` usage outside appointments module (repo-wide grep). |

### Classification summary

- **Single SQL definition** of room exclusivity: **`AppointmentRepository::hasRoomConflict`**.
- **Multiple call sites** with **different surrounding rules** (manual create vs service-based move vs slot insert with null room).
- **Search vs write (FOUNDATION-12–14):** internal slot search **with** optional **`room_id`** and all internal writes that set **`room_id`** use the **same** **`hasRoomConflict`** SQL. **`lockRoomRowAssertCanonicallyFreeOrThrow`** centralizes throw-path locking + recheck for moves and internal slot create. Public booking **still** ignores room for slots and create.

---

## 3) Exact current runtime rule (plain English)

When **`appointments.room_id`** is a positive id and the write path runs **`hasRoomConflict`**:

1. There is a conflict if **another non-deleted appointment** shares that **`room_id`**, has **overlapping** **`start_at` / `end_at`** (strict interval overlap: `start_at < proposed_end` AND `end_at > proposed_start`), is **`id != exclude`**, and:
   - is **not** `cancelled` or `no_show`, and
   - matches the **branch predicate** above (branch-scoped or `NULL` branch rows per SQL).
2. **Service buffer minutes are not applied** to room overlap (unlike staff checks in `checkConflicts`, which use buffered windows via `isStaffWindowAvailable`).
3. **`FOR UPDATE` on `rooms`** serializes concurrent writers targeting the same room row.

**If `room_id` is null** (typical for slot/public/series creates): **no** room conflict check runs on insert.

---

## 4) Semantic risk analysis (from code truth)

| Question | Answer |
| --- | --- |
| **A. What would “allow resource overbooking” bypass?** | In today’s code, only **`hasRoomConflict`** (and thus **room** double-booking prevention on paths that set **`room_id`**). There is **no** equipment/station exclusivity to bypass. |
| **B. Room only or all “resources”?** | **Room only** (`appointments.room_id`). Equipment is not a booking constraint. |
| **C. Search vs write contradiction?** | **Internal + room_id:** aligned (**FOUNDATION-12** search + **14**). **Internal without `room_id` on slot search** or **public** slots: still **no** room column in search — a slot may appear until a write assigns a room (**FOUNDATION-14** doc). |
| **D. One clean enforcement point?** | **One SQL primitive** (`hasRoomConflict`) + shared service helper for locked writes; staff checks still use **buffers** while room uses **raw** intervals. |
| **E. Internal vs public?** | **Public book** creates with **`room_id` null** — room conflict **not** exercised on create. **Reschedule / move** paths can still throw **room booked** when a room is carried or set. Any future “overbooking” flag must define **per channel** behavior explicitly. |
| **F. Downstream assumptions?** | Calendar/reporting **displays** appointments; they do **not** re-validate room exclusivity. Operators may **assume** the UI prevents double room booking for **manual** paths only. |

---

## 5) Implementation readiness verdict

### **VERDICT B — NEEDS NARROWER CHARTER FIRST**

**Reason (summary):**  
“Allow resource overbooking” is **ambiguous** against current behavior: the only enforceable dimension is **room**, but **slot search and several create paths ignore room entirely**, and **room vs staff conflict use different overlap mathematics** (raw vs buffered). Shipping a single boolean **`appointments.allow_resource_overbooking`** without **parity and naming** decisions would be **unsafe** and **misleading**.

---

## 6) Recommended next charters (exact naming)

Implement **in order** (suggested task names):

1. **`ROOM-CONFLICT-SEARCH-WRITE-PARITY-01`** (or **`APPOINTMENT-ROOM-OCCUPANCY-IN-SLOTS-01`**)  
   Decide whether slot / `isSlotAvailable` should accept an optional **`room_id`** (or derive room from service defaults) so **search and write** agree — **before** any “overbooking” toggle.

2. **`ROOM-CONFLICT-BUFFER-AND-STATUS-CANONICALIZATION-01`**  
   Align **status set** and **buffer expansion** for room vs staff if product requires one consistent rule.

3. **`ROOM-OVERBOOKING-SETTING-01`** — **implemented:** **`appointments.allow_room_overbooking`** (internal room-aware paths only; public channel always enforces room when applicable). **`APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md`**.

Do **not** use a generic **`appointments.allow_resource_overbooking`** until “resource” is defined in UI/docs as **room-only** or equipment gains a real booking model.

---

## 7) What remains intentionally out of scope

Per charter: staff concurrency, off-day/search/end-after-closing redesigns, packages ops, check-in, staff–room lock, print system, class/course settings, UI redesign — **unchanged**.

---

## 8) Proof / verification

This wave is **documentation-only**. **No** runtime verifier added: the truth claims above are justified by **static code paths** in:

- `system/modules/appointments/repositories/AppointmentRepository.php` (`hasRoomConflict`, `hasStaffConflict`, `lockRoomRowForConflictCheck`)
- `system/modules/appointments/services/AvailabilityService.php` (`isSlotAvailable`, `isStaffWindowAvailable`, `hasBufferedAppointmentConflict`)
- `system/modules/appointments/services/AppointmentService.php` (`checkConflicts`, `buildServiceBasedMovePatchAfterAppointmentLock`, `insertNewSlotAppointmentWithLocks`, `createFromPublicBooking`, `createFromSeriesOccurrence`, `create`, `update`)

Re-run this audit after any change to those methods.
