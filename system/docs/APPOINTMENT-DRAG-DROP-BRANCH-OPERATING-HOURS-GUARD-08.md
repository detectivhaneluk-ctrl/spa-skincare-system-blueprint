# APPOINTMENT-DRAG-DROP-BRANCH-OPERATING-HOURS-GUARD-08

## Scope
- Focused on internal calendar schedule-mutation safety for drag/drop-style reschedule requests.
- No public-booking changes, no slot engine rewrite, no staff-schedule merge, no UI redesign.

## Exact Drag/Drop Mutation Path Audited
- Calendar day read endpoint: `GET /calendar/day` via `AppointmentController::dayCalendar()`.
- Contract signal in payload: `capabilities.move_preview = false` (no dedicated move-preview endpoint active).
- Internal schedule mutation endpoint used for calendar-style rescheduling:  
  `POST /appointments/{id}/reschedule` via `AppointmentController::rescheduleAction()` -> `AppointmentService::reschedule()`.

## Guard Coverage Finding
- Branch operating-hours enforcement was already present in canonical service path from Wave 07:
  - `AppointmentService::reschedule()` uses `buildServiceBasedMovePatchAfterAppointmentLock()`
  - that path calls `assertWithinBranchOperatingHours(...)`
- So backend enforcement for reschedule interval already existed and was not bypassed in service layer.

## Gap Closed in This Wave
- Hardened `rescheduleAction()` to support JSON/AJAX callers (drag/drop-compatible transport):
  - success: JSON `{ success: true, data: { appointment_id } }` (HTTP 200)
  - policy/validation failures (including branch-hours violations): JSON `{ success: false, error: "..." }` (HTTP 422)
  - unexpected failures: JSON `{ success: false, error: "Failed to reschedule appointment." }` (HTTP 500)
- Existing form-post behavior (flash + redirect) is preserved.
- Optional optimistic-concurrency field `expected_current_start_at` is now passed through to service when provided.

## Error Behavior
- User-facing branch-hours messages come from the canonical guard:
  - `Opening hours are not configured for this branch on the selected day.`
  - `This branch is closed on the selected day.`
  - `The selected time falls outside this branch's operating hours (HH:MM-HH:MM).`
- Raw SQL/internal exceptions are not exposed on unexpected errors in JSON mode.

## No Data Corruption Guarantee
- Rejected reschedule requests throw before repository update.
- Service transaction rolls back on failure.
- Appointment row remains unchanged when guard rejects the move.
