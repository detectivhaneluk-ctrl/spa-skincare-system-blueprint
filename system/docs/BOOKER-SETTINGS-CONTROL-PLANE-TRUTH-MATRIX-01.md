# Booker Settings control plane — truth matrix 01

**Foundation ID:** `SETTINGS-CONTROL-PLANE-TRUTH-MATRIX-FOUNDATION-01`  
**Date:** 2026-03-25  
**Authority:** **Live code** under `system/` (routes, modules, core, migrations). `archive/` and older docs are **non-authoritative** unless re-verified here.  
**No repairs implemented** in this document wave — baseline only.

**Risk scale:** `L` · `M` · `H` (false promise, safety, or operator trust).

**Shared primitives**

| Primitive | Location / role |
|-----------|------------------|
| Settings HTTP | `GET/POST /settings` — `system/routes/web/register_settings.php` → `Modules\Settings\Controllers\SettingsController` |
| Settings workspace UI | `system/modules/settings/views/index.php` + `system/modules/settings/views/partials/shell.php` |
| KV settings | `Core\App\SettingsService` → `settings` table (keys per group in `SettingsService` constants) |
| Booker↔settings map (historical) | `system/docs/ADMIN-SETTINGS-BACKLOG-ROADMAP.md` — verify in code before relying on |

---

## Matrix rows (Booker-vs-repo control plane)

Each row uses the same attribute set.

---

### 1. Establishment information (overview / primary contact)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Establishment information |
| **Native vs launcher** | Native — `section=establishment`, screens `overview`, `edit-overview`, `edit-primary-contact` |
| **Route(s)** | `GET /settings?section=establishment&screen=…` · `POST /settings` (same section/screen hidden fields) |
| **Controller(s)** | `Modules\Settings\Controllers\SettingsController` |
| **Service / repository / keys** | `SettingsService::getEstablishmentSettings`, `patchEstablishmentSettings`; keys `establishment.name`, `establishment.phone`, `establishment.email`, `establishment.address`, `establishment.currency`, `establishment.timezone`, `establishment.language` (see `SettingsController::ESTABLISHMENT_WRITE_KEYS` for org-default save) |
| **View file(s)** | `modules/settings/views/establishment/screens/overview.php`, `edit-overview.php`, `edit-primary-contact.php` (and `index.php` router) |
| **Read-side SoT** | `settings` rows (org default `branch_id` semantics via `SettingsService::get`) + validated defaults |
| **Write-side SoT** | `POST /settings` → `patchEstablishmentSettings($patch, null)` for primary org fields |
| **Runtime consumer(s)** | Currency/timezone/language: `SettingsService` effective helpers, `ApplicationTimezone`, `ApplicationContentLanguage` / branch middleware (per `SettingsService` class docblock) |
| **Verdict** | **REAL** (for listed org-default fields) |
| **Evidence** | `SettingsController::store` establishment patch path; keys in `ALL_ALLOWED_WRITE_KEYS` |
| **Risk** | **M** — same “Establishment” nav also hosts branch-scoped screens (see rows 2–3); scope must be read carefully |
| **Next action** | Wave 2: IA copy clarifying org vs branch under Establishment |

---

### 2. Opening hours

| Attribute | Value |
|-----------|--------|
| **Subsection** | Opening hours |
| **Native vs launcher** | Native — subtree of **Establishment**: `section=establishment`, `screen=opening-hours` |
| **Route(s)** | `GET /settings?section=establishment&screen=opening-hours` · `POST /settings` (branch hours payload) |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `Modules\Settings\Services\BranchOperatingHoursService` · persistence: migration `092_create_branch_operating_hours_table.sql` → table `branch_operating_hours` · **not** `settings` KV rows |
| **View file(s)** | `modules/settings/views/establishment/screens/opening-hours.php` |
| **Read-side SoT** | `BranchOperatingHoursService::getWeeklyMapForBranch($branchId)` when `isStorageReady()` |
| **Write-side SoT** | `BranchOperatingHoursService::saveWeeklyMapForBranch` from `POST['opening_hours']` |
| **Runtime consumer(s)** | Appointment validation / calendar — `AppointmentService` + operating-hours integration (branch effective hours) |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Requires resolvable branch context (`SettingsController::resolveOpeningHoursBranchId`); if none, UI cannot save |
| **Risk** | **M** — branch context resolution can block saves silently from an operator’s POV without reading flash/errors |
| **Next action** | Wave 2: empty-state messaging when no branch context |

