# CLOSURE-DATES-BACKEND-FOUNDATION-11

## Why blocked slots were not reused

`appointment_blocked_slots` was intentionally not reused because it models staff-scoped time windows (`staff_id`, `start_time`, `end_time`) and supports partial-day blocking. Closure dates in this wave are a different domain truth: branch-level, date-based, full-day operational closures.

## New schema

Added migration: `system/data/migrations/093_create_branch_closure_dates_table.sql`

Table: `branch_closure_dates`

- `id` BIGINT UNSIGNED PK
- `branch_id` BIGINT UNSIGNED NOT NULL (FK to `branches.id`, ON DELETE CASCADE)
- `closure_date` DATE NOT NULL
- `title` VARCHAR(150) NOT NULL
- `notes` TEXT NULL
- `created_by` BIGINT UNSIGNED NULL (FK to `users.id`, ON DELETE SET NULL)
- `created_at`, `updated_at`, `deleted_at`
- `live_closure_date` generated column to enforce unique live closure dates
- Unique constraint: `uk_branch_closure_dates_live (branch_id, live_closure_date)`

This enforces one live closure row per branch and date while still allowing soft deletes.

## Branch context resolution used

For settings closure dates and overview preview, branch resolution follows:

1. Current `BranchContext` active branch id, if active and allowed.
2. Authenticated user `branch_id` fallback, only when it maps to an active branch.
3. If unresolved: non-destructive empty-state UI and no save operations.

## CRUD behavior added

Dedicated repository/service:

- `Modules\Settings\Repositories\BranchClosureDateRepository`
- `Modules\Settings\Services\BranchClosureDateService`

Behavior:

- list closure dates for active branch
- create closure date
- update closure date
- soft delete closure date
- storage readiness guard when migration is missing

Controller integration:

- `SettingsController` now has a dedicated controlled branch for `section=establishment` + `screen=closure-dates`
- Uses `closure_dates_action` (`create`, `update`, `delete`)
- Keeps generic settings whitelist flow intact and separate

## Validation rules

Service validation:

- `closure_date` required
- `closure_date` must match `YYYY-MM-DD`
- `closure_date` must be a valid calendar date
- `title` required
- `title` max length 150
- duplicate live date per branch/date rejected

On invalid create/update:

- redirect back to closure-dates screen
- flash error shown
- submitted values preserved via flash payload for create/update forms

## Scope intentionally not covered in this wave

This wave does **not**:

- enforce closure dates in calendar availability
- block appointment create/reschedule/public booking
- filter slots
- add partial-day closure support
- merge closure dates with blocked slots
