# Appointment check-in — foundation implementation 01

**Task:** `APPOINTMENT-CHECK-IN-FOUNDATION-IMPLEMENTATION-01`  
**Truth basis:** `APPOINTMENT-SETTINGS-REMAINING-PARITY-TRUTH-WAVE-01-OPS.md` documented **no** prior check-in columns or settings; this wave adds the **smallest** persistent foundation plus an internal-only write path and read surface.

## Schema

- **Migration:** `system/data/migrations/095_appointments_check_in_foundation.sql`
- **`appointments.checked_in_at`** — `DATETIME NULL` — first recorded client arrival (operator action).
- **`appointments.checked_in_by`** — `BIGINT UNSIGNED NULL`, FK → `users(id)` ON DELETE SET NULL — session user at record time.

## Semantics

- **Orthogonal to status:** Check-in does **not** change `appointments.status`. Operators may still use **Update status** (e.g. `confirmed` → `in_progress`) separately.
- **Who may check in:** Status must be `scheduled`, `confirmed`, or `in_progress`. Not allowed for `completed`, `cancelled`, or `no_show`.
- **Idempotent:** If `checked_in_at` is already set, `markCheckedIn` returns without error and does not overwrite.
- **Scope:** Same branch/tenant rules as other appointment mutations (`TenantOwnedDataScopeGuard`, `BranchContext`).

## Runtime

| Piece | Location |
| --- | --- |
| Write | `POST /appointments/{id}/check-in` — `AppointmentController::checkInAction` — `appointments.edit` + auth + tenant middleware |
| Service | `AppointmentService::markCheckedIn` — transaction, `FOR UPDATE`, `AppointmentRepository::markCheckedIn` |
| Repository | `AppointmentRepository::markCheckedIn` — **not** in `normalize()`; avoids mass-assign from generic `update()` |
| Audit | `appointment_checked_in` with before/after snapshot + `checked_in_at` |
| Read | Appointment **show** — “Checked in” detail row (`checked_in_display`); toolbar **Check in** button when `can_mark_checked_in` |

## Not in this foundation

- No `appointments.*` setting (no toggle without a separate product decision).
- No public booking or calendar JSON changes.
- No automatic status transition on check-in.
- No undo/clear check-in (additive timestamp only).

## Verifier (static, no DB)

```bash
php scripts/verify_appointment_check_in_foundation_implementation_01.php
```

## Deploy note

Apply migration **095** before relying on the UI; without it, `markCheckedIn` will fail at SQL layer.
