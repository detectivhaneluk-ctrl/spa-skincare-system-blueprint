# Appointments Phase 1.2 Progress

## What Was Changed

- Added a shared appointments workspace shell with tab navigation:
  - Calendar
  - List
  - New Appointment
  - Waitlist
- Kept existing pages and flows intact, but rendered workspace tabs on:
  - appointments list page
  - day calendar page
  - create appointment page
- Added a safe waitlist placeholder page and route (no waitlist business logic yet).
- Added small controller helpers to reduce duplication safely:
  - shared branch query parsing
  - shared date query parsing
  - shared workspace tab context builder
- Added minimal CSS for workspace tabs (no UI redesign).

## Files Modified

- `system/routes/web.php`
- `system/modules/appointments/controllers/AppointmentController.php`
- `system/modules/appointments/views/index.php`
- `system/modules/appointments/views/create.php`
- `system/modules/appointments/views/calendar-day.php`
- `system/public/assets/css/app.css`

## Files Added

- `system/modules/appointments/views/partials/workspace-shell.php`
- `system/modules/appointments/views/waitlist.php`
- `system/docs/appointments-phase-1-2-progress.md`

## Old Routes Compatibility

- Existing appointment routes are preserved and continue to work:
  - `/appointments`
  - `/appointments/create`
  - `/appointments/{id}`
  - `/appointments/{id}/edit`
  - `/appointments/calendar/day`
  - `/appointments/slots`
  - `/calendar/day`
- New route is additive and backward-compatible:
  - `/appointments/waitlist`

## Intentionally Postponed to Phase 1.3

- Waitlist database model and CRUD logic.
- Blocked slots model and availability integration.
- Multi-resource calendar lanes (rooms/equipment).
- Availability hardening beyond current behavior (tokens/idempotency, deeper overlap guarantees).