---

### 3. Closure dates

| Attribute | Value |
|-----------|--------|
| **Subsection** | Closure dates |
| **Native vs launcher** | Native — subtree of **Establishment**: `section=establishment`, `screen=closure-dates` |
| **Route(s)** | `GET /settings?section=establishment&screen=closure-dates` · `POST /settings` (`closure_dates_action`, etc.) |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `Modules\Settings\Services\BranchClosureDateService` · migration `093_create_branch_closure_dates_table.sql` → `branch_closure_dates` |
| **View file(s)** | `modules/settings/views/establishment/screens/closure-dates.php` |
| **Read-side SoT** | `BranchClosureDateService::listForBranch` |
| **Write-side SoT** | `createForBranch` / `updateForBranch` / `deleteForBranch` driven by `closure_dates_action` |
| **Runtime consumer(s)** | Appointment / hours logic where closure dates are consulted (same operational stack as hours) |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Same branch-context requirement pattern as opening hours |
| **Risk** | **M** |
| **Next action** | Wave 2: align closure-date UX hints with branch resolution |

---

### 4. Establishment — secondary contact (branch-scoped)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Secondary contact (Booker-style “second contact” under establishment) |
| **Native vs launcher** | Native — `screen=edit-secondary-contact` |
| **Route(s)** | `GET/POST /settings?section=establishment&screen=edit-secondary-contact` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `SettingsService::patchEstablishmentSettings` with `secondary_contact_*` keys on **branch** id |
| **View file(s)** | `modules/settings/views/establishment/screens/edit-secondary-contact.php` |
| **Read-side SoT** | Branch-scoped establishment keys via `getEstablishmentSettings($secondaryContactBranchId)` |
| **Write-side SoT** | `patchEstablishmentSettings($patch, $secondaryBranchId)` |
| **Runtime consumer(s)** | Display / future comms consumers of establishment branch merge |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Branch-scoped; requires `resolveSecondaryContactBranchId` |
| **Risk** | **M** |
| **Next action** | Wave 2: label as branch-scoped |

---

### 5. Cancellation policy

| Attribute | Value |
|-----------|--------|
| **Subsection** | Cancellation policy |
| **Native vs launcher** | Native — `section=cancellation` |
| **Route(s)** | `GET/POST /settings?section=cancellation` (+ query modes for policy text / reasons editor) |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `SettingsService::getCancellationPolicySettings`, `patchCancellationSettings`, `getCancellationRuntimeEnforcement`; keys in `SettingsService::CANCELLATION_KEYS` · reasons: `AppointmentCancellationReasonService` + migration **094** (per UI message when storage missing) |
| **View file(s)** | `modules/settings/views/index.php` (cancellation blocks) |
| **Read-side SoT** | `settings` KV + reasons table when ready |
| **Write-side SoT** | KV patches + reason CRUD posts (`cancellation_reasons_action`) |
| **Runtime consumer(s)** | **Enforced on cancel:** `AppointmentService::cancel` → `getCancellationRuntimeEnforcement` (enabled, min notice, reason requirement, override). **Self-service:** `PublicBookingService` policy payload. **Not enforced:** fee/tax/customer_scope (UI marks many as configuration-only) |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | `getCancellationRuntimeEnforcement` omits economic fields; fees not charged in cancel path |
| **Risk** | **H** |
| **Next action** | Wave 2: strengthen fee/tax/scope honesty; Wave 3+: enforcement or DEFER |

---

### 6. Appointment settings

