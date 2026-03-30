# CALENDAR-DAY-BRANCH-HOURS-HARD-CLAMP-REPAIR-11

Date: 2026-03-24  
Status: DONE (read-side calendar bounds repair)

## Problem (old partial behavior)

`AppointmentController::normalizeCalendarTimeGridBounds()` previously treated branch operating hours as an expansion-only hint:

- branch `open_time` only moved `day_start` earlier
- branch `close_time` only moved `day_end` later

That meant the default grid end could remain visible even when the branch closes earlier.  
Example: branch `06:30-17:00`, default grid end `18:00` -> `17:00-18:00` remained visible as shaded off-hours.

## New hard-clamp rule

For configured open days (`branch_hours_available=true`, `is_closed_day=false`):

- initialize visible bounds from branch hours first:
  - `day_start = open_time`
  - `day_end = close_time`
- then expand only if real appointment/blocked-slot bounds exist outside that branch envelope

Result:

- when no real outside-hours records exist, calendar visibly ends at branch close
- branch hours are now the primary visible calendar envelope

## Truth-preserving exception (outside-hours records)

Real appointments and blocked slots are never hidden.

If real data exists outside branch envelope:

- earlier record start expands `day_start` earlier
- later record end expands `day_end` later

So anomaly visibility is preserved while normal days stay hard-clamped to configured branch hours.

## Preserved contracts and metadata

This repair is read-side only and does not alter write paths or booking flows.

Unchanged:

- day calendar payload contract
- `branch_operating_hours` metadata fields
- `out_of_hours_appointments` anomaly count behavior
- closed-day handling (`is_closed_day=true`) truth behavior
- missing-hours fallback behavior when branch hours are unavailable or incomplete
