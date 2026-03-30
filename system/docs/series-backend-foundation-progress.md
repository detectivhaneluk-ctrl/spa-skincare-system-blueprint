# Series backend foundation progress

**Task:** `SERIES-BACKEND-FOUNDATION-01`  
**Status:** Backend foundation implemented (create-only, internal/admin path), with semantics hardening for truthful conflict handling.

## Scope delivered

- Added minimal schema foundation (migration **057**; reflected in `system/data/full_project_schema.sql`):
  - New `appointment_series` table for weekly/biweekly recurrence definitions.
  - Nullable `appointments.series_id` foreign key linkage.
- Added backend foundation classes:
  - `AppointmentSeriesRepository` for series persistence.
  - `AppointmentSeriesService` for series definition creation + recurrence generation + occurrence creation loop.
- Added one internal/admin entry path for testability:
  - `POST /appointments/series` (auth + `appointments.create`).

## Reuse guarantees (no conflict logic rewrite)

- Occurrence inserts call `AppointmentService::createFromSeriesOccurrence`, which delegates into existing locked creation pipeline.
- Existing authoritative checks remain in force for each generated occurrence:
  - branch-scoped policy checks (`validateTimes` through appointment settings),
  - staff/service active row locks (`FOR UPDATE`),
  - consent checks (`ConsentService`),
  - slot conflict/availability checks (`AvailabilityService`),
  - audit logging via existing appointment create audit flow.

## V1 limits intentionally kept

- Internal/admin backend only.
- Create-only (no edit-series flow, no split "this vs following" behavior).
- No public booking integration.
- No payment, membership, notification, or recurring billing automation.
- No UI work and no timezone model rewrite.

## Result contract

`POST /appointments/series` success payload includes:

- `series_id`
- `requested_count`
- `created_count`
- `skipped_conflict_count`
- `first_conflict_date` (nullable)

Generation currently stops on first hard conflict for safe v1 behavior.

## Semantics hardening (SERIES-BACKEND-FOUNDATION-SEMANTICS-HARDENING-01)

- Series creation now performs narrow pre-validation for branch/client/service/staff state before persisting.
- Series create and occurrence generation run as one transaction boundary to avoid orphan active series rows when first occurrence fails.
- Conflict handling is narrow: only real slot/conflict-style domain failures are treated as stop/skip conflict outcomes.
- Non-conflict domain failures (e.g. consent/state/invalid entity conditions) bubble as domain errors and are not converted into conflict counters.
- `POST /appointments/series` returns `201` only when at least one occurrence was actually created; zero-created outcomes are `422`.