| Attribute | Value |
|-----------|--------|
| **Subsection** | Appointment settings |
| **Native vs launcher** | Native — `section=appointments` |
| **Route(s)** | `GET /settings?section=appointments&appointments_branch_id=…` · `POST /settings` + `appointments_context_branch_id` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `SettingsService::getAppointmentSettings`, `patchAppointmentSettings`; keys in `SettingsController::APPOINTMENT_WRITE_KEYS` / `SettingsService::APPOINTMENT_KEYS` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | Branch-effective merge via `SettingsService` |
| **Write-side SoT** | `patchAppointmentSettings` for org (0) or branch override |
| **Runtime consumer(s)** | `AppointmentService`, `AvailabilityService`, `AppointmentController`, `ClientAppointmentProfileProviderImpl`, `AppointmentPrintSummaryService`, public booking where applicable |
| **Verdict** | **REAL** |
| **Evidence** | Consumers grep to `getAppointmentSettings` / appointment keys |
| **Risk** | **L** |
| **Next action** | Optional Wave 2: pointer doc for internal vs public |

---

### 7. Payment settings

| Attribute | Value |
|-----------|--------|
| **Subsection** | Payment settings |
| **Native vs launcher** | Native — `section=payments` |
| **Route(s)** | `GET/POST /settings?section=payments` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `SettingsService::getPaymentSettings`, `patchPaymentSettings`; `payments.default_method_code`, `payments.allow_partial_payments`, `payments.allow_overpayments`, `payments.receipt_notes` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | `settings` KV |
| **Write-side SoT** | `patchPaymentSettings` |
| **Runtime consumer(s)** | `PaymentService`, `PaymentController`, `InvoiceService`, `InvoiceController` |
| **Verdict** | **REAL** |
| **Evidence** | Sales module reads all four concerns |
| **Risk** | **L** |
| **Next action** | — |

---

### 8. Custom payment methods

| Attribute | Value |
|-----------|--------|
| **Subsection** | Custom payment methods |
| **Native vs launcher** | Native admin (table-backed, not KV subsection in `SettingsController::SECTION_ALLOWED_KEYS`) |
| **Route(s)** | `GET/POST /settings/payment-methods`, `…/create`, `…/{id}/edit`, `…/{id}` — `register_settings.php` |
| **Controller(s)** | `Modules\Settings\Controllers\PaymentMethodsController` |
| **Service / repository / keys** | `Modules\Sales\Services\PaymentMethodService` → `payment_methods` table |
| **View file(s)** | `modules/settings/views/payment-methods/*.php` + `partials/shell.php` |
| **Read-side SoT** | DB rows |
| **Write-side SoT** | `create` / `update` (admin branch id `null` in controller) |
| **Runtime consumer(s)** | Payment UI / `PaymentService` method resolution |
| **Verdict** | **REAL** |
| **Evidence** | Dedicated routes and CRUD |
| **Risk** | **L** |
| **Next action** | — |

---

### 9. Tax types (VAT types)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Tax types |
| **Native vs launcher** | Native admin CRUD |
| **Route(s)** | `GET/POST /settings/vat-rates` (+ create/edit) — `register_settings.php` |
| **Controller(s)** | `Modules\Settings\Controllers\VatRatesController` |
| **Service / repository / keys** | `VatRateService` / `VatRateRepository` → `vat_rates` |
| **View file(s)** | `modules/settings/views/vat-rates/*.php` + `shell.php` |
| **Read-side SoT** | DB |
| **Write-side SoT** | CRUD |
| **Runtime consumer(s)** | `ServiceService` (`vat_rate_id`), invoicing / reports |
| **Verdict** | **REAL** |
| **Evidence** | Assignability checks on service write |
| **Risk** | **L** |
| **Next action** | — |

---

### 10. Tax allocation (VAT distribution)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Tax allocation |
| **Native vs launcher** | Launcher from settings sidebar |
| **Route(s)** | `GET /reports/vat-distribution` — `register_reports.php` |
| **Controller(s)** | `Modules\Reports\Controllers\ReportController::vatDistribution` |
| **Service / repository / keys** | `ReportService::getVatDistribution` (aggregates transactional data) |
| **View file(s)** | **None** — controller returns JSON only |
| **Read-side SoT** | Report query layer |
| **Write-side SoT** | None |
| **Runtime consumer(s)** | JSON API clients (e.g. dashboard) |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Browser opens raw JSON — not an HTML report page |
| **Risk** | **H** |
| **Next action** | Wave 2: relabel or redirect to a real viewer |

