# Appointments Phase 1.5 Progress

## Changed Files

- `system/modules/appointments/services/AvailabilityService.php`
- `system/modules/appointments/services/AppointmentService.php`
- `system/modules/appointments/services/WaitlistService.php`
- `system/modules/appointments/repositories/WaitlistRepository.php`
- `system/modules/appointments/controllers/AppointmentController.php`
- `system/modules/appointments/views/waitlist.php`
- `system/routes/web.php`
- `system/modules/bootstrap.php`
- `system/docs/appointments-phase-1-5-progress.md`

## Validation Rules Added

- **Central staff-time validation path** now enforces, for create/update/reschedule/slot checks:
  - valid non-negative time window
  - inside staff working intervals
  - not overlapping staff breaks
  - not overlapping blocked slots
  - not overlapping existing appointments (same staff + branch scope)
- **Branch-safe conflict matching** uses the existing rule style:
  - same branch only, or both global (`branch_id IS NULL`) when context is global.
- **Service buffer foundation** added:
  - uses `services.buffer_before_minutes` and `services.buffer_after_minutes`
  - applied to staff conflict window checks and slot availability checks.

## Conflict-Check Flow Used

- **Single core path**: `AvailabilityService::isStaffWindowAvailable()`
  - used by `AppointmentService::checkConflicts()` for create/update
  - used indirectly by `AvailabilityService::isSlotAvailable()` for slot and reschedule/create-from-slot checks
- `AppointmentService` still keeps room conflict checks through repository room overlap query.
- This removes duplicated staff overlap logic across service/controller paths.

## Waitlist Conversion Flow

- Added new route/action for conversion:
  - `POST /appointments/waitlist/{id}/convert`
- Conversion logic in `WaitlistService::convertToAppointment()`:
  - validates waitlist status (`waiting`/`matched`)
  - resolves required fields from waitlist + request override
  - reuses `AppointmentService::createFromSlot()` for booking
  - updates waitlist to `booked`
  - links `matched_appointment_id`
  - writes audit logs:
    - `appointment_waitlist_status_changed` (conversion source)
    - `appointment_waitlist_converted`
    - `appointment_waitlist_linked` (conversion source)
- UI: waitlist table now has a simple convert form per eligible row.

## Matching Helpers Added

- Waitlist server-side filtering expanded to include:
  - `date`
  - `status`
  - `service_id`
  - `preferred_staff_id`
  - existing `branch_id` scope
- Added lightweight “suggested for conversion” list generation using the same filters (favoring waiting/matched status).

## Postponed to Phase 1.6

- Automatic waitlist-to-slot recommendation ranking (smart scoring).
- Room/resource-level unified conflict engine (staff path hardened first).
- Advanced buffer rollout across all legacy/manual edge cases with migration-backed policy settings.
- Calendar inline conversion actions and richer conflict diagnostics in UI.
