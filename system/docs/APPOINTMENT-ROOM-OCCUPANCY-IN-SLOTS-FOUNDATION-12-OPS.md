# Room occupancy in internal slot search (FOUNDATION-12)

**Task:** `APPOINTMENT-ROOM-OCCUPANCY-IN-SLOTS-12`  
**Scope:** Appointment **room** occupancy parity for **internal** slot generation only. No room-overbooking setting, no public booking changes, no schema changes, no forced `room_id` on slot-backed creates.

---

## Runtime truth (this wave)

### What changed

- **`AvailabilityService::getAvailableSlots`** accepts an optional 6th argument: **`?int $roomIdForOccupancy`** (default `null`).
  - When **`$slotQueryAudience === 'internal'`** and **`$roomIdForOccupancy > 0`**, each candidate time is rejected if **`AppointmentRepository::hasRoomConflict`** would be true for that room and the **service-duration window** (`start_at` → `start_at + duration`), using the **same SQL semantics as write-time room checks** (raw interval overlap; statuses **`cancelled`** / **`no_show`** excluded; same branch predicate as the repository).
  - When the audience is **`public`**, the room argument is **ignored** (slot list unchanged vs omitting it).
- **`AvailabilityService::isSlotAvailable`** accepts an optional 8th argument: **`?int $roomIdForOccupancyInSearch`** (default `null`).
  - Applied **only** when **`$forAvailabilitySearch === true`**, **`$forPublicBookingChannel === false`**, and room id is positive — i.e. **internal search**, not final booking validation.
  - **Booking paths** (`$forAvailabilitySearch === false`) **do not** apply this check; behavior matches pre–wave-12.

### HTTP (internal)

- **`GET /appointments/slots`** (authenticated, `appointments.view`): optional query **`room_id`** (positive integer). When present, it must be a room listed for the resolved branch scope via **`RoomListProvider`**; otherwise **422**. Response JSON includes **`data.room_id`**: the filter applied (`null` when omitted).

### What did not change (wave 12 only)

- **Public online booking** slot APIs: still call **`getAvailableSlots`** with default **`public`** audience — **no** room occupancy filter.
- **No** service buffer expansion for **room** overlap (same as **`hasRoomConflict`** today).
- **No** `appointments.allow_room_overbooking` (or similar) setting.

**Follow-up:** **FOUNDATION-13** optional room on internal slot create (`APPOINTMENT-CREATE-FROM-SLOT-ROOM-PERSISTENCE-FOUNDATION-13-OPS.md`); **FOUNDATION-14** canonical `hasRoomConflict` + write helper (`APPOINTMENT-ROOM-CONFLICT-CANONICALIZATION-FOUNDATION-14-OPS.md`). Wave 12 doc above describes **slot search** only.

---

## Verification

From repo `system/` directory:

```bash
php scripts/verify_appointment_room_occupancy_in_slots_foundation_12.php --branch-code=SMOKE_A
```

**Proves (when fixtures exist):** conflicting time appears in internal slots without room filter for an alternate staff member, disappears with **`room_id`**; **`isSlotAvailable`** search mode respects room; booking-mode **`isSlotAvailable`** ignores the room argument; public slot list ignores a non-null room argument.

**May SKIP:** no qualifying appointment with **`room_id`** on the branch, or no second staff on the same service (cannot isolate room vs staff conflict).

---

## Relation to FOUNDATION-11

See **`RESOURCE-OVERBOOKING-TRUTH-AUDIT-FOUNDATION-11-OPS.md`**. This wave closes the **search** gap: **internal** operators that pass **`room_id`** on **`GET /appointments/slots`** see slot lists consistent with **`hasRoomConflict`**. Optional **create** persistence is **`CREATE-FROM-SLOT-OPTIONAL-ROOM-PERSISTENCE-13`** / **`APPOINTMENT-CREATE-FROM-SLOT-ROOM-PERSISTENCE-FOUNDATION-13-OPS.md`**. Public/manage-token flows remain **out of scope** for room here.

---

## Recommended next task

**`ROOM-OVERBOOKING-SETTING-01`** — optional room on internal slot create is implemented in **FOUNDATION-13**; overbooking policy remains undefined.