---

### 11. Internal notifications

| Attribute | Value |
|-----------|--------|
| **Subsection** | Internal notifications |
| **Native vs launcher** | Native — `section=notifications` |
| **Route(s)** | `GET/POST /settings?section=notifications` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `notifications.appointments_enabled`, `notifications.sales_enabled`, `notifications.waitlist_enabled`, `notifications.memberships_enabled` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | `SettingsService::getNotificationSettings` |
| **Write-side SoT** | `patchNotificationSettings` |
| **Runtime consumer(s)** | In-app: `SettingsService::shouldEmitInAppNotificationForType`. Outbound: `shouldEmitOutboundNotificationForEvent` — **sales outbound not gated by `sales_enabled`** per `SettingsService` header docblock |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Asymmetric in-app vs outbound semantics |
| **Risk** | **M** |
| **Next action** | Wave 2: inline help text |

---

### 12. Hardware / device settings

| Attribute | Value |
|-----------|--------|
| **Subsection** | Hardware / device settings |
| **Native vs launcher** | Native — `section=hardware` (sidebar label “IT Hardware”) |
| **Route(s)** | `GET/POST /settings?section=hardware` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `hardware.use_cash_register`, `hardware.use_receipt_printer` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | `getHardwareSettings` |
| **Write-side SoT** | `patchHardwareSettings` |
| **Runtime consumer(s)** | `PaymentService` (cash register); `InvoiceService` / `isReceiptPrintingEnabled` (receipt printer flag — roadmap §5.D notes no physical driver) |
| **Verdict** | **REAL** |
| **Evidence** | Sales reads flags |
| **Risk** | **L** |
| **Next action** | — |

---

### 13. Security

| Attribute | Value |
|-----------|--------|
| **Subsection** | Security |
| **Native vs launcher** | Native — `section=security` |
| **Route(s)** | `GET/POST /settings?section=security` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `security.password_expiration`, `security.inactivity_timeout_minutes` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | `getSecuritySettings` |
| **Write-side SoT** | `patchSecuritySettings` |
| **Runtime consumer(s)** | `Core\Middleware\AuthMiddleware` |
| **Verdict** | **REAL** |
| **Evidence** | Middleware reads both keys |
| **Risk** | **L** |
| **Next action** | — |

---

### 14. Marketing settings

| Attribute | Value |
|-----------|--------|
| **Subsection** | Marketing settings |
| **Native vs launcher** | Native — `section=marketing` |
| **Route(s)** | `GET/POST /settings?section=marketing` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `marketing.default_opt_in`, `marketing.consent_label` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | `getMarketingSettings` |
| **Write-side SoT** | `patchMarketingSettings` |
| **Runtime consumer(s)** | Client intake/registration paths per `SettingsService` docblock |
| **Verdict** | **REAL** (narrow) |
| **Evidence** | Only two keys in allow-list — not full Booker marketing module |
| **Risk** | **M** (Booker parity gap by design) |
| **Next action** | Wave 2: subtitle “client consent defaults” |

---

### 15. Waitlist settings

| Attribute | Value |
|-----------|--------|
| **Subsection** | Waitlist settings |
| **Native vs launcher** | Native — `section=waitlist` |
| **Route(s)** | `GET/POST /settings?section=waitlist` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `waitlist.*` keys in `SettingsService::WAITLIST_KEYS` |
| **View file(s)** | `modules/settings/views/index.php` |
| **Read-side SoT** | `getWaitlistSettings` |
| **Write-side SoT** | `patchWaitlistSettings` |
| **Runtime consumer(s)** | `WaitlistService` |
| **Verdict** | **REAL** |
| **Evidence** | Service consumes settings |
| **Risk** | **L** |
| **Next action** | — |

---

