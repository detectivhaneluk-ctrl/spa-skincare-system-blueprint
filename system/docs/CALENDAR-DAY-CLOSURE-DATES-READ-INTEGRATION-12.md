# CALENDAR-DAY-CLOSURE-DATES-READ-INTEGRATION-12

## Exact calendar read path patched

- `system/modules/appointments/controllers/AppointmentController.php`
  - `dayCalendar()` read payload now includes closure-date truth.
  - Effective day-closure precedence for calendar read metadata is applied in this controller.
- `system/modules/appointments/views/calendar-day.php`
  - Day calendar indicator now renders closure-date state and anomaly visibility notes.

## Closure-date lookup rule

For selected `date` + active `branch_id`:

1. Check `BranchClosureDateService::isStorageReady()`.
2. If storage is ready and branch context exists, read branch rows via `listForBranch($branchId)`.
3. Match where live `closure_date === selected date`.
4. Expose read metadata:
   - `closure_date.storage_ready`
   - `closure_date.active`
   - `closure_date.title`
   - `closure_date.notes`
   - `closure_date.records_visible_count`

## Closed-day read behavior

When `closure_date.active` is true:

- Calendar read metadata treats the day as operationally closed for display purposes.
- In controller read path, closure date takes precedence over branch-hours open state:
  - `branch_operating_hours.is_closed_day = true`
  - `open_time/close_time = null`
- Calendar indicator displays closed-day message with closure title/notes when available.

## Anomaly visibility behavior

No real data is hidden.

- Existing appointments and blocked slots remain in their normal lanes.
- On closure days, payload includes `closure_date.records_visible_count`.
- UI indicator shows a restrained anomaly note/count when records exist on a closed day.

## Missing-storage behavior

If `branch_closure_dates` storage is unavailable:

- No fatal error.
- No fake open/closed closure-date state is emitted.
- Payload reports `closure_date.storage_ready = false`.
- Calendar indicator explicitly states closure-date storage is unavailable and keeps operating-hours behavior.

## Intentionally out of scope

This wave does not implement:

- write-side blocking for appointment create/reschedule
- slot generation filtering/enforcement
- public booking changes
- blocked-slot merge/replacement
