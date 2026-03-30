# Booker Modernization Master Plan

> **DEFERRED UNTIL BACKBONE CLOSURE** — Not an active execution source until `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md` phases allow product/parity work. Strict status: `system/docs/TASK-STATE-MATRIX.md`.

## 1. Purpose

This plan exists to move the current **single-salon** system toward **Booker-level single-salon backend capability** (scheduling integrity, CRM data completeness, safe moves, concurrency) **without** drifting into UI work, visual calendar product design, payment/marketing scope, or broad refactors. Execution must remain **backend-first** and **evidence-driven** from this repository.

## 2. Locked Scope

Only these topics are in scope:

- Drag-and-drop calendar **backend contracts** (payloads, validation, idempotency, error semantics—not visual DnD).
- Calendar move / reschedule **safety** (atomicity, conflict rules, parity across entry paths).
- Staff shift and availability **backend model** (weekly patterns, breaks, blocks, gaps vs Booker-style exceptions).
- Client CRM **notes/history backend completeness** (structured notes, booking history surfaces, audit usefulness).
- Booking **concurrency** / double-booking **prevention** across admin, slot-create, waitlist, and public booking paths.
- **Timezone** correctness relative to stored datetimes and slot generation.
- Removal of **multi-business** or unnecessary structural bloat **only where clearly proven** in-repo and safe under single-salon assumptions.
- **Modular, minimalist** backend improvements **only where necessary** to close gaps above—no architecture rewrite.

## 3. Explicit Out of Scope

- UI redesign or new screen flows (except where a **backend contract** must be named; no UI specs).
- Visual calendar interactions, drag handles, animations, layout.
- CSS changes.
- JS enhancements **unless** a locked backend contract explicitly requires a minimal client contract change (default: **no**).
- Payment redesign, register, invoicing beyond what already blocks booking.
- Marketing pages, SEO, branding.
- Broad re-architecture, framework migration, ORM introduction.
- Speculative features **not** grounded in current repo evidence.
- Multi-tenant / multi-business SaaS expansion thinking.

## 4. Current Architecture Snapshot

**Entrypoints**

- `index.php` (project root) → loads `system/bootstrap.php`, `system/modules/bootstrap.php`, runs `Core\App\Application`.
- `system/public/index.php` → same pattern when docroot is `system/public`.

**Bootstrap / container**

- `system/bootstrap.php`: loads helpers, `Env`, constructs `Core\App\Container`, registers **core** singletons (`Config`, `Database`, `SessionAuth`, `AuthService`, `PermissionService`, `BranchContext`, `AuditService`, `SettingsService`, `HttpErrorHandler`).
- `system/modules/bootstrap.php`: registers **module** repositories, services, controllers, and `Core\Contracts\*` provider implementations (large DI graph).

**Routing**

- `Core\App\Application::registerRoutes()` requires `system/routes/web.php`.
- Additional module route files: `system/modules/gift-cards/routes/web.php`, `packages/routes/web.php`, `memberships/routes/web.php` (included from main `web.php`).
- `Core\Router\Dispatcher` global middleware: `CsrfMiddleware`, `ErrorHandlerMiddleware`, `BranchContextMiddleware` (`system/core/router/Dispatcher.php`).

**Modules (domain folders under `system/modules/`)**

- **Auth**, **Clients**, **Staff**, **ServicesResources**, **Appointments**, **OnlineBooking**, **Documents** (consents), **Notifications**, **Sales**, **Inventory**, **Reports**, **Settings**, **Dashboard**, plus **GiftCards**, **Packages**, **Memberships** (separate route files).

**Appointments flow (staff / authenticated)**

