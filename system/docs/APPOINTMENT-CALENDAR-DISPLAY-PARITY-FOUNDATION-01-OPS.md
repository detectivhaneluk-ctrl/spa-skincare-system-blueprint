# Appointment calendar display — series vs standalone (foundation 01)

## Task

`APPOINTMENT-CALENDAR-DISPLAY-PARITY-FOUNDATION-01`

## Truth (domain)

- There is **no** class/course appointment type in-repo for calendar labeling.
- There **is** a persisted, runtime field: **`appointments.series_id`** (nullable FK to `appointment_series`). Occurrences linked to a recurring series have **`series_id > 0`**; standalone service appointments have **`series_id` null** (or zero treated as absent).

## What was added

| Setting key | Role |
| --- | --- |
| `appointments.calendar_series_show_start_time` | Day calendar: show start time for **series-linked** rows (`series_id` present and > 0). Default **true** (matches service pair). |
| `appointments.calendar_series_label_mode` | Same enum as `appointments.calendar_service_label_mode`. Default **`client_and_service`** (matches service pair). |

**Standalone** appointments (no effective `series_id`) use **`calendar_service_*`** only. **Series-linked** rows use **`calendar_series_*`** in the day-calendar consumer (`calendar-day.php` view script, driven by `GET /appointments/calendar/day` JSON `appointment_calendar_display`).

## Parity claim boundary

**In scope:** Operator **day calendar** label line / meta line / start-time vs end-only time strip behavior can differ for **series-linked** vs **standalone** service appointments, via Settings → Appointments.

**Out of scope:** Class/course engines, booking semantics, staff pricing/duration, packages, check-in, other screens (list, print, client profile) unless explicitly wired later.

Screenshot parity vs an external spec is **not** claimed beyond: “two tunable pairs exist and the consumer branches on real `series_id`.”

## Consumers (read-side)

- **`SettingsService::getAppointmentSettings`** — returns `calendar_series_show_start_time`, `calendar_series_label_mode`.
- **`SettingsController`** — POST allowlist + `appointmentPatch` for both keys.
- **`Settings` → `modules/settings/views/index.php`** — “Series-linked appointments” subsection.
- **`AppointmentController::dayCalendar`** — JSON `appointment_calendar_display.series_show_start_time`, `series_label_mode`.
- **`AvailabilityService::listDayAppointmentsGroupedByStaff`** — each row may include **`series_id`** (int or null).
- **`modules/appointments/views/calendar-day.php`** — `buildCalendarViewModel` chooses service vs series display fields per row.

## Verification

From `system/` (requires seeded branch, e.g. `SMOKE_A`):

```bash
php scripts/verify_appointment_calendar_display_series_parity_foundation_01.php --branch-code=SMOKE_A
```

**Proven:** branch patch/read divergence between service and series keys; org-default stability under branch-only patch; restore.

**May SKIP (stdout):** no non-deleted appointment with `series_id` on the branch — `listDayAppointmentsGroupedByStaff` `series_id` shape is not exercised against a real row (settings proof still runs).
