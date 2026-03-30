# Appointments Phase Plan (Phase 1.1 Audit)

## Current Architecture

- **Module boundaries**
  - Appointment runtime logic lives in `modules/appointments` (controller/service/repository/views).
  - Cross-module integrations already use contracts/providers for:
    - packages consumption/availability (`PackageAvailabilityProvider`, `AppointmentPackageConsumptionProvider`)
    - sales checkout prefill (`AppointmentCheckoutProvider`)
- **Core flow split**
  - Legacy form flow: `store()` / `update()` with manual start/end/status fields.
  - Booking-core flow: `storeFromCreatePath()` + `AvailabilityService` + transactional `createFromSlot()`.
- **Availability stack**
  - `AvailabilityService` calculates slots from:
    - service duration
    - active staff + service-staff mapping
    - `staff_schedules`
    - optional `staff_breaks` table (if exists)
    - blocking appointment statuses
- **Operational actions**
  - Cancel, reschedule, and explicit status update are implemented in `AppointmentService` with transactions and audit.
- **Permissions**
  - Route-level middleware for `appointments.view/create/edit/delete`.

## Existing Working Features

- **Index/List**
  - Branch/date filters, pagination, status visibility.
  - File path: `modules/appointments/views/index.php`.
- **Create**
  - Slot-based create flow (`POST /appointments/create`) with race-safe availability recheck in transaction.
  - Legacy create endpoint still present (`POST /appointments`).
- **Show**
  - Appointment details + admin actions.
  - Packages integration (explicit consume for completed appointments).
- **Edit**
  - `GET /appointments/{id}/edit` + `POST /appointments/{id}`.
  - Route ordering and id constraints are now safe.
- **Status/cancel/reschedule**
  - Transition matrix is enforced in service.
  - Repeated cancel is idempotent-safe.
- **Calendar day**
  - JSON API: `GET /calendar/day`.
  - Basic page: `GET /appointments/calendar/day`.
  - Groups by staff with day grid.
- **Slots**
  - JSON API: `GET /appointments/slots`.
  - Validates service/date and optional staff scope.

## File Map (Current Appointment Flow)

- **Routes**
  - `system/routes/web.php` (appointments route group)
- **Controller**
  - `system/modules/appointments/controllers/AppointmentController.php`
- **Services**
  - `system/modules/appointments/services/AppointmentService.php`
  - `system/modules/appointments/services/AvailabilityService.php`
- **Repository**
  - `system/modules/appointments/repositories/AppointmentRepository.php`
- **Views**
  - `system/modules/appointments/views/index.php`
  - `system/modules/appointments/views/create.php`
  - `system/modules/appointments/views/edit.php`
  - `system/modules/appointments/views/show.php`
  - `system/modules/appointments/views/calendar-day.php`
- **Providers**
  - `system/modules/appointments/providers/AppointmentCheckoutProviderImpl.php`
  - `system/modules/appointments/providers/ClientAppointmentProfileProviderImpl.php`
- **Module wiring**
  - `system/modules/bootstrap.php`
- **Permissions seed**
  - `system/data/seeders/001_seed_roles_permissions.php`

## Tables/Columns Already Used by Appointments

- **Primary**
  - `appointments`
    - `id, client_id, service_id, staff_id, room_id, branch_id, start_at, end_at, status, notes, created_by, updated_by, deleted_at, created_at, updated_at`
- **Availability dependencies**
  - `staff_schedules` (`staff_id, day_of_week, start_time, end_time`)
  - optional `staff_breaks` (`staff_id, day_of_week, start_time, end_time`) if table exists
  - `services` (`id, duration_minutes, is_active, branch_id, deleted_at`)
  - `service_staff` (`service_id, staff_id`) — optional per-service staff allowlist for booking eligibility
  - `service_staff_groups` (`service_id`, `staff_group_id`) — optional; when linked to **active** groups, booking eligibility requires membership in an applicable linked group (see `ServiceStaffGroupEligibilityService`)
  - `staff` (`id, is_active, branch_id, deleted_at`)
- **Display joins**
  - `clients`, `services`, `staff`, `rooms`
- **Audit**
  - `audit_logs` for all state changes

## Reusable Logic for Expansion

- **Staff/resource availability**
  - `AvailabilityService::getAvailableSlots()`, `isSlotAvailable()`, `listDayAppointmentsGroupedByStaff()`, `getDayGrid()`
- **Conflict logic**
  - `AppointmentRepository::hasStaffConflict()`, `hasRoomConflict()`
- **Branch scoping patterns**
  - List filters use `(branch_id = ? OR branch_id IS NULL)`
  - Action context derives branch from appointment/service where needed
- **Status safety**
  - `AppointmentService` transition matrix and terminal status guards

