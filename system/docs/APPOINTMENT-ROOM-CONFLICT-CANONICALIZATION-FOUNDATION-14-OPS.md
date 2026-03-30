# Room conflict canonicalization (FOUNDATION-14)

**Task:** `ROOM-CONFLICT-CANONICALIZATION-14`  
**Scope:** One **canonical** room-interval conflict rule and **one** SQL implementation, shared by every **internal** path that consults room occupancy. **No** room-overbooking setting, **no** public booking room support, **no** schema changes, **no** room buffers.

---

## Canonical room-conflict rule (normative)

A **room conflict** exists when there is at least one row in `appointments` such that:

| Rule | Value |
|------|--------|
| **Deleted** | `deleted_at IS NULL` |
| **Status** | `status` **not** in **`cancelled`**, **`no_show`** (see `AppointmentRepository::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES`) |
| **Room** | `room_id` equals the **positive** room under test |
| **Self** | `id != excludeAppointmentId` (0 = exclude nobody) |
| **Interval** | Raw overlap: `start_at < $endAt AND end_at > $startAt` (no service buffers on the room dimension) |
| **Branch** | If `$branchId` is non-null: `(branch_id = $branchId OR branch_id IS NULL)`. If `$branchId` is null: `branch_id IS NULL` only. |

**Authoritative implementation:** `AppointmentRepository::hasRoomConflict`.

**Staff** overlap (`hasStaffConflict`) uses the **same** status exclusion list and the **same** raw overlap shape (global per `staff_id`; branch parameter unused).

---

## Call sites using the canonical rule

| Layer | Method | Role |
|--------|--------|------|
| **Repository** | `AppointmentRepository::hasRoomConflict` | **Only** SQL definition |
| **Search (internal)** | `AvailabilityService::isSlotAvailable` (search + non-public + `roomIdForOccupancyInSearch`) | Delegates to `hasRoomConflict` for candidate windows |
| **Manual create/update** | `AppointmentService::checkConflicts` | Locks `rooms` row when `room_id` set, then `hasRoomConflict` |
| **Move / reschedule** | `AppointmentService::buildServiceBasedMovePatchAfterAppointmentLock` | `assertRoomInScope` → `lockRoomRowAssertCanonicallyFreeOrThrow` → same SQL |
| **Internal slot create** | `AppointmentService::insertNewSlotAppointmentWithLocks` (optional room) | Same as move path via `lockRoomRowAssertCanonicallyFreeOrThrow` |

**Write-time helper (throws on conflict):** `AppointmentService::lockRoomRowAssertCanonicallyFreeOrThrow` — `lockRoomRowForConflictCheck` + `hasRoomConflict`; used wherever the move/slot pipeline must fail fast with `DomainException`.

**Public booking:** `createFromPublicBooking` / public `isSlotAvailable` channel **do not** apply room occupancy; unchanged by design.

---

## Status / exclusion / branch alignment

- **Excluded from blocking:** `cancelled`, `no_show` only (both room and staff interval checks).
- **Still blocking for room:** `scheduled`, `confirmed`, `in_progress`, `completed`.
- **Self-exclusion:** `excludeAppointmentId` on update/reschedule/move is the current appointment id; slot create uses `0`.
- **Search vs write:** Internal slot search with `room_id` uses the **same** `hasRoomConflict` as writes; late races on write remain mitigated by `SELECT … FROM rooms … FOR UPDATE` before recheck.

---

## Residual non-parity (explicit)

- **Public** booking and public slot lists: **room-agnostic** (`room_id` null on create; no room filter on public audience).
- **Series occurrence** / **waitlist** conversion: typically **no** `room_id` on create — no room conflict on insert unless extended later.
- **No** `appointments.allow_room_overbooking` — **defer** until policy is defined **after** this canonicalization is proven in deployment.
- **No** room buffers; staff overlap still uses service buffers separately (`isStaffWindowAvailable`).
- **`service_rooms`**: catalog only; **not** part of `hasRoomConflict`.

---

## Verification

From repo `system/`:

```bash
php scripts/verify_appointment_room_conflict_canonicalization_foundation_14.php --branch-code=SMOKE_A
```

**Proves (when fixtures exist):** repository conflict + self-exclusion; internal search vs repo parity (alternate staff); booking/public channels ignore room arg; optional lone cancelled row does not block.

**May SKIP:** missing blocking appointment, alternate staff, or isolated cancelled fixture — see script output.

---

## Related docs

- `APPOINTMENT-ROOM-OCCUPANCY-IN-SLOTS-FOUNDATION-12-OPS.md` (internal search)
- `APPOINTMENT-CREATE-FROM-SLOT-ROOM-PERSISTENCE-FOUNDATION-13-OPS.md` (optional room on slot create)
- `RESOURCE-OVERBOOKING-TRUTH-AUDIT-FOUNDATION-11-OPS.md` (audit cross-links)

---

## Recommended next task

**`ROOM-OVERBOOKING-SETTING-01`** — **done:** **`appointments.allow_room_overbooking`** (see **`APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md`**).