### 16. Online booking settings (as implemented: public channels)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Online booking settings (Booker); **repo:** public channels bundle |
| **Native vs launcher** | Native — `section=public_channels` (sidebar: “Online Booking”) |
| **Route(s)** | `GET/POST /settings?section=public_channels` + `online_booking_branch_id` / `online_booking_context_branch_id` |
| **Controller(s)** | `SettingsController` |
| **Service / repository / keys** | `online_booking.*`, `intake.*`, `public_commerce.*` (see `SECTION_ALLOWED_KEYS['public_channels']`) |
| **View file(s)** | `modules/settings/views/index.php` (public channels card + forms) |
| **Read-side SoT** | Branch-effective settings merge |
| **Write-side SoT** | `patchOnlineBookingSettings`, `patchIntakeSettings`, `patchPublicCommerceSettings` |
| **Runtime consumer(s)** | `PublicBookingService`, `IntakeFormService`, `PublicCommerceService` |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Three Booker domains in one `section` |
| **Risk** | **M** |
| **Next action** | Wave 2: directory labels / subheads |

---

### 17. Spaces

| Attribute | Value |
|-----------|--------|
| **Subsection** | Spaces |
| **Native vs launcher** | Launcher — `shell.php` “Related module launchers” |
| **Route(s)** | Sidebar: `/services-resources/rooms/create` only · full module: `/services-resources/rooms`, `…/rooms/{id}`, etc. — `register_services_resources.php` |
| **Controller(s)** | `Modules\ServicesResources\Controllers\RoomController` |
| **Service / repository / keys** | Rooms repositories / services (not `settings` KV) |
| **View file(s)** | Services-resources views (not settings `index.php` body) |
| **Read-side SoT** | Rooms tables |
| **Write-side SoT** | Room CRUD POST routes |
| **Runtime consumer(s)** | Appointments, availability, room occupancy |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | No “all spaces” link from settings shell |
| **Risk** | **M** |
| **Next action** | Wave 2: add `/services-resources/rooms` link |

---

### 18. Material (equipment)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Material |
| **Native vs launcher** | Launcher |
| **Route(s)** | Sidebar: `/services-resources/equipment/create` · module: `/services-resources/equipment` |
| **Controller(s)** | `EquipmentController` |
| **Service / repository / keys** | Equipment tables / services |
| **View file(s)** | Services-resources module views |
| **Read-side SoT** | DB |
| **Write-side SoT** | CRUD routes |
| **Runtime consumer(s)** | Resource scheduling as implemented |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | Create-only from settings |
| **Risk** | **M** |
| **Next action** | Wave 2: index link |

---

### 19. Employees (staff)

| Attribute | Value |
|-----------|--------|
| **Subsection** | Employees |
| **Native vs launcher** | Launcher |
| **Route(s)** | Sidebar: `/staff/create` · module: `/staff`, `/staff/{id}`, … — `register_staff.php` |
| **Controller(s)** | `StaffController` |
| **Service / repository / keys** | Staff module |
| **View file(s)** | Staff module views |
| **Read-side SoT** | DB |
| **Write-side SoT** | Staff POST routes |
| **Runtime consumer(s)** | Appointments, payroll, RBAC |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | No staff index from settings shell |
| **Risk** | **M** |
| **Next action** | Wave 2: `/staff` link |

---

### 20. Employee groups

| Attribute | Value |
|-----------|--------|
| **Subsection** | Employee groups |
| **Native vs launcher** | Launcher |
| **Route(s)** | `/staff/groups`, `/staff/groups/{id}`, permissions — `register_staff.php` |
| **Controller(s)** | `StaffGroupController` |
| **Service / repository / keys** | Staff group + permission attachment services |
| **View file(s)** | Staff module views |
| **Read-side SoT** | DB |
| **Write-side SoT** | POST create/update/attach/detach/permissions |
| **Runtime consumer(s)** | RBAC, service eligibility, public booking staff rules where applicable |
| **Verdict** | **REAL** |
| **Evidence** | Full route surface; linked from shell |
| **Risk** | **L** |
| **Next action** | Optional: “create group” shortcut |

