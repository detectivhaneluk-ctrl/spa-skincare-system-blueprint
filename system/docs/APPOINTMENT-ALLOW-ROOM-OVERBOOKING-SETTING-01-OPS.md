# Allow room overbooking — setting 01 (room-only)

**Task:** `ROOM-OVERBOOKING-SETTING-01`  
**Scope:** Branch-aware appointment setting **`appointments.allow_room_overbooking`** (bool, default **false**). **Room-only** — not generic “resource” overbooking. **No** public booking room support, **no** room buffers, **no** auto-assignment.

---

## Storage & read rule

| Key | Type | Default | Group |
| --- | --- | --- | --- |
| `appointments.allow_room_overbooking` | bool | false | `appointments` |

Merged like other appointment keys: **`getAppointmentSettings(?int $branchId)`** — branch override when `branch_id > 0`, else organization default (`0`) + platform fallback.

**UI:** Settings → Appointment Settings → Scheduling — **Allow room overbooking**.

**Legacy:** Absent row → **`getBool(..., false)`** → behaves as **false** (enforce room exclusivity).

---

## Semantics

### When **false** (default)

Same as pre-setting behavior: internal room-aware search and writes use **`AppointmentRepository::hasRoomConflict`** (canonical rule from FOUNDATION-14).

### When **true**

- **Internal** flows that pass a **positive `room_id`** **skip** the **`hasRoomConflict`** check (search + write). **Staff** overlap, service buffers, branch hours, consent, eligibility, inactive entities, etc. **unchanged**.
- **Public** booking and **`forPublicBookingAvailabilityChannel`** paths **always** enforce room exclusivity when a room is in play — **this setting is not consulted** there (public remains room-agnostic on create; token reschedule with a room still cannot bypass via org/branch flag).

---

## Affected flows (setting consulted)

| Flow | Mechanism |
| --- | --- |
| Internal slot search with `room_id` | `AvailabilityService::isSlotAvailable` (search, non-public) + `shouldEnforceAppointmentRoomExclusivity` |
| Internal `createFromSlot` with optional `room_id` | `lockRoomRowAssertCanonicallyFreeOrThrow` (`forPublicBookingAvailabilityChannel === false`) |
| Manual create/update with `room_id` | `AppointmentService::checkConflicts` |
| Admin scheduling move / **internal** `reschedule` | `buildServiceBasedMovePatchAfterAppointmentLock` + helper |

## Unaffected / always enforce room when applicable

| Flow | Reason |
| --- | --- |
| `createFromPublicBooking` / public slot list | Public channel; typically no `room_id` |
| `reschedule(..., forPublicBookingAvailabilityChannel: true)` | Explicit bypass of setting |
| Flows with **no** `room_id` | Setting irrelevant |
| Series / waitlist slot create without room | No room conflict path |

---

## Proof

```bash
cd system
php scripts/verify_appointment_allow_room_overbooking_setting_01.php --branch-code=SMOKE_A
```

---

## Related

- `APPOINTMENT-ROOM-CONFLICT-CANONICALIZATION-FOUNDATION-14-OPS.md`
- `APPOINTMENT-SETTINGS-BACKEND-CONTRACT-FOUNDATION-01-OPS.md`
- `RESOURCE-OVERBOOKING-TRUTH-AUDIT-FOUNDATION-11-OPS.md` (historical “resource” wording vs room-only product truth)

**Note:** Older screenshots may say “Allow **resource** overbooking”; repository enforcement is **room-only** (`appointments.room_id`).
