# Booker Modernization Checklist

## Status Legend

- LOCKED
- READY
- IN_PROGRESS
- BLOCKED
- DONE
- REJECTED

### BKM-001 — Booking write-path inventory & concurrency contract (design-only execution)

Status: DONE  
Depends on: none  
Touches later: `system/modules/appointments/services/AppointmentService.php`, `system/modules/online-booking/services/PublicBookingService.php`, `system/modules/appointments/services/WaitlistService.php`, `system/modules/appointments/repositories/AppointmentRepository.php`, optionally `system/modules/appointments/services/AvailabilityService.php`  
Deliverable: **Delivered in** `system/docs/booker-modernization-booking-concurrency-contract.md` — write-path inventory (W1–W8), shared availability mechanics, normative slot pipeline for BKM-002+, lock-order recommendation, `update` vs `reschedule` decision placeholder, failure semantics, master plan §6 cross-check.  
Do not do: Change application behavior, routes, or schema before that contract is reviewed against this master plan.

### BKM-002 — Public booking transactional parity

Status: DONE  
Depends on: BKM-001  
Touches later: `system/modules/online-booking/services/PublicBookingService.php`, `system/modules/appointments/repositories/AppointmentRepository.php`, optionally `system/modules/appointments/services/AvailabilityService.php`  
Deliverable: **Done:** `createBooking` delegates to `AppointmentService::createFromPublicBooking` → shared `insertNewSlotAppointmentWithLocks` (staff/service `FOR UPDATE`, consent + `isSlotAvailable` + insert in one transaction). Contract doc W3 updated.  
Do not do: Redesign public API shapes or add UI; stay within locked booking semantics.

### BKM-003 — Admin create / update parity with locked slot pipeline

Status: DONE  
Depends on: BKM-001 (and ideally BKM-002 for shared primitives)  
Touches later: `system/modules/appointments/services/AppointmentService.php`, optionally `system/modules/appointments/controllers/AppointmentController.php`, `system/modules/appointments/repositories/AppointmentRepository.php`  
Deliverable: **Done:** `create` calls `lockActiveStaffAndServiceRows` before `checkConflicts`; `update` uses `appointments … FOR UPDATE` then same staff/service locks; `insertNewSlotAppointmentWithLocks` refactored to shared helper. Edit vs `reschedule` rule differences deferred to **BKM-004** (documented in contract §7).  
Do not do: Broad appointment UI or form refactors unrelated to time writes.

### BKM-004 — Reschedule / move API contract (backend)

Status: DONE  
Depends on: BKM-003  
Touches later: `system/modules/appointments/controllers/AppointmentController.php`, `system/modules/appointments/services/AppointmentService.php`, `system/routes/web.php`  
Deliverable: **Done:** `AppointmentService::buildServiceBasedMovePatchAfterAppointmentLock` — single locked pipeline (appointment `FOR UPDATE` → staff/service locks → `validateTimes` → optional consent → `isSlotAvailable` + service-derived `end_at`; room via non-locking `hasRoomConflict`). `update` routes scheduling mutations through it when `service_id` + `staff_id` are set; `reschedule` delegates to the same helper. Contract W5/W6 and §7 updated.  
Do not do: Implement drag-and-drop UI or calendar visuals.

### BKM-005 — Timezone correctness pass

Status: DONE  
Depends on: BKM-001; sequence after BKM-002–003  
Touches later: `system/modules/appointments/services/AvailabilityService.php`, `system/modules/online-booking/services/PublicBookingService.php`, `system/modules/appointments/services/AppointmentService.php`, `system/core/app/SettingsService.php`, `system/config/app.php`  
Deliverable: **Done:** `ApplicationTimezone::applyForHttpRequest()` at `Application::run()` sets an initial default TZ; `BranchContextMiddleware` calls `ApplicationTimezone::syncAfterBranchContextResolved()` so branch-effective `establishment.timezone` applies for resolved staff context (guests: global merge). Fallback: `APP_TIMEZONE` / config `app.timezone` else UTC. Contract §11 documents storage vs runtime, DB/CLI limits. No DB or booking API shape changes.  
Do not do: Payment, invoice, or marketing timezone scope.

### BKM-006 — Staff availability exceptions model (if gap accepted)

