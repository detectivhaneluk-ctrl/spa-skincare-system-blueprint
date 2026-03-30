# Appointments Phase 1.3 Progress

## Changed Files

- `system/routes/web.php`
- `system/modules/bootstrap.php`
- `system/modules/appointments/controllers/AppointmentController.php`
- `system/modules/appointments/services/AvailabilityService.php`
- `system/modules/appointments/services/AppointmentService.php`
- `system/modules/appointments/services/WaitlistService.php`
- `system/modules/appointments/services/BlockedSlotService.php`
- `system/modules/appointments/repositories/WaitlistRepository.php`
- `system/modules/appointments/repositories/BlockedSlotRepository.php`
- `system/modules/appointments/views/waitlist.php`
- `system/modules/appointments/views/waitlist-create.php`
- `system/modules/appointments/views/calendar-day.php`
- `system/public/assets/css/app.css`
- `system/data/migrations/039_create_appointment_waitlist_and_blocked_slots.sql`
- `system/data/full_project_schema.sql`
- `system/docs/appointments-phase-1-3-progress.md`

## New Routes

- `GET /appointments/waitlist`
- `GET /appointments/waitlist/create`
- `POST /appointments/waitlist`
- `POST /appointments/waitlist/{id}/status`
- `POST /appointments/waitlist/{id}/link-appointment`
- `POST /appointments/blocked-slots`
- `POST /appointments/blocked-slots/{id}/delete`

All routes are additive and existing appointment URLs are preserved.

## New Tables

- `appointment_waitlist`
  - `id, branch_id, client_id, service_id, preferred_staff_id, preferred_date, preferred_time_from, preferred_time_to, notes, status, created_by, matched_appointment_id, created_at, updated_at`
- `appointment_blocked_slots`
  - `id, branch_id, staff_id, title, block_date, start_time, end_time, notes, created_by, created_at, updated_at, deleted_at`

## Business Rules Added

- **Waitlist statuses**: `waiting`, `matched`, `booked`, `cancelled`.
- **Waitlist transition guard**:
  - `waiting -> matched|booked|cancelled`
  - `matched -> waiting|booked|cancelled`
  - `booked/cancelled` are terminal in this phase.
- **Waitlist validation**:
  - `preferred_date` required in `YYYY-MM-DD`.
  - optional time window must be valid and ordered.
- **Waitlist linking**:
  - can link to real appointment id.
  - cancelled waitlist entries cannot be linked.
- **Blocked slot validation**:
  - requires active `staff_id`.
  - requires valid date + time window with `end_time > start_time`.
  - delete is soft-delete via `deleted_at`.

## Audit Logging Added

- `appointment_waitlist_created`
- `appointment_waitlist_status_changed`
- `appointment_waitlist_linked`
- `appointment_blocked_slot_created`
- `appointment_blocked_slot_deleted`

## How Blocked Slots Affect Availability

- `AvailabilityService` now loads blocked intervals for the given staff/day/scope.
- Blocked intervals are merged with break intervals before slot generation.
- `isSlotAvailable()` now rejects slots that overlap blocked periods.
- `GET /calendar/day` now returns `blocked_by_staff`, and the day calendar view renders blocked items alongside appointments.

## Permissions Added/Used

- **No new permission keys were added in this phase.**
- Reused existing appointment permissions:
  - `appointments.view` for waitlist/calendar visibility
  - `appointments.create` for waitlist entry creation
  - `appointments.edit` for waitlist status/link and blocked-slot create/delete

## Postponed to Phase 1.4

- Waitlist auto-matching suggestions and booking conversion flow.
- Dedicated blocked-slot edit/update flow (only create + delete now).
- Multi-resource blocking (rooms/equipment lanes).
- Span-accurate calendar rendering (current renderer remains row-start based).
- Expanded policy controls for waitlist transitions and role-specific controls.
