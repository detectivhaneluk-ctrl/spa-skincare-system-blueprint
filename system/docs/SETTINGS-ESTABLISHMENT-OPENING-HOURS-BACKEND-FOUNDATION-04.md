# SETTINGS-ESTABLISHMENT-OPENING-HOURS-BACKEND-FOUNDATION-04

Date: 2026-03-24  
Status: Completed (opening-hours backend foundation only)

## Why staff schedules were not reused

`staff_schedules` and `staff_breaks` are staff-level availability structures. They are not establishment/branch operating-hours truth and were intentionally not repurposed.

This wave introduces a dedicated branch-level recurring weekly schedule backend for Settings Opening Hours.

## Why `/branches` was not used

`/branches` remains branch catalog administration (name/code/state) and is not used as opening-hours editor.

Opening Hours is now edited directly at:
- `/settings?section=establishment&screen=opening-hours`

## New schema

Migration added:
- `system/data/migrations/092_create_branch_operating_hours_table.sql`

Table:
- `branch_operating_hours`
  - `id`
  - `branch_id` (FK to `branches.id`)
  - `day_of_week` (`0..6`)
  - `start_time` (nullable)
  - `end_time` (nullable)
  - `created_at`
  - `updated_at`

Rules embedded by contract:
- Unique row per `(branch_id, day_of_week)`.
- One recurring interval per day in this wave.
- `NULL/NULL` means closed day.

## Branch context resolution used

For opening-hours load/save in `SettingsController`, branch context resolves in this order:
1. `BranchContext::getCurrentBranchId()` when active and allowed.
2. Authenticated user `branch_id` fallback when active and allowed.
3. If no valid active branch is resolvable, screen is non-destructive and save is blocked.

No fake global opening-hours scope was introduced.

## Validation rules

Implemented in `BranchOperatingHoursService`:
- Both empty -> closed day.
- One empty / one filled -> validation error.
- Both filled -> closing time must be strictly after opening time.
- Accepted time formats: `HH:MM` or `HH:MM:SS` (normalized to `HH:MM:SS` for storage).
- Save is transactional for all 7 days.

On validation error:
- User remains in opening-hours flow (redirects back to same screen).
- Error is flashed.
- Submitted values are preserved and re-rendered.

## What this wave does not cover

This wave intentionally does not:
- integrate opening hours into appointment slot calculation
- integrate opening hours into public booking logic
- merge opening hours with staff schedules
- support multiple intervals per day
- implement closure dates / holiday exceptions
- add a branch selector UI in settings