Status: DONE  
Depends on: BKM-005  
Touches later: `system/data/migrations/*.sql`, `system/modules/staff/services/StaffScheduleService.php` and/or new service, `system/modules/appointments/services/AvailabilityService.php`, `system/modules/staff/controllers/StaffController.php`  
Deliverable: **Done:** Migration `052_create_staff_availability_exceptions.sql`; `StaffAvailabilityExceptionRepository`; `AvailabilityService::getWorkingIntervals` applies `closed` / merged `open` / weekly schedule + `unavailable` subtraction; wired in bootstrap; `getDayGrid` uses same path; contract §12. No admin UI/routes (backend + schema only).  
Do not do: Full workforce management or HR product scope.

### BKM-007 — CRM `client_notes` write completeness

Status: DONE  
Depends on: BKM-001; prefer after BKM-003  
Touches later: `system/modules/clients/repositories/ClientRepository.php`, `system/modules/clients/services/ClientService.php`, `system/modules/clients/controllers/ClientController.php`, `system/routes/web.php`, permission seeders if required  
Deliverable: **Done:** `ClientRepository::createNote` / `findNote` / `softDeleteNote`; `ClientService::addClientNote` / `deleteClientNote` with `BranchContext::assertBranchMatch`, audit `client_note_created` / `client_note_deleted` on target `client`; routes `POST /clients/{id}/notes`, `POST /clients/{id}/notes/{noteId}/delete` (`clients.edit`); minimal client show add/remove forms for editors.  
Do not do: Redesign client profile UI beyond what the locked contract requires.

### BKM-008 — Day calendar JSON contract versioning

Status: DONE  
Depends on: BKM-004  
Touches later: `system/modules/appointments/controllers/AppointmentController.php` (`dayCalendar`), `system/modules/appointments/services/AvailabilityService.php`  
Deliverable: **Done:** `day_calendar_contract` (`name` `spa.day_calendar`, `version` 1) + `capabilities.move_preview` false on every `GET /calendar/day` response (including 422); additive `branch_id`; legacy keys unchanged; contract §13.  
Do not do: CSS/JS calendar implementation work.

### BKM-009 — Waitlist vs availability integration review

Status: DONE  
Depends on: BKM-002, BKM-003  
Touches later: `system/modules/appointments/services/WaitlistService.php`, `system/modules/appointments/repositories/WaitlistRepository.php`, `system/modules/appointments/services/AppointmentService.php`  
Deliverable: **Done:** Repo audit confirmed `convertToAppointment` delegates only to `AppointmentService::createFromSlot` (W2); no appointment creation elsewhere in waitlist; availability and BKM-006 exceptions apply. Contract W4 updated with BKM-009 verification note. No code change required.  
Do not do: Waitlist marketing automation or notifications redesign unless blocking correctness.

### BKM-010 — Single-salon / branch simplification proof task

Status: DONE  
Depends on: BKM-005–007 stable  
Touches later: Documentation; optional future `system/core/branch/BranchContext.php` usage audit  
Deliverable: **Done:** `system/docs/booker-modernization-single-salon-branch-position.md` — repo-proven decision to **keep** `branches` / `branch_id` / `BranchContext` + middleware; single-salon ops = one branch row + consistent scoping; classify keep / overhead / defer / unsafe removal; master plan §6–§7 cross-links. **No code or schema deletion.**  
Do not do: Rip out `branch_id` or multi-branch schema without a separate approved deletion task.

---

### Booker modernization track (BKM-001–BKM-010)

**Status: COMPLETE** — All items BKM-001 through BKM-010 are **DONE**. Further work is **out of scope** of this checklist unless a new phase adds tasks under change-control.

### ZIP-AUDIT-03 — Membership refund-review operator workflow

Status: DONE  
Depends on: none (post-Booker ZIP audit gap)  
Touches: `system/modules/memberships/routes/web.php`, `MembershipRefundReviewController.php`, `MembershipRefundReviewService.php`, `MembershipSaleService::operatorReevaluateRefundReviewSale`, `MembershipBillingService` operator refund-review methods, repositories `listRefundReview` / `listRefundReviewQueue`, `modules/memberships/views/refund-review/index.php`, `bootstrap.php` bindings  
Deliverable: Operator inbox at **`GET /memberships/refund-review`** with **Reconcile from invoice** (canonical re-settlement) and **Acknowledge** (audit-only) for initial sales in **`refund_review`** and renewal billing cycles in the refund-review queue; all actions audited; branch checks + inbox eligibility guards. Proof and route/action index: **`booker-modernization-task-breakdown.md`** § ZIP-AUDIT-03.  
Do not do: Auto-reverse memberships without invoice-driven settlement; broaden scope beyond memberships refund-review surfaces.
