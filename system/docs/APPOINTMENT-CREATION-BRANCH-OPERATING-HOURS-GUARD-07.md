# APPOINTMENT-CREATION-BRANCH-OPERATING-HOURS-GUARD-07

## Scope
- Added backend enforcement for branch operating hours on internal appointment write paths.
- Covered create and scheduling updates/reschedules through canonical service methods.
- Did not change public booking UX, drag/drop UI behavior, slot generation, staff schedule logic, or blocked-slot semantics.

## Exact Write Paths Patched
- `Modules\Appointments\Services\AppointmentService::create()`
- `Modules\Appointments\Services\AppointmentService::insertNewSlotAppointmentWithLocks()`
  - used by `createFromSlot()` and internal/public/series slot-based create pipelines
- `Modules\Appointments\Services\AppointmentService::buildServiceBasedMovePatchAfterAppointmentLock()`
  - used by `update()` when scheduling fields mutate
  - used by `reschedule()`

## Guard Rule
- Source of truth: `branch_operating_hours` via `BranchOperatingHoursService::getDayHoursMeta()`.
- For appointment `start_at`/`end_at` and resolved `branch_id`:
  1. if hours unavailable or day row not configured => reject with:
     - `Opening hours are not configured for this branch on the selected day.`
  2. if configured day is closed => reject with:
     - `This branch is closed on the selected day.`
  3. if configured open day => require full containment:
     - `start_time >= open_time`
     - `end_time <= close_time`
     - otherwise reject with:
       - `The selected time falls outside this branch's operating hours (HH:MM-HH:MM).`

## Create / Update Coverage
- **Create (`create`)**: guarded after existing core validations and before conflict checks/insert.
- **Slot create (`createFromSlot` path)**: guarded in shared insert pipeline after branch resolution.
- **Update (`update`)**:
  - scheduling-mutated updates are guarded in canonical move builder.
  - non-scheduling updates are unchanged.
- **Reschedule (`reschedule`)**: guarded through the same canonical move builder.

## User-Facing Validation Behavior
- Guard failures raise `DomainException` with English messages.
- Existing controller error handling already surfaces these messages in internal create/edit flows.
- No UI redesign was added.

## Safe Legacy Handling
- Existing stored out-of-hours appointments are not modified.
- Enforcement only blocks new invalid writes or invalid schedule changes.

## Intentionally Not Covered Yet
- Drag/drop-specific guard wiring if that path bypasses canonical service methods.
- Public booking-specific policy messaging/UX refinements.
- Slot-generation-level enforcement.
