# Appointments Phase 1.4 Progress

## Changed Files

- `system/modules/appointments/views/calendar-day.php`
- `system/modules/appointments/services/AvailabilityService.php`
- `system/public/assets/css/app.css`
- `system/docs/appointments-phase-1-4-progress.md`

## Rendering Approach Used

- Kept the same Calendar tab entry point and existing calendar routes.
- Replaced table cell start-row rendering with a staff-column operational timeline view.
- Used a client-side view-model builder (`buildCalendarViewModel`) to transform API payload into renderable blocks.
- Preserved current date/branch filters and current blocked-slot create/delete section on the same page.

## How Appointment/Block Coordinates Are Computed

- Time window comes from `time_grid.day_start` / `time_grid.day_end`.
- Each item uses:
  - `start_min = minutes(start_at)`
  - `end_min = minutes(end_at)`
  - clamp to day window
- Coordinate formulas:
  - `top_px = (start_min - day_start_min) * pixels_per_minute`
  - `height_px = max((end_min - start_min) * pixels_per_minute, min_block_height)`
- Rendering is per staff column:
  - appointments -> `ops-block-appt`
  - blocked slots -> `ops-block-blocked`
- Appointments include `client_name` and `service_name` in the payload for better block labels.

## Availability/Data Consistency Notes

- Availability engine was not rewritten.
- Existing blocked-slot exclusion logic remains the source of truth for slot availability.
- Calendar API data is still sourced from `AvailabilityService` methods:
  - appointments by staff
  - blocked slots by staff
  - day grid boundaries

## Limitations Intentionally Left for Phase 1.5

- No drag/drop or resize interactions.
- No room/equipment lanes yet (staff-focused columns only).
- No overlap lane splitting algorithm (current model assumes no overlapping appointments per staff).
- No zoom levels or week/multi-day canvas.
- No edit-in-place from calendar blocks.

## Risks / Follow-ups

- If legacy data contains overlapping blocks for same staff, visual overlap may hide one block.
- Very dense schedules can produce crowded block labels; truncation is currently used.
- Full multi-resource parity (staff + room/equipment simultaneous lanes) still pending.