---

### 21. Personnel compensation / timekeeping settings

| Attribute | Value |
|-----------|--------|
| **Subsection** | Personnel compensation / timekeeping |
| **Native vs launcher** | Launcher (module — **not** `settings` KV subsection) |
| **Route(s)** | Sidebar: `/payroll/runs` · `register_payroll.php`: `/payroll/rules*`, `/payroll/runs*` |
| **Controller(s)** | `PayrollRuleController`, `PayrollRunController` |
| **Service / repository / keys** | Payroll services + migrations (e.g. `076`) — **no** `section=payroll` in `SettingsController` |
| **View file(s)** | `modules/payroll/views/*` |
| **Read-side SoT** | Payroll tables |
| **Write-side SoT** | Rule + run mutations |
| **Runtime consumer(s)** | Commission / run calculations |
| **Verdict** | **REAL** (module) |
| **Evidence** | Full payroll routes; time clock / compliance depth still **DEFER** per master roadmap Phase 8 / §5.D |
| **Risk** | **L** |
| **Next action** | Wave 2: label as “Payroll (module)” |

---

### 22. Users / connections / credentials

| Attribute | Value |
|-----------|--------|
| **Subsection** | Users / connections / credentials |
| **Native vs launcher** | Launcher placeholder in shell |
| **Route(s)** | **Tenant:** no `/users` admin registrar in `system/routes/web` (verified by search). **Platform:** `/platform-admin/...` salon admin access (separate plane) |
| **Controller(s)** | N/A for tenant settings-shell CRUD |
| **Service / repository / keys** | `users` table used by auth; no tenant-facing user admin from settings |
| **View file(s)** | `shell.php` — disabled “New User — Backend pending” |
| **Read-side SoT** | N/A for this surface |
| **Write-side SoT** | N/A |
| **Runtime consumer(s)** | Login/session only |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | Shell pending item; no tenant routes |
| **Risk** | **H** |
| **Next action** | Wave 2: honest copy; future lane: tenant user admin |

---

### 23. Services

| Attribute | Value |
|-----------|--------|
| **Subsection** | Services |
| **Native vs launcher** | Launcher |
| **Route(s)** | Sidebar: `/services-resources/services/create` · index: `/services-resources/services` |
| **Controller(s)** | `ServiceController` |
| **Service / repository / keys** | `services` table + `ServiceService` |
| **View file(s)** | Services-resources views |
| **Read-side SoT** | DB |
| **Write-side SoT** | CRUD |
| **Runtime consumer(s)** | Booking, sales, packages |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | Create-only from settings |
| **Risk** | **M** |
| **Next action** | Wave 2: services index link |

---

### 24. Packages

| Attribute | Value |
|-----------|--------|
| **Subsection** | Packages |
| **Native vs launcher** | Launcher |
| **Route(s)** | Sidebar: `/packages/create` · `modules/packages/routes/web.php` — `/packages`, etc. |
| **Controller(s)** | `PackageDefinitionController`, `ClientPackageController`, … |
| **Service / repository / keys** | Packages module tables |
| **View file(s)** | Packages module views |
| **Read-side SoT** | DB |
| **Write-side SoT** | CRUD + client package flows |
| **Runtime consumer(s)** | Appointments consume-package, sales |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | Create-only from settings |
| **Risk** | **M** |
| **Next action** | Wave 2: `/packages` link |

---

### 25. Series

| Attribute | Value |
|-----------|--------|
| **Subsection** | Series |
| **Native vs launcher** | Launcher placeholder + **API** (no settings KV) |
| **Route(s)** | `POST /appointments/series`, `…/materialize`, cancel endpoints — `register_appointments_calendar.php` |
| **Controller(s)** | `AppointmentController` (JSON-oriented series actions) |
| **Service / repository / keys** | `AppointmentSeriesService` + `appointment_series` data |
| **View file(s)** | No dedicated settings or list view linked from shell |
| **Read-side SoT** | DB |
| **Write-side SoT** | POST JSON/API flows |
| **Runtime consumer(s)** | Calendar series rows; materialization |
| **Verdict** | **LAUNCHER-PENDING** |
| **Evidence** | Shell says “Backend pending” but POST API exists — **misleading** |
| **Risk** | **H** |
| **Next action** | Wave 2: replace badge; Wave 4: list/manage UX if in scope |

