# BOOKING-SLOT-GENERATION-BRANCH-OPERATING-HOURS-INTEGRATION-10

## Scope
- Integrated branch operating hours into canonical slot generation / availability read paths.
- Kept write-time guards in place (no regression).
- No UI redesign, no closure-date logic, no staff-schedule replacement.

## Exact Slot / Availability Paths Audited
- Internal slot API:
  - `GET /appointments/slots` -> `AppointmentController::slots()` -> `AvailabilityService::getAvailableSlots()`
- Public slot API:
  - `GET /api/public/booking/slots` -> `PublicBookingController::slots()` -> `PublicBookingService::getPublicSlots()` -> `AvailabilityService::getAvailableSlots()`
- Public manage reschedule slot list:
  - `POST /api/public/booking/manage/slots` (body `token`, `date`) -> `PublicBookingService::getManageRescheduleSlotsByToken()` -> `AvailabilityService::getAvailableSlots()`

## Canonical Path(s) Patched
- Canonical generator patched: `AvailabilityService::getAvailableSlots()`
  - resolves branch-hours day meta via `BranchOperatingHoursService`
  - returns no slots when day is missing-hours or closed
  - intersects staff working intervals with branch open/close envelope before candidate slot generation
  - retains existing staff conflict/blocked/break checks
- Added reusable metadata accessor:
  - `AvailabilityService::getBranchOperatingHoursMeta(?int $branchId, string $date)`

## Branch-Hours Filtering Rule Applied
- For selected branch + date:
  - not configured (or unavailable): no generated slots
  - closed day: no generated slots
  - open day: candidate slot interval must be fully within branch envelope:
    - slot start >= open_time
    - slot end (start + service duration) <= close_time
- Staff-level availability remains a separate inner constraint.

## Missing-Hours Behavior
- No fatal.
- No fabricated default hours.
- No misleading slots.
- Slot APIs now return empty slots with explicit notice:
  - `Opening hours are not configured for this branch on the selected day.`

## Closed-Day Behavior
- Returns empty slots on closed day.
- Slot APIs expose clear notice:
  - `This branch is closed on the selected day.`

## Response Follow-Through
- Internal and public slot responses now include:
  - `branch_operating_hours`:
    - `branch_hours_available`
    - `is_closed_day`
    - `open_time`
    - `close_time`
  - `availability_notice` (nullable string)
- Existing response style remains JSON success with data payload.

## Separate Internal Availability Path
- A separate internal generator path beyond `GET /appointments/slots` was not found for slot generation.
- Staff calendar/day rendering uses different read shapes and was already integrated in earlier wave.

## Intentionally Out Of Scope
- Slot chooser UI redesign.
- Closure-dates filtering.
- Any weakening/removal of create/update/reschedule/public write guards.
