# Allow staff concurrency (overlapping appointments) — setting 01

**Task:** `APPOINTMENT-STAFF-CONCURRENCY-SETTING-IMPLEMENTATION-01`  
**Scope:** Branch-aware appointment setting **`appointments.allow_staff_concurrency`** (bool, default **false**). Affects **buffered** overlap between **staff** appointments only (same primitive as `AvailabilityService::hasBufferedAppointmentConflict`). **Not** room/resource logic, **not** schedule/breaks/blocked slots, **not** hours/consent/eligibility.

**Related:** `STAFF-CONCURRENCY-TRUTH-AUDIT-FOUNDATION-02-OPS.md`, `APPOINTMENT-SETTINGS-BACKEND-CONTRACT-FOUNDATION-01-OPS.md`, `APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md` (analogous internal-vs-public split).

---

## Storage & read rule

| Key | Type | Default | Group |
| --- | --- | --- | --- |
| `appointments.allow_staff_concurrency` | bool | false | `appointments` |

Merged like other appointment keys: **`getAppointmentSettings(?int $branchId)`** — branch override when `branch_id > 0`, else organization default (`0`) + platform fallback.

**UI:** Settings → Appointment Settings → Staff — **Allow staff concurrency (overlapping appointments)**.

**Legacy:** Absent row → **`getBool(..., false)`** → behaves as **false** (enforce buffered staff overlap).

**Policy helper:** **`SettingsService::shouldEnforceBufferedStaffAppointmentOverlap(?int $branchId, bool $forPublicBookingChannel)`** — returns **false** only when internal channel **and** setting is **true**; always **true** when `$forPublicBookingChannel` is **true**.

---

## Semantics

### When **false** (default)

Same as pre-setting behavior: **`isStaffWindowAvailable`** runs **`hasBufferedAppointmentConflict`** (buffer-expanded overlap vs existing blocking-status appointments for the same `staff_id`).

### When **true**

- **Internal** (`forPublicBookingChannel === false`): **skips** the buffered staff appointment overlap check only. Still enforced: working intervals (or internal off-day envelope per `allow_staff_booking_on_off_days`), breaks, blocked slots, service–staff eligibility, staff active/branch scope, same-calendar-day buffer window, internal room occupancy when `room_id` is in play, `validateTimes`, `assertWithinBranchOperatingHours`, consent, etc.
- **Public** online booking / public slot search (`forPublicBookingChannel === true`): **always** enforces buffered staff overlap — **this setting is not consulted** (parity with `appointments.allow_room_overbooking` public behavior).

---

## Affected flows

| Flow | Mechanism |
| --- | --- |
| **`AvailabilityService::isStaffWindowAvailable`** | Gates **`hasBufferedAppointmentConflict`** via **`shouldEnforceBufferedStaffAppointmentOverlap`** |
| **`AvailabilityService::isSlotAvailable`** | Delegates to **`isStaffWindowAvailable`** |
| **`AvailabilityService::getAvailableSlots`** | Internal audience: honors setting; public audience: does not |
| **`AppointmentService::checkConflicts`** | **`isStaffWindowAvailable`** (internal) |
| **`AppointmentService` slot insert / move / reschedule** | **`isSlotAvailable`** with internal vs public channel flag |
| **`WaitlistService`** (auto-offer eligibility) | **`isStaffWindowAvailable`** — internal; respects branch setting |

---

## Proof

```bash
cd system
php scripts/verify_appointment_allow_staff_concurrency_setting_01.php --branch-code=SMOKE_A
```

**May SKIP:** no qualifying appointment row on the branch, or internal path blocked at the probe window for reasons other than staff overlap.

---

## Related

- `STAFF-CONCURRENCY-TRUTH-AUDIT-FOUNDATION-02-OPS.md` — authoritative overlap primitive (**`hasBufferedAppointmentConflict`**)
- `booker-modernization-booking-concurrency-contract.md`
