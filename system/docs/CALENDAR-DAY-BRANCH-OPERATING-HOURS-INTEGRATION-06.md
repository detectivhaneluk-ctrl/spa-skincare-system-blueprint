# CALENDAR-DAY-BRANCH-OPERATING-HOURS-INTEGRATION-06

## Scope
- Integrated branch operating hours into the internal day calendar read surface only.
- No write-path enforcement was added for appointment creation, drag/drop, or slot engine.
- Public booking behavior was not changed.

## Exact Calendar Read Path Patched
- Route: `GET /calendar/day` in `routes/web/register_appointments_calendar.php`
- Controller JSON builder: `modules/appointments/controllers/AppointmentController.php::dayCalendar()`
- Internal day calendar page consumer: `modules/appointments/views/calendar-day.php` (JS fetch/render layer)
- DI wiring for controller dependency: `modules/bootstrap/register_appointments_online_contracts.php`

## Branch Operating Hours Resolution
- Added day-level lookup in `modules/settings/repositories/BranchOperatingHoursRepository.php`:
  - `findByBranchAndDay(int $branchId, int $dayOfWeek): ?array`
- Added canonical day metadata resolver in `modules/settings/services/BranchOperatingHoursService.php`:
  - `getDayHoursMeta(?int $branchId, string $date): array`
- Resolution logic:
  - `weekday` is derived with PHP `date('w', strtotime($date))` (0=Sunday..6=Saturday).
  - `branch_hours_available=false` when storage is unavailable or branch context is missing.
  - `is_configured_day=false` when table exists but no row exists for selected branch/day.
  - `is_closed_day=true` when row exists and both times are null/blank.
  - `open_time` and `close_time` are exposed only for configured open days.

## Metadata Added to Day Calendar Payload
- `branch_operating_hours` is now included in `GET /calendar/day` response:
  - `branch_hours_available`
  - `is_closed_day`
  - `open_time`
  - `close_time`
  - `out_of_hours_appointments` (truth signal for legacy/anomaly rows)

## Closed-Day Behavior
- Calendar now shows a clear closed-day indicator in the day calendar header row.
- The UI no longer presents closed days as normal open operations.
- Existing appointments are still rendered truthfully.
- If appointments exist on a closed day, the indicator shows an anomaly note/count.

## Open-Day Envelope Behavior
- Calendar renders a branch-hours indicator (e.g., `Branch hours: 09:00-19:00`).
- Time bands before opening and after closing are visually muted per staff lane.
- Existing out-of-hours appointments remain visible and are counted as anomalies.
- Day grid bounds are normalized to preserve truthful visibility of existing appointments/blocked slots.

## Missing-Hours Behavior
- If branch/day hours are not configured, no fatal occurs.
- Calendar shows: `Opening hours not configured for this branch/day.`
- No fake default business hours are introduced.
- Existing appointments and blocked slots remain visible.

## Intentionally Not Enforced Yet
- Appointment creation is not blocked outside branch hours in this wave.
- Drag/drop/update enforcement is not added in this wave.
- Public booking and slot generation enforcement are unchanged.
- Staff schedules and blocked-slot semantics remain independent from branch operating hours.
