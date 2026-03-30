# Optional room persistence on internal slot-based create (FOUNDATION-13)

**Task:** `CREATE-FROM-SLOT-OPTIONAL-ROOM-PERSISTENCE-13`  
**Scope:** When an internal operator uses **optional** room context on the **slot-based** create path, the saved appointment may persist **`room_id`**. **No** public booking changes, **no** schema changes, **no** room-overbooking setting, **no** auto-assignment of rooms, **no** new service-to-room eligibility model.

---

## Consumer / channel boundary

### Supports optional `room_id` persistence (this wave)

| Surface | Path |
|--------|------|
| **HTTP** | **`POST /appointments/create`** → `AppointmentController::storeFromCreatePath` |
| **Service** | `AppointmentService::createFromSlot` → `insertNewSlotAppointmentWithLocks(..., $forPublicBookingAvailabilityChannel = false, $internalSlotOptionalRoomId)` |
| **UI** | Internal `create.php` (simplified path): optional **`room_id`** select; same value is sent on slot **`GET /appointments/slots`** when set |

**Validation / write-time enforcement (when `room_id` is present and positive):**

- `TenantOwnedDataScopeGuard::assertRoomInScope($roomId, $branchId)` — room must belong to tenant/branch truth already used elsewhere.
- `AppointmentRepository::lockRoomRowForConflictCheck` then `hasRoomConflict(..., $excludeAppointmentId = 0)` — same authoritative overlap semantics as other locked moves; **`DomainException`** `Room is booked for another appointment at this time.` on conflict.

**When `room_id` is omitted, empty, or non-positive:** behavior matches pre–wave-13 — **`room_id`** stored **`null`** on the new row.

### Does not support optional room on create (unchanged)

| Flow | Notes |
|------|--------|
| **Public online booking** | `AppointmentService::createFromPublicBooking` → `insertNewSlotAppointmentWithLocks(..., $forPublicBookingAvailabilityChannel = true, $internalSlotOptionalRoomId = null)` — **room argument ignored**; appointments remain room-agnostic. |
| **Series occurrence create** | `createFromSeriesOccurrence` passes **`null`** for internal optional room — not in scope for this wave. |
| **Manual create** | `POST /appointments` → `AppointmentController::store` → `AppointmentService::create` — separate path; unchanged unless form already sent `room_id` (not this wave’s charter). |
| **Manage-token / public reschedule** | Unchanged — no room persistence work here. |
| **Waitlist conversion** | Still calls `createFromSlot` **without** `room_id` in the payload — **null** persistence unless extended later. |

---

## Parity with FOUNDATION-12

- **FOUNDATION-12** (`APPOINTMENT-ROOM-OCCUPANCY-IN-SLOTS-FOUNDATION-12-OPS.md`): internal **`GET /appointments/slots`** may filter by **`room_id`** so the slot list matches **`hasRoomConflict`** for that room.
- **FOUNDATION-13** (this doc): internal **create-from-slot** may persist the same optional **`room_id`**, with **write-time** lock + **`hasRoomConflict`** so a late race still fails safely.
- **FOUNDATION-14** (`APPOINTMENT-ROOM-CONFLICT-CANONICALIZATION-FOUNDATION-14-OPS.md`): canonical room rule + **`lockRoomRowAssertCanonicallyFreeOrThrow`** on locked writes.

Together: **search parity + create persistence** for internal room-aware booking only.

---

## Runtime truth (unchanged vs new)

- **No** `appointments.allow_room_overbooking` (or similar) setting.
- **No** automatic room assignment when the operator leaves room unset.
- **No** new room buffer semantics — reuse existing **`hasRoomConflict`** SQL.
- Public slot/list APIs: still **ignore** room occupancy filter (wave 12 unchanged).

---

## Verification

From repo `system/` directory (requires PHP CLI and DB fixtures):

```bash
php scripts/verify_appointment_create_from_slot_room_persistence_foundation_13.php --branch-code=SMOKE_A
```

**Intends to prove:** create without **`room_id`** → **`room_id` null**; with valid **`room_id`** → persisted; foreign/out-of-scope room rejected; second booking same room/time (different staff) blocked at write time; internal slots with vs without explicit **`room_id`** query still align with wave-12 behavior where fixtures allow.

**May SKIP:** missing client/service/staff/room/slot fixtures; see script output for exact reason.

---

## Recommended next task

**`ROOM-OVERBOOKING-SETTING-01`** (product-defined) or extend **waitlist / series** slot creates with optional room only if product asks — not implied by this wave.