---

### 26. Memberships

| Attribute | Value |
|-----------|--------|
| **Subsection** | Memberships |
| **Native vs launcher** | **Both:** KV `section=memberships` **and** module launcher `/memberships/create` |
| **Route(s)** | `GET/POST /settings?section=memberships` · `/memberships/*` — `modules/memberships/routes/web.php` |
| **Controller(s)** | `SettingsController` (KV) · `MembershipDefinitionController`, `ClientMembershipController`, … |
| **Service / repository / keys** | `memberships.terms_text`, `memberships.renewal_reminder_days`, `memberships.grace_period_days` + full module schema |
| **View file(s)** | `index.php` (KV) + memberships module views |
| **Read-side SoT** | KV + DB |
| **Write-side SoT** | `patchMembershipSettings` vs module mutations |
| **Runtime consumer(s)** | `MembershipService`, `MembershipLifecycleService`, public commerce gates, etc. |
| **Verdict** | **REAL-BUT-PARTIAL** |
| **Evidence** | Two planes; deep Booker parity **DEFER** per charter |
| **Risk** | **M** |
| **Next action** | Wave 2: distinguish “defaults” vs “catalog” |

---

### 27. Document storage

| Attribute | Value |
|-----------|--------|
| **Subsection** | Document storage |
| **Native vs launcher** | Launcher placeholder; **backend = JSON API** |
| **Route(s)** | `GET/POST /documents/definitions`, file routes — `register_documents.php` |
| **Controller(s)** | `DocumentController` |
| **Service / repository / keys** | `ConsentService`, `DocumentService` |
| **View file(s)** | **None** — `DocumentController` docblock: **“No UI; JSON”** |
| **Read-side SoT** | DB |
| **Write-side SoT** | POST APIs |
| **Runtime consumer(s)** | Booking/check-in consent flows |
| **Verdict** | **UI-WITHOUT-BACKEND** (operator HTML) · **REAL** (API) |
| **Evidence** | Shell “Backend pending” contradicts live routes |
| **Risk** | **H** |
| **Next action** | Wave 2: honest labeling (“API only, no admin UI”) |

---

## Compact verdict summary

| Verdict | Subsections |
|---------|-------------|
| **REAL** | Establishment org-default fields; Appointment settings; Payment settings; Custom payment methods; Tax types; Hardware; Security; Waitlist; Employee groups; Payroll module (compensation/timekeeping as implemented) |
| **REAL-BUT-PARTIAL** | Opening hours; Closure dates; Secondary contact; Cancellation policy; Tax allocation (JSON); Internal notifications; Marketing (narrow); Online booking / public channels; Memberships |
| **READ-ONLY** | — |
| **UI-WITHOUT-BACKEND** | Document storage (operator-facing HTML) |
| **LAUNCHER-PENDING** | Spaces; Material; Employees; Users; Services; Packages; Series |
| **DEFER** | Full Booker marketing; full HTML VAT UX; tenant user CRUD; time clock / payroll compliance depth — per master roadmap / charter |

---

## Supplementary: payment methods & VAT in shell

| Item | Behavior |
|------|----------|
| `/settings/payment-methods` | Uses `shell.php` with `activeSettingsSection = payment_methods`; CRUD not via `SettingsController::store` |
| `/settings/vat-rates` | Same pattern for `vat_rates` |

---

---

## Appendix — Wave 02-01 shell updates (2026-03-25)

Operator-facing adjustments (sidebar + selected workspace copy) without changing backend contracts. Notably: **`GET /settings/vat-distribution-guide`** replaces a direct sidebar link to raw JSON; **Branches** appears when `branches.view`; module launchers include **All …** list links where routes exist. Detail: `SETTINGS-SHELL-HONESTY-WAVE-02-01.md`.

*End of matrix 01.*
