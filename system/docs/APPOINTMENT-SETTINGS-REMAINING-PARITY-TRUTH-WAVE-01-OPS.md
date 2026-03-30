# Appointment settings — remaining screenshot parity uncertainty (truth wave 01)

**Task:** `APPOINTMENT-SETTINGS-REMAINING-PARITY-TRUTH-WAVE-01`  
**Scope:** Read-only audits for four deferred lanes + verifier fix for print product-purchase-history foundation. No new settings, no booking behavior change, no implementation of deferred features in this wave.

## Verifier note (print lane false negative)

`verify_appointment_print_product_purchase_history_foundation_01.php` previously used `AppointmentPrintSummaryService\([^)]*ClientSalesProfileProvider::class`, which fails on the real bootstrap line because `$c->get(\Some\Class::class)` injects closing `)` before `ClientSalesProfileProvider::class`. The check now matches the `new \Modules\Appointments\Services\AppointmentPrintSummaryService(...)` constructor call up to the same PHP statement (`[^;]*`), which reflects actual DI in `register_appointments_online_contracts.php` line registering `AppointmentPrintSummaryService`.

---

## 2) Service check-in — truth

**Post-audit:** **`APPOINTMENT-CHECK-IN-FOUNDATION-IMPLEMENTATION-01`** adds `checked_in_at` / `checked_in_by`, internal check-in POST, and show-page display — see **`APPOINTMENT-CHECK-IN-FOUNDATION-IMPLEMENTATION-01-OPS.md`**. Below reflects **audit-time** repo state; supersede domain bullets with that doc for current schema.

### Domain / model

- **At audit time:** **No** `checked_in_*` columns on `appointments` (see `full_project_schema.sql` snapshot; apply migration **`095_appointments_check_in_foundation.sql`** for runtime).
- **No** `appointments.*` settings key for check-in in `SettingsService`, `SettingsController` allowlists, or `modules/settings/views/index.php`.

### Partial wiring

- **Status workflow only:** `AppointmentService::VALID_STATUSES` includes `in_progress` and `completed`; `updateStatus` performs guarded transitions (`assertStatusTransition`). Operators can move e.g. `confirmed` → `in_progress` → `completed` manually (`AppointmentController::updateStatusAction`, `views/show.php` / `views/edit.php`). This is **generic status**, not a “check-in” feature, timestamp, or setting.
- **Comment-only future hook:** `DocumentController` docblock mentions JSON “for reuse by check-in and booking” — no check-in consumer in-repo.

### Screenshot parity

- **(c) Blocked** for a dedicated “service check-in setting / flow” as typically shown in product screenshots: **no** setting, **no** check-in event model, **no** automated flow distinct from manual status.

### Safest next boundary

- Define check-in as **either** (A) explicit domain (timestamps + optional setting + UI) **or** (B) document that `in_progress` is the only operational stand-in until (A) exists. Do not claim parity without schema + settings contract.

### Blockers

- **Cleared (foundation 01):** persistent operator check-in timestamp + user + internal route + show read surface.
- **Still open:** settings-driven check-in, public/kiosk flows, automatic status coupling, full screenshot “check-in module” parity.

---

## 3) Automatic package detection / auto-consumption — truth

**Foundation 01 verdict:** **`APPOINTMENT-PACKAGE-AUTO-CONSUMPTION-FOUNDATION-01-OPS.md`** — **blocked** until package definitions can be tied to services (or equivalent snapshot); static verifier `verify_appointment_package_auto_consumption_foundation_01.php`.

### Domain / model

- **Packages:** `client_packages`, usage tracking, `PackageService::consumeForCompletedAppointment` (via `AppointmentPackageConsumptionProvider`).
- **Appointment link:** `AppointmentPackageConsumptionProvider` + `AppointmentPackageConsumptionProviderImpl`; `AppointmentService::consumePackageSessions` calls `consumeForCompletedAppointment` with explicit `client_package_id` and `quantity`.

### Partial wiring

- **Manual only:** `POST /appointments/{id}/consume-package` → `AppointmentController::consumePackage` → `consumePackageSessions`. Requires **completed** appointment (`DomainException` otherwise).
- **No** call from `updateStatus` (or any path reviewed) to package consumption when status becomes `completed`.
- **No** settings key for auto-detect / auto-consume in appointment settings.
- **Read/display:** `listAppointmentConsumptions`, appointment show “Package consumption” section, print summary optional package section — all **observability**, not automation.

