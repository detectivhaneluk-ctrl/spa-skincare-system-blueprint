# SETTINGS-OPENING-HOURS-RUNTIME-SAFETY-AND-MIGRATION-GATE-05

Date: 2026-03-24  
Status: Completed (runtime safety repair)

## Why the fatal happened

The previous wave introduced runtime reads/writes to `branch_operating_hours` from `SettingsController` via the Opening Hours service/repository.  
When migration `092_create_branch_operating_hours_table.sql` had not yet been applied, these DB calls hit a missing table and raised SQL errors during settings page load.

## Migration flow truth in this repo

- Incremental migrations are applied by `system/scripts/migrate.php` (reads all `system/data/migrations/*.sql`, records in `migrations` table).
- Canonical initialization also exists: `php scripts/migrate.php --canonical`, which applies `system/data/full_project_schema.sql` then stamps migrations.
- Therefore, migration `092_create_branch_operating_hours_table.sql` is part of incremental migration flow.
- Canonical schema must also include new tables for fresh environments using `--canonical`.

## Guard added

A table-availability guard was added using `information_schema.TABLES` lookup for `branch_operating_hours`:

- Repository-level probe:
  - `BranchOperatingHoursRepository::isTableAvailable()`
  - cached per request instance
- Service-level readiness:
  - `BranchOperatingHoursService::isStorageReady()`

Behavior when table is missing:
- No fatal on `/settings` load.
- Opening Hours editor is not rendered.
- Opening Hours save is blocked gracefully with a clear error.
- Overview card shows setup-required state instead of trying to compute a summary.

## Canonical schema / setup artifact updates

Updated:
- `system/data/full_project_schema.sql`

`branch_operating_hours` is now present in canonical schema, so fresh canonical installs include this table.

## User-visible behavior before vs after migration apply

### Before migration apply
- `/settings` remains accessible (no fatal).
- `/settings?section=establishment&screen=opening-hours` shows:
  - "Opening Hours is not available yet because the required database migration has not been applied."
- Save attempts for opening hours are blocked with clear error flash.
- Overview Opening Hours card shows setup-required text.

### After migration apply (`php scripts/migrate.php`)
- Opening Hours editor loads normally.
- Weekly schedule reads/writes operate as implemented in wave 04.
- Overview card shows real weekly summary.
