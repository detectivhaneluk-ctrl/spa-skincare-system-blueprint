# Appointments Module Notes (Phase 6A Foundation)

## Packages Integration Boundary

Appointments does not directly read/write package repositories.
Integration is handled via core contracts:

- `Core\Contracts\PackageAvailabilityProvider`
- `Core\Contracts\AppointmentPackageConsumptionProvider`

Implementations live in Packages module providers.

## First-Pass Workflow

- Appointment detail page shows eligible client packages through provider.
- Package consumption is an explicit action only.
- Consumption is allowed only for `completed` appointments.
- Consumption writes package usage with:
  - `usage_type = 'use'`
  - `reference_type = 'appointment'`
  - `reference_id = appointment_id`

## Safety Rules

- No implicit/automatic package consumption.
- Duplicate consumption for the same appointment + client_package is blocked.
- Branch context remains explicit from appointment branch.
- Package ownership must match appointment client.
# Appointments Module

## Temporary Branch Behavior

Until `BranchContextMiddleware` is fully implemented:

- **branch_id** comes from the form or query string. When null, appointments are "global" (no branch).
- **List**: Optional `?branch_id=1` filter. When omitted, shows all appointments.
- **Create/Edit**: User selects branch in the form. Conflict checks run within the same branch only.
- **Conflict checks**: Staff and room overlaps are detected only against other non-cancelled appointments in the **same branch** (or both global when branch_id is null).

## Statuses

- `scheduled`, `confirmed`, `in_progress`, `completed`, `cancelled`, `no_show`

## Conflict Rules

- Staff overlap: rejected if another non-cancelled appointment uses the same staff in the same time range and branch.
- Room overlap: rejected if another non-cancelled appointment uses the same room in the same time range and branch.
- When editing, the current appointment ID is excluded from overlap checks.

## end_at Behavior

- **Create**: If end_time is left blank and a service is selected, end_at is auto-calculated from the service's duration_minutes.
- **Edit**: Manual override is always allowed. If end_time is blank and a service is selected, end_at is auto-calculated.
- **Rule**: end_at = start_at + service.duration_minutes when end_time is not provided and service has duration.