- Routes in `system/routes/web.php`: list/create/edit, `POST /appointments` → `AppointmentController::store` → `AppointmentService::create`; `POST /appointments/create` → `storeFromCreatePath` → `AppointmentService::createFromSlot`; cancel, reschedule (`POST /appointments/{id}/reschedule` → `AppointmentService::reschedule`), status, delete, blocked slots, waitlist actions.
- Day calendar JSON: `GET /calendar/day` → `AppointmentController::dayCalendar` (aggregates `AvailabilityService`: staff, appointments by staff, blocked slots, time grid).
- Page shell: `GET /appointments/calendar/day` → `dayCalendarPage`.

**Online booking flow (unauthenticated API)**

- Public booking (`system/routes/web.php`): `GET /api/public/booking/slots`, `POST /api/public/booking/book` → `PublicBookingController` → `PublicBookingService`. `GET /api/public/booking/consent-check` → `PublicBookingController::consentCheck` only (read bucket + `requireBranchPublicBookability` + **410** fixed JSON — **no** `PublicBookingService` consent method, **no** `ConsentService` client lookup on that GET; PB-HARDEN-08).

**Clients / CRM flow**

- `ClientController` + `ClientService` + `ClientRepository`; client show loads `ClientAppointmentProfileProvider`, sales/packages/gift card providers, `ClientRepository::listNotes`, `listAuditHistory` (`system/modules/clients/controllers/ClientController.php`).
- `ClientAppointmentProfileProviderImpl` queries `appointments` for summary + recent list (`system/modules/appointments/providers/ClientAppointmentProfileProviderImpl.php`).

**Staff scheduling flow**

- `StaffController` posts for schedules and breaks (`/staff/{id}/schedules`, `/staff/{id}/breaks`) → `StaffScheduleService` / `StaffBreakService` + repositories.
- `AvailabilityService` consumes `StaffScheduleRepository`, `StaffBreakRepository`, `BlockedSlotRepository`, and appointment overlap SQL.

**Reports / settings (booking-adjacent only)**

- `SettingsService` keys include `establishment.timezone` and appointment/online-booking related settings (`system/core/app/SettingsService.php`; settings UI under `modules/settings/`).
- Reports: `ReportController` includes `appointments-volume`, `staff-appointment-count`, `new-clients` (`system/routes/web.php`) feeding `ReportService` / `ReportRepository`.

## 5. Booker Core Features vs Current Repo

| Capability | Status | Repo evidence |
|------------|--------|----------------|
| Operational appointment calendar backend | **EXISTS** | `GET /calendar/day` → `AppointmentController::dayCalendar`; `AvailabilityService::getDayGrid`, `listDayAppointmentsGroupedByStaff`, `listDayBlockedSlotsGroupedByStaff`. |
| Safe appointment move/reschedule contract | **PARTIAL** | `AppointmentService::reschedule` uses transaction + `SELECT ... FOR UPDATE` on appointment/staff/service + `AvailabilityService::isSlotAvailable`. **No** dedicated versioned JSON “move” contract; `update` path can change `start_at`/`end_at` via `AppointmentService::update` with `checkConflicts` but **without** the same row-lock pattern as `reschedule` / `createFromSlot`. |
| Staff weekly schedule model | **EXISTS** | Table `staff_schedules` (`system/data/full_project_schema.sql`); `StaffScheduleService`, `StaffScheduleRepository`; routes `POST /staff/{id}/schedules`. |
| Staff exception / time-off / override model | **PARTIAL** | Recurring `staff_breaks` (day-of-week). Ad-hoc blocks via `appointment_blocked_slots` + `BlockedSlotService`. **No** separate date-specific “schedule override” entity (e.g. holiday hours) besides blocked slots / breaks pattern. |
| Client booking history | **EXISTS** | `ClientAppointmentProfileProviderImpl::listRecent` + `getSummary` on `appointments`. |
| Client structured notes | **PARTIAL** | Table `client_notes` (`client_id`, `content`, `created_by`, `created_at`, `deleted_at`). `ClientRepository::listNotes` used in `ClientController::show`. **No** `ClientRepository` create/update/delete for `client_notes` and **no** service/route for adding structured notes (only free-text `clients.notes` via client CRUD). |
| Client timeline / audit usefulness | **PARTIAL** | `audit_logs` via `AuditService::log`; `ClientRepository::listAuditHistory` filters `target_type = 'client'`. Appointment actions audit `target_type = 'appointment'` (separate from client-scoped timeline). |
| Waitlist relevance | **EXISTS** | Table `appointment_waitlist`; `WaitlistService`, `WaitlistRepository`; routes under `/appointments/waitlist*`. Conversion uses `AppointmentService::createFromSlot` (see `WaitlistService`). |

