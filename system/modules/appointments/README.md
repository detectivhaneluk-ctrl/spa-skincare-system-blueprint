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

## Branch Contract (C-002 — Canonical Rule)

Every appointments page and JSON endpoint **requires** a resolved branch. There is no "global" or
"all branches" mode.

Resolution order (applied by `resolveAppointmentBranchFromGetOrFail` /
`resolveAppointmentBranchForPrincipalFromOptionalRequestId`):

1. **Explicit `branch_id` query/post param** — validated against the principal's allowed branches
   and the resolved organization. Takes precedence over session.
2. **Session branch from `BranchContext::getCurrentBranchId()`** — used when no explicit param is
   supplied. Fails closed with `DomainException` when no session branch is active.

If resolution fails (no explicit param and no session branch), the controller redirects to
`/dashboard` with a flash error — not back to `/appointments` (which would loop).

The old "global / no-branch" behavior documented below is **retired**. Appointments always carry a
`branch_id`. Rows with `branch_id IS NULL` are excluded by the org-scope SQL fragment and are
unreachable from any current UI path.

## Route Contract — Dual-Route System (BKM-008)

| Purpose | Route | Handler |
|---|---|---|
| HTML page | `GET /appointments/calendar/day` | `AppointmentController::dayCalendarPage()` |
| JSON data | `GET /calendar/day` | `AppointmentController::dayCalendar()` |

The calendar page fetches its data from `/calendar/day` (not `/appointments/calendar/day`). Both
routes share the same branch resolver. The page URL and the JSON URL are **intentionally different**
paths. `history.replaceState` keeps the browser URL on `/appointments/calendar/day?...` while the
fetch targets `/calendar/day?...`.

## Statuses

- `scheduled`, `confirmed`, `in_progress`, `completed`, `cancelled`, `no_show`

## Conflict Rules

- Staff overlap: rejected if another non-cancelled appointment uses the same staff in the same time
  range within the same organization.
- Room overlap: rejected if another non-cancelled appointment uses the same room in the same time
  range and branch.
- When editing, the current appointment ID is excluded from overlap checks.

## end_at Behavior

- **Create**: If end_time is left blank and a service is selected, end_at is auto-calculated from
  the service's `duration_minutes`.
- **Edit**: Manual override is always allowed. If end_time is blank and a service is selected,
  end_at is auto-calculated.
- **Rule**: `end_at = start_at + service.duration_minutes` when end_time is not provided and service
  has duration.