## Missing Booker-Parity Features

- **Workspace tabs**
  - Current system has separate pages/endpoints; no unified tabbed workspace shell.
- **Multi-resource day calendar**
  - Calendar is staff-grouped only; no room/equipment lanes and no simultaneous resource occupancy visualization.
- **Blocked slots**
  - No dedicated blocked-time model/table or UI/API.
- **Waitlist**
  - No waitlist entities, matching logic, or conversion flow.
- **Stronger availability flow**
  - No dedicated availability tokens/idempotency keys.
  - No database-level overlap constraints (application-level locking/check only).
  - `staff_breaks` check runs `SHOW TABLES` at runtime (functional, but not ideal at scale).

## Gaps / Dead Code / Duplicated Logic / Risky Areas

- **Dual create/update paradigms**
  - Legacy manual flow (`store/update`) and slot-driven flow coexist; rules can drift if not consolidated.
- **Conflict logic duplication**
  - Overlap validation exists in both repository (`has*Conflict`) and availability service (`isSlotAvailable`).
- **Route + controller footprint**
  - Many appointment actions in one controller; likely to grow quickly as Booker-parity expands.
- **No DB-enforced overlap invariant**
  - Current race safety relies on transactions + checks, not hard DB constraints.
- **Calendar render simplification**
  - Current day calendar places an appointment only by matching row start-time; no span rendering.

## Files to Modify (Phase 1.2+)

- **Appointments**
  - `modules/appointments/controllers/AppointmentController.php`
  - `modules/appointments/services/AppointmentService.php`
  - `modules/appointments/services/AvailabilityService.php`
  - `modules/appointments/repositories/AppointmentRepository.php`
  - `modules/appointments/views/index.php`
  - `modules/appointments/views/show.php`
  - `modules/appointments/views/calendar-day.php`
  - `modules/appointments/views/create.php`
- **New appointments internals (recommended)**
  - `modules/appointments/services/WaitlistService.php`
  - `modules/appointments/repositories/BlockedSlotRepository.php`
  - `modules/appointments/repositories/WaitlistRepository.php`
  - `modules/appointments/providers/*` (if exposing new contracts)
- **Routing & wiring**
  - `system/routes/web.php`
  - `system/modules/bootstrap.php`
- **Contracts (if cross-module read needed)**
  - `system/core/contracts/*`

## Tables to Add/Change (Phase 1.2+)

- **Add**
  - `appointment_blocked_slots`
    - `id, branch_id, staff_id nullable, room_id nullable, starts_at, ends_at, reason, created_by, deleted_at, created_at`
  - `appointment_waitlist`
    - `id, client_id, service_id, preferred_staff_id nullable, preferred_date nullable, preferred_time_window nullable, priority, status, notes, branch_id, created_by, created_at, updated_at`
- **Possible add**
  - `appointment_status_history`
    - explicit state transition log (optional if audit logs are considered sufficient)
- **Indexes**
  - composite range indexes for blocked slots and waitlist query paths (branch + resource + time/status)

## Risks / Constraints

- Keep current create/edit/show/status/cancel/reschedule behavior unchanged while adding new capabilities.
- Avoid cross-module repository coupling; new summaries/integrations should remain contracts/providers.
- Respect current branch-null (“global”) semantics in all new tables and queries.
- Maintain transactional guarantees for every new state-changing action.
- Existing permission set has no dedicated waitlist/blocked-slot permissions yet; additions should be explicit.

## Recommended Build Order (Next Subphases)

1. **Phase 1.2 — Internal Refactor Safety Layer**
   - Consolidate overlap checks through one source (`AvailabilityService`) and keep repository helpers as thin wrappers or adapters.
   - Extract appointment action handlers into focused services where needed (without changing behavior).
2. **Phase 1.3 — Blocked Slots Foundation**
   - Add blocked-slot table/repository/service/routes.
   - Merge blocked-slot checks into availability and calendar API.
3. **Phase 1.4 — Waitlist Foundation**
   - Add waitlist table/repository/service.
   - Add simple waitlist list/create/update actions (no advanced UI).
4. **Phase 1.5 — Calendar Parity Step-up**
   - Extend day API to include room/resource lanes.
   - Improve block rendering model (time spans, not start-time only).
5. **Phase 1.6 — Workspace Shell (Tabbed)**
   - Add lightweight tab container page (Calendar / List / New / Waitlist) reusing existing pages/APIs.
6. **Phase 1.7 — Hardening Pass**
   - Permission expansion, audit completeness validation, and concurrency smoke tests.

## Minimal Refactor Notes in This Phase

- No functional refactor was applied in this Phase 1.1 audit step.
- This document is implementation mapping + plan only.
