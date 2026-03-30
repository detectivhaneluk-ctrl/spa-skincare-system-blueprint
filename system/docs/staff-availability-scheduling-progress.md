# Staff Availability / Scheduling Engine — Progress

Backend-first staff availability foundation for operational scheduling and future public booking. Reuses existing appointment conflict rules and branch context; adds working hours, recurring breaks, and a reusable availability shape.

---

## Changed / added files

| File | Role |
|------|------|
| `system/data/migrations/044_create_staff_breaks_table.sql` | New table `staff_breaks` (staff_id, day_of_week, start_time, end_time, title) |
| `system/data/full_project_schema.sql` | Added `staff_breaks` table definition |
| `system/modules/staff/repositories/StaffScheduleRepository.php` | CRUD for `staff_schedules` (working hours by day_of_week) |
| `system/modules/staff/repositories/StaffBreakRepository.php` | CRUD for `staff_breaks` (recurring weekly breaks) |
| `system/modules/appointments/services/AvailabilityService.php` | Added `getStaffAvailabilityForDate`, `getStaffAvailabilityForDateRange`, `getStaffAppointmentSlotsForDate` |
| `system/modules/appointments/controllers/AppointmentController.php` | Added `staffAvailabilityAction` for GET availability JSON |
| `system/routes/web.php` | Route `GET /appointments/availability/staff/{id}` |
| `system/modules/bootstrap.php` | Bindings for `StaffScheduleRepository`, `StaffBreakRepository` |
| `system/docs/staff-availability-scheduling-progress.md` | This doc |

---

## Data model added / used

- **staff_schedules** (existing): `staff_id`, `day_of_week` (0=Sun..6=Sat), `start_time`, `end_time`. Branch implied by staff.
- **staff_breaks** (new): `staff_id`, `day_of_week`, `start_time`, `end_time`, `title` (optional). Recurring weekly breaks (e.g. lunch). Branch implied by staff.
- **appointment_blocked_slots** (existing): date-specific blocked time per staff/branch; already used by availability.

---

## Availability rules

1. **Working hours:** From `staff_schedules` for the requested date’s day of week. No rows → off-day (empty `working_intervals`).
2. **Breaks:** From `staff_breaks` for that day of week; any overlap with a requested window makes the window unavailable (existing `getBreakIntervals` in AvailabilityService).
3. **Blocked time:** From `appointment_blocked_slots` for that staff and date; branch filter applied when branch_id is set. Overlap with window → unavailable.
4. **Appointments:** Blocking statuses `scheduled`, `confirmed`, `in_progress`, `completed`; buffer before/after from service when checking a specific slot. For the availability shape we return raw `appointment_slots` for the day.
5. **Branch:** Staff must be active; when `branch_id` is provided, staff’s `branch_id` must match (or staff is global). Otherwise availability returns null / excluded from range.
6. **Conflict check (unchanged):** `isStaffWindowAvailable` / `isSlotAvailable` still enforce: within working interval, no break overlap, no blocked overlap, no buffered appointment overlap. Same as used by AppointmentService `checkConflicts`.

---

## Endpoints / services added

- **GET /appointments/availability/staff/{id}**  
  - Query: `date=Y-m-d` (single day) or `date_from=Y-m-d&date_to=Y-m-d` (range). Optional `branch_id`.  
  - Single day: 200 + `{ success, data: { date, staff_id, branch_id, working_intervals, break_intervals, blocked_intervals, appointment_slots } }`; 404 if staff not found/inactive or branch mismatch.  
  - Range: 200 + `{ success, data: [ ...per-day same shape... ] }`.  
  - Auth + `appointments.view`.

- **AvailabilityService**  
  - `getStaffAvailabilityForDate(int $staffId, string $date, ?int $branchId): ?array` — Single-day availability shape; null if staff inactive or branch mismatch.  
  - `getStaffAvailabilityForDateRange(int $staffId, string $dateFrom, string $dateTo, ?int $branchId): array` — List of single-day shapes for each date in range.

- **StaffScheduleRepository** (staff module): `listByStaff`, `find`, `create`, `update`, `delete` for `staff_schedules`.  
- **StaffBreakRepository** (staff module): `listByStaff`, `find`, `create`, `update`, `delete` for `staff_breaks`.

---

## Manual smoke test checklist

1. **Migration**  
   - Run migration 044 (create `staff_breaks`). Confirm table exists.

2. **Working hours**  
   - Ensure at least one staff has rows in `staff_schedules` for a day (e.g. Monday).  
   - Call `GET /appointments/availability/staff/{id}?date=YYYY-MM-DD` (that weekday). Response `data.working_intervals` non-empty.

3. **Off-day**  
   - Call availability for a date whose day-of-week has no `staff_schedules` for that staff. `working_intervals` should be empty.

4. **Breaks**  
   - Insert a row in `staff_breaks` for that staff/day. Call availability again; `data.break_intervals` should list the break. Existing slot check (create appointment) should reject a slot overlapping the break.

5. **Blocked time**  
   - Add an `appointment_blocked_slots` for that staff/date/branch. Call availability; `data.blocked_intervals` should include it. Creating an appointment in that window should still conflict.

6. **Appointment slots**  
   - Create an appointment for that staff/date (scheduled/confirmed). Call availability; `data.appointment_slots` should contain that appointment’s start_at/end_at/status.

7. **Branch**  
   - Call with `branch_id` that does not match staff’s branch (when staff has branch_id). Expect 404 or empty range result. Call with matching branch_id or no branch_id (global staff) → 200 and data.

8. **Date range**  
   - `GET /appointments/availability/staff/{id}?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`. Response `data` is an array of daily shapes; one entry per date in range (only dates where staff has data returned).

9. **Conflict logic unchanged**  
   - Create/reschedule appointment: overlapping another appointment, or inside blocked slot, or outside working hours, or over break → conflict message. No change from previous behavior.

---

## Final hardening pass

A later **final backend hardening pass** added controller-level branch guards and document permissions elsewhere; staff availability and scheduling were unchanged. See `system/docs/archive/system-root-summaries/HARDENING-SUMMARY.md` §5 and `system/docs/archive/system-root-summaries/BACKEND-STATUS-SUMMARY.md`.

---

## Postponed

- **UI:** No new UI for managing working hours or breaks; repositories are ready for future staff schedule/break management screens.  
- **Payroll / rota planning:** Not in scope.  
- **Public booking API:** Availability shape and endpoint are ready for consumption; no public-facing routes or anonymized API added.  
- **Staff schedule/break CRUD endpoints:** No controller or routes for creating/editing `staff_schedules` or `staff_breaks`; data can be seeded or managed via DB until a later phase.