## 6. Critical Risks and Gaps

Prioritized, **repo-proven** only:

1. **Concurrency / double-booking (create & public book)**  
   - **Why risky:** `AvailabilityService::hasBufferedAppointmentConflict` is a non-locking read; two concurrent requests can both see “free” and insert.  
   - **Files:** `AppointmentService::create`, `AppointmentService::update` (`system/modules/appointments/services/AppointmentService.php`); `PublicBookingService::createBooking` (`system/modules/online-booking/services/PublicBookingService.php`) — **no** transaction wrapping slot check + insert; **no** `FOR UPDATE` on staff/service like `createFromSlot`.  
   - **Missing contract:** DB **unique exclusion constraint** or **mandatory** serializable lock scope (documented single pattern) for all write paths.

2. **Duplicate / uneven create paths (unsafe parity)**  
   - **Why risky:** `createFromSlot` locks staff + service rows before slot check; `create()` and public `createBooking` do not share that pattern.  
   - **Files:** `AppointmentService.php` (`create` vs `createFromSlot`); `AppointmentController::store` vs `storeFromCreatePath`; `PublicBookingService::createBooking`.  
   - **Missing contract:** One **normative** “book slot” pipeline used by admin slot UI, waitlist, and public API.

3. **Public booking path weakness**  
   - **Why risky:** After `isSlotAvailable`, immediate `AppointmentRepository::create` without transaction; race with staff calendar and other clients.  
   - **Files:** `PublicBookingService.php` (method `createBooking`).  
   - **Missing contract:** Transaction boundary + lock order + failure codes documented and implemented consistently.

4. **Timezone handling**  
   - **Why risky:** `AvailabilityService` / `PublicBookingService` / `AppointmentService::normalizeDateTime` use PHP `strtotime` / `date` in default TZ; `SettingsService` exposes `establishment.timezone` and `config/app.php` has `timezone` — **no** grep evidence that establishment timezone is applied to slot iteration or appointment validation in `AvailabilityService`.  
   - **Files:** `system/modules/appointments/services/AvailabilityService.php`; `system/modules/online-booking/services/PublicBookingService.php`; `system/core/app/SettingsService.php`; `system/config/app.php`.  
   - **Missing contract:** Explicit rule: “all stored datetimes are X; slot math uses Y TZ.”

5. **Move/reschedule vs generic update**  
   - **Why risky:** `reschedule` is stricter (locks + availability); `update` can change times relying on `checkConflicts` only inside a generic transaction—**asymmetric** safety.  
   - **Files:** `AppointmentService::reschedule`, `AppointmentService::update`.  
   - **Missing contract:** Either forbid time changes via `update` or apply **same** validation/locking as `reschedule` (decision recorded before implementation).

6. **CRM structured notes write path**  
   - **Why risky:** `client_notes` table exists but no create path in `ClientRepository` / `ClientService` / routes → data model unused for operational note-taking.  
   - **Files:** `system/data/full_project_schema.sql` (`client_notes`); `ClientRepository.php` (only `listNotes`); `system/routes/web.php` (no client note POST).  
   - **Missing backend contract:** CRUD + permissions + audit events for `client_notes`.