### Screenshot parity

- **(b) Supportable with additive implementation:** Core **manual** consumption and listing exist; automation would add policy (match service to package definition, idempotency, settings) on top.
- **Not (a)** — there is **no** automatic detection or consumption today.

### Safest next boundary

- Specify matching rules (service ↔ package SKU/template), idempotency with existing `hasAppointmentConsumption` / usage rows, and whether automation runs on status `completed` or on explicit action; add settings **in a later charter** (out of scope for this wave).

### Blockers for full auto parity

- **No** domain rule for “which client package applies to which appointment/service” without new logic (and likely schema or package metadata). **No** hook on completion.

---

## 4) Lock staff to room / space — truth

### Domain / model

- **Appointments:** optional `room_id` on `appointments`; conflict checks via `AppointmentRepository::hasRoomConflict` when room exclusivity enforced (`SettingsService::shouldEnforceAppointmentRoomExclusivity`).
- **Services:** `service_rooms` pivot — **which rooms are allowed for a service**, not staff-bound.
- **Staff:** `service_staff` and `service_staff_groups` — **which staff can perform a service**; **no** `staff_rooms`, `staff_room_id`, or equivalent in `full_project_schema.sql`.

### Partial wiring

- Internal slot search can filter by `room_id` (room occupancy); `allow_room_overbooking` bypasses room overlap only for internal paths — **not** a staff↔room lock.
- UI copy in settings mentions “room rules” in concurrency help text — **no** staff–room lock setting.

### Screenshot parity

- **(c) Blocked** for “lock staff to room/space”: **no** per-staff room binding, **no** validation that selected staff may only use certain rooms.

### Safest next boundary

- Introduce data model (e.g. `staff_rooms` or staff default room) + validation in booking/slot paths + settings if toggled behavior is required.

### Blockers

- **No** schema or service logic tying `staff_id` to allowed `room_id` set beyond independent service–room and service–staff graphs.

---

## 5) Staff-specific price / duration override — truth

### Domain / model

- **`services` table:** canonical `duration_minutes`, `buffer_*`, `price` per service row — **no** staff dimension.
- **`service_staff`:** composite key `(service_id, staff_id)` only — **no** price or duration columns (`full_project_schema.sql`).

### Partial wiring

- **Appointment timing:** `AvailabilityService::getServiceDurationMinutes` / service row; `AppointmentService` move/reschedule paths use service duration — **single** duration per `service_id`.
- **Invoicing / payroll:** out of scope for this audit except to note there is **no** staff-specific service price on the service definition table for booking UI.

### Screenshot parity

- **(c) Blocked** for per-staff price/duration overrides on the service as booked: **no** columns, **no** settings, **no** merge logic in appointment or availability layer.

### Safest next boundary

- Add override store (table or JSON) keyed by `(service_id, staff_id[, branch_id])` + read path in `AvailabilityService` and `AppointmentService` duration/price resolution + migration.

### Blockers

- **No** persistence or resolver for staff-specific `duration_minutes` / `price` relative to `services`.

---

## Cross-reference

- Deferred lanes already called out in `APPOINTMENT-SETTINGS-BACKEND-CONTRACT-FOUNDATION-01-OPS.md` (intentionally out of scope section).
- Package consumption contract: `Core\Contracts\AppointmentPackageConsumptionProvider`, implementation `Modules\Packages\Providers\AppointmentPackageConsumptionProviderImpl`.

---

## 9) Safe next implementation order (recommendation only)

1. **Staff-specific price/duration** — needs schema + resolver; touches booking duration end-to-end; do before or with any pricing display parity.
2. **Lock staff to room** — schema + validation layered on existing `service_rooms` / `room_id` booking.
3. **Automatic package consumption** — policy + completion hook + idempotency; depends on clear package–service matching rules.
4. **Check-in** — greenfield workflow (fields/settings/UI); least coupled to existing partials but no foundation yet.

## 10) Explicit out-of-scope (this wave)

- Implementing check-in, package auto-detection, staff–room lock, or staff-specific price/duration.
- Adding settings keys or UI for those lanes.
- Broad refactors, booking behavior changes, or screenshot/UI claims without code truth.