7. **Staff “exception” modeling**  
   - **Why risky:** Booker-style PTO/sick overrides by **date** may not map cleanly to recurring `staff_breaks` + `appointment_blocked_slots` only.  
   - **Files:** `staff_breaks`, `appointment_blocked_slots` schema; `StaffBreakService`; `BlockedSlotService`.  
   - **Missing contract:** Date-level availability exceptions (if required) not named as first-class entities.

8. **Multi-branch schema vs single-salon product**  
   - **Why risky:** Widespread `branch_id` (`branches` table, `BranchContext`) adds branching rules to every query—not “SaaS multi-business,” but **complexity** for a single location.  
   - **Files:** `BranchContext.php`; schema on `appointments`, `staff`, `clients`, etc.  
   - **Resolved (BKM-010):** **`booker-modernization-single-salon-branch-position.md`** — keep branch foundation; single salon = one `branches` row + operational consistency; **no schema/middleware removal** without a separate approved task.

## 7. Bloat / Simplification Candidates

**Safe to delete later** (only with follow-up proof task—**not** approved now)

- None listed for immediate deletion; repo actively registers **GiftCards**, **Packages**, **Memberships** in `modules/bootstrap.php` and routes—removal would require dependency proof across sales/appointments.

**Safe to merge later**

- Overlapping “notes” concepts: `clients.notes` (profile), `appointments.notes`, `client_notes` table, issue flag notes, registration notes—**merge strategy** could reduce fragmentation after a **data audit** (no merge execution in this phase).

**Safe to postpone**

- **Gift cards / packages / memberships** modules: large surface area; not required for booking concurrency core. Postpone until booking/staff/CRM gaps are closed.
- **Inventory** module: unrelated to appointment calendar backend.
- **Hard removal of `branch_id`**: **deferred indefinitely** unless a future approved migration task proves full decoupling; single salon runs with **one branch** per BKM-010 (`booker-modernization-single-salon-branch-position.md`).

## 8. Execution Phases

Locked IDs — **backend-first, safest order**. No implementation in this document.

### BKM-001 — Booking write-path inventory & concurrency contract (design-only execution)

- **Objective:** Produce a single written contract listing every code path that inserts/updates appointment times, lock order, transaction boundaries, and required error semantics; map to current methods (`create`, `update`, `createFromSlot`, `reschedule`, `PublicBookingService::createBooking`, `WaitlistService` conversion).
- **Files likely touched later:** `system/modules/appointments/services/AppointmentService.php`, `system/modules/online-booking/services/PublicBookingService.php`, `system/modules/appointments/services/WaitlistService.php`, `system/modules/appointments/repositories/AppointmentRepository.php`, possibly `AvailabilityService.php`.
- **Depends on:** None.
- **Why safest:** No behavior change until contract is explicit—prevents ad hoc fixes on one path only.

### BKM-002 — Public booking transactional parity

- **Objective:** Align `PublicBookingService::createBooking` with the locking/transaction strategy defined in BKM-001 (match or exceed `createFromSlot`).
- **Files likely touched later:** `PublicBookingService.php`, `AppointmentRepository.php`, possibly `AvailabilityService.php`.
- **Depends on:** BKM-001.
- **Why safest:** Closes highest-exposure unauthenticated race without touching staff UI flows first.

### BKM-003 — Admin `create` / `update` parity with locked slot pipeline

- **Objective:** Ensure `AppointmentService::create` and time-changing `update` cannot bypass the same conflict/lock rules as `createFromSlot` / `reschedule`.
- **Files likely touched later:** `AppointmentService.php`, `AppointmentController.php` (only if contract requires routing split), `AppointmentRepository.php`.
- **Depends on:** BKM-001; ideally after BKM-002 (reuse same primitives).
- **Why safest:** Removes asymmetric safety between form create and slot create.

### BKM-004 — Reschedule / move API contract (backend)

- **Objective:** Define stable request/response and idempotency for “move appointment” (may remain `POST /appointments/{id}/reschedule` or additive JSON endpoint—**decision in BKM-001 follow-up**); ensure `update` cannot subvert move rules.
- **Files likely touched later:** `AppointmentController.php`, `AppointmentService.php`, `system/routes/web.php`.
- **Depends on:** BKM-003.
- **Why safest:** Calendar DnD clients (when allowed later) need a **single** move contract after core writes are safe.

### BKM-005 — Timezone correctness pass

- **Objective:** Bind slot generation, “today,” and lead-time windows to documented TZ (`establishment.timezone` + PHP/DB behavior); eliminate silent local-server TZ drift.
- **Files likely touched later:** `AvailabilityService.php`, `PublicBookingService.php`, `AppointmentService.php`, `SettingsService.php`, `system/config/app.php`.
- **Depends on:** BKM-001 (contract); **sequenced after** BKM-002–003 so locking tests are not conflated with TZ changes.
- **Why safest:** TZ changes alter boundaries; do after atomic booking behavior is defined.

### BKM-006 — Staff availability exceptions model (if gap accepted)

- **Objective:** If product requires date-specific overrides beyond `staff_breaks` + `appointment_blocked_slots`, add minimal schema/service contract; otherwise document “use blocked slots for ad-hoc” as intentional.
- **Files likely touched later:** `system/data/migrations/*.sql`, `StaffScheduleService` / new service, `AvailabilityService.php`, `StaffController.php`.
- **Depends on:** BKM-005 (TZ stable for “date”).
- **Why safest:** Schema additions after booking invariants are stable.

### BKM-007 — CRM `client_notes` write completeness

- **Objective:** Add service + repository methods + routes + permissions + audit actions for creating (and optionally soft-deleting) `client_notes`; keep scope minimal.
- **Files likely touched later:** `ClientRepository.php`, `ClientService.php`, `ClientController.php`, `system/routes/web.php`, seeders for permissions if needed.
- **Depends on:** BKM-001 (audit naming); can proceed in parallel with BKM-006 after BKM-003—**prefer after BKM-003** to avoid competing transaction work.
- **Why safest:** Isolated domain; no calendar concurrency coupling.

### BKM-008 — Day calendar JSON contract versioning

- **Objective:** Document and optionally extend `dayCalendar` JSON for future move-preview fields (e.g. version key, server-computed “can_place_at”) **without** mandating UI work.
- **Files likely touched later:** `AppointmentController.php` (`dayCalendar`), `AvailabilityService.php`.
- **Depends on:** BKM-004 (move semantics stable).
- **Why safest:** Read-model extensions after move rules exist.

### BKM-009 — Waitlist vs availability integration review

- **Objective:** Verify waitlist conversion and suggestions always use hardened booking path from BKM-002–003; adjust only if gaps found.
- **Files likely touched later:** `WaitlistService.php`, `WaitlistRepository.php`, `AppointmentService.php`.
- **Depends on:** BKM-002, BKM-003.
- **Why safest:** Waitlist volume lower than public book but must not reintroduce races.

### BKM-010 — Single-salon / branch simplification proof task

- **Objective:** Document operational decision: one branch deployment vs code simplification; **no code deletion** unless a later task proves dead paths.
- **Files likely touched later:** documentation only; optional future `BranchContext` usage audit.
- **Depends on:** BKM-005–007 complete or stable.
- **Why safest:** Policy last to avoid premature structural removal.

## 9. Stop Conditions

Pause and **do not** continue automatic implementation when:

- **Insufficient repo evidence** for a change (e.g. unclear production TZ requirement, unknown client note permissions model).
- **Scope ambiguity** (e.g. whether `update` may change times vs forced `reschedule` only).
- A **deletion candidate** is not **fully proven** safe (no dependency graph / route usage proof).
- A task would **force UI work** before the backend contract is locked (per out-of-scope rules).
- **Setting or schema** changes would affect compliance (consents, audit) without stakeholder sign-off—stop and record under change-control “New Findings.”

---

## New Findings

_(Append-only during implementation; do not use to expand scope without revising this plan.)_
