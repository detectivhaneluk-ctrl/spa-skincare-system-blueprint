# SETTINGS-PARITY-ANALYSIS-01

Status: source-of-truth parity analysis; **runtime/docs synced** through **BRANCH-EFFECTIVE-SETTINGS-OPERATIONALIZATION-01** (central in-app notification policy + waitlist mutation gate + receipt-printer canonical resolver — see § SETTINGS-DRIVEN / notifications / hardware).

## Scope and method

This document compares the current repository settings backend/runtime against the effective Booker-style Settings surface currently tracked in project docs. It records only code-proven truth from this repo snapshot.

Evidence anchors used:

- `system/core/app/SettingsService.php`
- `system/modules/settings/controllers/SettingsController.php`
- `system/modules/online-booking/services/PublicBookingService.php` (incl. `resolveClient` marketing default)
- `system/modules/clients/services/ClientRegistrationService.php` (convert → new client marketing default)
- `system/modules/appointments/services/AppointmentService.php`
- `system/modules/appointments/services/WaitlistService.php`
- `system/modules/sales/controllers/PaymentController.php`
- `system/modules/sales/services/PaymentService.php`
- `system/core/middleware/AuthMiddleware.php`
- `system/modules/memberships/Services/MembershipService.php`
- `system/modules/notifications/services/NotificationService.php`
- `system/core/app/ApplicationTimezone.php`
- `system/modules/clients/controllers/ClientController.php`
- `system/modules/settings/controllers/VatRatesController.php`
- `system/modules/sales/services/VatRateService.php`
- `system/modules/sales/repositories/VatRateRepository.php`

## Cross-domain branch-scoping behavior (current truth)

- Storage model: `settings` supports `(key, branch_id)` with global fallback to `branch_id = 0` in `SettingsService::get()`/`all()` (authoritative merge: branch-specific row wins over `branch_id = 0`, then getter defaults — documented on the service class).
- Current branch-aware load/save in `SettingsController`: `establishment`, `marketing`, `hardware`, `memberships`, `online_booking`, `payments`, `cancellation`, `appointments`, `waitlist`, `security`, `notifications`.
- Residual branch parity gap is now **breadth of runtime callers** (who still passes implicit `null`) and model/policy depth, not missing controller branch-write coverage for those domains.
- Runtime branch-aware reads exist when callers pass the correct branch id or `BranchContext` into `SettingsService` getters.

## Domain-by-domain parity analysis

### 1) Establishment

- Keys/settings that exist:
  - `establishment.name`, `phone`, `email`, `address`, `currency`, `timezone`, `language`.
- Storage-only vs operationalized:
  - Operationalized.
- Runtime enforcement/read paths:
  - Timezone runtime: `ApplicationTimezone::applyForHttpRequest()` at `Application::run()` (pre-middleware, usually **global** merge), then `ApplicationTimezone::syncAfterBranchContextResolved()` after `BranchContextMiddleware` so staff requests use **branch-effective** `getEstablishmentSettings(BranchContext::getCurrentBranchId())` (see `ApplicationTimezone` docblock).
  - Language: `establishment.language` → `SettingsService::getEffectiveEstablishmentLanguageTag()` → HTTP `Content-Language` via `ApplicationContentLanguage::applyAfterBranchContextResolved()` after `BranchContextMiddleware` (branch-effective for staff; global merge for guests).
  - Currency is consumed by `SettingsService::getEffectiveCurrencyCode()`.
  - **Invoices:** `invoices.currency` is set on **create** from `getEffectiveCurrencyCode(invoice branch)` (**SALES-CURRENCY-CANONICAL-ENFORCEMENT-01**); immutable on update (client cannot override). Gift-card redemption on an invoice requires **matching** gift card currency vs invoice currency. Payment/refund audits include `invoice_currency` (row or resolver fallback).
  - **Payments:** `payments.currency` on each insert (**SALES-PAYMENT-CURRENCY-PERSISTENCE-01**) matches invoice canonical currency (same empty-invoice fallback via `getEffectiveCurrencyCode`); not from request; refund rows use invoice currency at refund time. **Legacy rows:** migration `064` sets `payments.currency` from `invoices.currency` where the latter is non-empty and differs; `system/scripts/repair_payments_currency_empty_invoice.php` aligns rows whose invoice currency is still empty using `getEffectiveCurrencyCode(invoice.branch_id)`. **Reads:** revenue/refunds report summaries use `by_currency` from `p.currency` and set `total_revenue` / `total_refunded` to **null** when `mixed_currency` (**REPORTS-MULTI-CURRENCY-SUMMARY-SAFETY-01**); payments-by-method includes `currency`. `ClientSalesProfileProvider::getSummary` nulls **`total_billed` / `total_paid` / `total_due`** when multi-currency unsafe; **`billed_by_currency`** (invoice currency) and **`paid_by_currency`** (payment-row currency, net completed) are authoritative (**CLIENT-SALES-PROFILE-MULTI-CURRENCY-SAFETY-01**). `listRecentPayments` exposes `currency` as payment → invoice → resolver (no FX). **Register close:** if completed **cash** payments on the session span more than one `payments.currency`, `RegisterSessionService::closeSession` sets `expected_cash_amount` / `variance_amount` / scalar `cash_sales_total` to **null**, returns `cash_sales_by_currency` + `cash_sales_mixed_currency` (**SALES-MULTI-CURRENCY-AGGREGATION-SAFETY-01**); audit includes the same breakdown (no FX).
- Branch-aware read parity:
  - Yes: `SettingsController` loads establishment with the same branch selector as other domains; invoices use invoice branch; `Content-Language` uses resolved `BranchContext` after middleware. PHP default timezone is re-synced after branch resolution (see above).
- Branch-aware write parity:
  - Yes: `SettingsController` persists establishment keys against the selected settings branch context (shared selector with other domains).
- Source-of-truth gaps:
  - Other callers that still pass `null` where an entity branch exists remain **pending** wiring (not exhaustively audited here).
- Gap type:
  - Settings-data-model + future operationalization depth.

### 2) Cancellation

- Keys/settings that exist:
  - `cancellation.enabled`, `min_notice_hours`, `reason_required`, `allow_privileged_override`.
- Storage-only vs operationalized:
  - Operationalized.
- Runtime enforcement/read paths:
  - `AppointmentService::cancel()` enforces enabled/notice/reason/override policy.
  - Public manage cancel policy in `PublicBookingService::computeManageActionPolicy()` reads cancellation settings.
- Branch-aware read parity:
  - Yes (service reads with appointment/manage branch context).
- Branch-aware write parity:
  - Yes in current `SettingsController` context-driven save.
- Source-of-truth gaps:
  - No major contradiction; parity is stronger than many domains.
- Gap type:
  - Future operationalization (policy expansion only).

### 3) Appointments

- Keys/settings that exist:
  - `appointments.min_lead_minutes`, `max_days_ahead`, `allow_past_booking`.
- Storage-only vs operationalized:
  - Operationalized.
- Runtime enforcement/read paths:
  - `AppointmentService::validateTimes()` enforces lead time, max days ahead, and past-booking allowance.
  - `passesRescheduleWindowPolicy()` reuses same window checks for public manage reschedule slots.
- Branch-aware read parity:
  - Yes (`branch_id` passed into appointment settings reads).
- Branch-aware write parity:
  - Yes in current `SettingsController`.
- Source-of-truth gaps:
  - Recurring/series **policy settings keys** are still absent; **internal** series runtime now includes materialize + cancel depth (`AppointmentSeriesService`, protected `POST /appointments/series/*`, migration `061` duplicate guard) — not settings-driven.
- Gap type:
  - Settings/runtime gap for **series policy keys** + future UX/edit/split/richer recurrence operationalization.

### 4) Online booking

- Keys/settings that exist:
  - `online_booking.enabled`, `public_api_enabled`, `min_lead_minutes`, `max_days_ahead`, `allow_new_clients`.
- Storage-only vs operationalized:
  - Fully operationalized for anonymous public API gating and booking windows.
- Runtime enforcement/read paths:
  - `PublicBookingService::requireBranchPublicBookability()` enforces branch active + `enabled`.
  - `PublicBookingService::requireBranchAnonymousPublicBookingApi()` enforces `public_api_enabled`.
  - `getPublicSlots()` and `createBooking()` enforce lead/max-days window and client policy.
  - **Token manage reschedule** (`getManageRescheduleSlotsByToken`, `rescheduleByManageToken`) enforces the **same** merged `online_booking.min_lead_minutes` / `online_booking.max_days_ahead` window as `createBooking`, in addition to `AppointmentService` internal `validateTimes` (appointment settings) on the actual reschedule mutation — **PUBLIC-API-SECURITY-PARITY-HARDENING-01**.
- Branch-aware read parity:
  - Yes (public runtime reads effective branch settings).
- Branch-aware write parity:
  - Yes in current `SettingsController` via branch context.
- Source-of-truth gaps:
  - Broader public booking hardening/backlog is separate from settings key existence.
- Gap type:
  - Future operationalization (non-settings hardening), not missing key.

### 5) Payments

- Keys/settings that exist:
  - `payments.default_method_code`, `allow_partial_payments`, `allow_overpayments`, `receipt_notes`.
- Storage-only vs operationalized:
  - Operationalized (not invoice-show-only).
- Runtime enforcement/read paths:
  - `PaymentController` uses `payments.default_method_code` (via `getPaymentSettings`) together with **`PaymentMethodService::resolveDefaultForRecordedPayment`** — no hardcoded `cash` fallback; default must be an **active** method for the invoice branch (global ∪ branch rows), excluding `gift_card` from the record-payment list.
  - `PaymentService::create()` enforces **allowed methods for recorded payments** via **`isAllowedForRecordedInvoicePayment`** (active for branch, **not** `gift_card`; gift cards only via redemption flow). Empty allowed list → `DomainException`. Partial/overpayment policy unchanged.
  - **`payments.receipt_notes`** on invoice HTML: `InvoiceController::show` + `getPaymentSettings($branchId)`.
  - **`payments.receipt_notes`** + **`hardware.use_receipt_printer`** (via **`SettingsService::isReceiptPrintingEnabled`**) on payment-adjacent **audit** payloads: `payment_recorded`, `payment_refunded`, `invoice_gift_card_redeemed` (**SALES-RECEIPT-PRINT-SETTINGS-OPERATIONALIZATION-01**); no in-repo printer driver.
- Branch-aware read parity:
  - Yes (invoice branch passed into payment settings reads).
- Branch-aware write parity:
  - Yes in current `SettingsController` via branch context.
- Source-of-truth gaps:
  - Remaining parity is breadth/depth, not zero-runtime usage.
- Gap type:
  - Future operationalization depth.

### 6) VAT-related settings/runtime touchpoints

- Keys/settings that exist:
  - No VAT keys in `SettingsService` flat settings namespace.
- Storage-only vs operationalized:
  - VAT is operationalized through dedicated VAT entities/services, not `settings`.
- Runtime enforcement/read paths:
  - `VatRatesController`, `VatRateService`, `VatRateRepository` manage VAT rates.
  - **`InvoiceService::create` / `update`:** for each line with `item_type = service` and `source_id` set, `tax_rate` is set from `services.vat_rate_id` via `VatRateService::getRatePercentById` before totals validation (catalog truth; manual lines unchanged). Orphan `vat_rate_id` → `DomainException` (no silent wrong tax).
  - **`ServiceService::create` / `update`:** non-null `vat_rate_id` must be **active** and allowed for the service’s resolved `branch_id` (same rule as `VatRateService::listActive`); create (**SERVICE-VAT-RATE-BRANCH-VALIDATION-SEAL-01**) + update post-merge (**SERVICE-VAT-RATE-UPDATE-SEAL-01**). Read-only drift classifier: **`scripts/verify_services_vat_rate_drift_readonly.php`**. Dev/DBA raw inserts bypass service-layer checks (intentionally out of scope).
  - **Service `staff_group_ids` (write):** **`StaffGroupRepository::assertIdsAssignableToService`** runs against the **effective saved branch** — create: after **`enforceBranchOnCreate`**; update: same post-merge row as VAT (**current** overlaid by payload **`branch_id`** when present). **`ServiceController::validate`** mirrors that merge for update. **SERVICE-STAFF-GROUP-BRANCH-MERGE-SEAL-01**. **SERVICE-BRANCH-MOVE-STAFF-GROUP-SEAL-01:** **`branch_id`** change without **`staff_group_ids`** in payload ⇒ **`filterIdsAssignableToServiceBranch`** prunes pivots in the same transaction. Read-only pivot drift: **`scripts/verify_service_staff_group_pivot_drift_readonly.php`** (**SERVICE-STAFF-GROUP-PIVOT-DRIFT-AUDIT-01**) for legacy/SQL drift.
  - `ServiceListProvider::find()` exposes `vat_rate_id` for cross-module resolution.
- Branch-aware read parity:
  - VAT repository supports global + branch VAT rate lookup/listing; invoice line canonicalization uses **`vat_rates.id`** via `getRatePercentById` (no invoice-branch overlay).
- Branch-aware write parity:
  - **`VatRatesController`** creates/lists **global** rows only; **edit/update** refuse branch-scoped ids (aligned with index), so URL guessing cannot mutate overlay rows.
- Source-of-truth gaps:
  - VAT governance is split from Settings surface; **HTTP** branch-level VAT admin (CRUD overlay rows per branch) remains deferred; repository/service list APIs already accept `branchId`.
- Gap type:
  - Settings-data-model gap (outside flat settings) + future operationalization/admin parity.

### 7) Marketing

- Keys/settings that exist:
  - `marketing.default_opt_in`, `marketing.consent_label`.
- Storage-only vs operationalized:
  - Partially operationalized (labels/defaults), no campaign engine.
- Runtime enforcement/read paths:
  - `marketing.consent_label` / `default_opt_in` exposed on admin client forms via `ClientController` + `getMarketingSettings` (branch-effective).
  - **`marketing.default_opt_in`** is written to `clients.marketing_opt_in` when **`PublicBookingService::resolveClient`** creates a client (anonymous public book) and when **`ClientRegistrationService::convert`** creates a new client from a registration request — both use `getMarketingSettings($branchId)` (branch → global → default).
  - No outbound campaign/automation orchestration.
- Branch-aware read parity:
  - Yes: `SettingsController` uses branch context for marketing; **client** create/edit forms use `ClientController` → `getMarketingSettings` with client `branch_id` when set, else `BranchContext`.
- Branch-aware write parity:
  - Yes in `SettingsController` via the same branch context as other domains.
- Source-of-truth gaps:
  - Operational engine remains absent.
- Gap type:
  - Future operationalization gap.

### 8) Security

- Keys/settings that exist:
  - `security.password_expiration`, `security.inactivity_timeout_minutes`.
- Storage-only vs operationalized:
  - Operationalized for session inactivity and password-expiry gate.
- Runtime enforcement/read paths:
  - `AuthMiddleware` enforces inactivity timeout and password expiration policy.
- Branch-aware read parity:
  - Yes (`BranchContext` branch id is used for security settings in middleware).
- Branch-aware write parity:
  - Yes in current `SettingsController` via branch context.
- Source-of-truth gaps:
  - Security runtime breadth beyond these controls remains limited.
- Gap type:
  - Future operationalization gap.

### 9) Notifications

- Keys/settings that exist:
  - `notifications.appointments_enabled`, `sales_enabled`, `waitlist_enabled`, `memberships_enabled`.
- Storage-only vs operationalized:
  - Operationalized for in-app notification emission gates (authoritative in `NotificationService::create`).
- Runtime enforcement/read paths:
  - **`SettingsService::shouldEmitInAppNotificationForType()`** maps type prefixes → branch-effective flags (`appointment_` → appointments, `waitlist_` → waitlist, `membership_` → memberships, `payment_` → sales); unknown prefixes default **true**.
  - **`NotificationService::create()`** calls the resolver first; when disabled it **returns 0**, does **not** insert a row, and audits **`notification_suppressed_by_settings`** (metadata: `notification_type`, `title`).
  - Domain services (`AppointmentService`, `PaymentService`, `WaitlistService`, `MembershipService::dispatchRenewalReminders`) call `create()` without duplicating flag checks; renewal reminder stats use **`skipped_notifications_disabled`** when `create` returns 0.
- Branch-aware read parity:
  - Yes where services pass branch context.
- Branch-aware write parity:
  - Yes in current `SettingsController`.
- Source-of-truth gaps:
  - No external channels (email/SMS/push) wired to settings behavior.
- Gap type:
  - Future operationalization gap.

### 10) Hardware

- Keys/settings that exist:
  - `hardware.use_cash_register`, `hardware.use_receipt_printer`.
- Storage-only vs operationalized:
  - Cash register: operationalized. Receipt printer: **canonical runtime read** + audit snapshot; **no** device dispatch in-repo.
- Runtime enforcement/read paths:
  - `PaymentService` uses `use_cash_register` to require/open register session for cash payment/refund behavior.
  - **`SettingsService::isReceiptPrintingEnabled($branchId)`** is the single branch-effective boolean for `hardware.use_receipt_printer` (future physical/queue dispatch must no-op when false). **`PaymentService::receiptPrintSettingsForAudit`** and **`InvoiceService`** (gift-card redeem audit) use this for `hardware_use_receipt_printer` on `payment_recorded`, `payment_refunded`, and `invoice_gift_card_redeemed`.
- Branch-aware read parity:
  - Service supports branch-aware reads; runtime passes invoice branch for cash register checks.
- Branch-aware write parity:
  - Yes in `SettingsController` via shared branch context (same as other domains).
- Source-of-truth gaps:
  - Printer/device backend integration is limited.
- Gap type:
  - Future operationalization gap.

### 11) Memberships

- Keys/settings that exist:
  - `memberships.terms_text`, `memberships.renewal_reminder_days`, `memberships.grace_period_days`.
  - `notifications.memberships_enabled` (in-app notification gate for types prefixed `membership_`).
- Storage-only vs operationalized:
  - **MEMBERSHIPS-SETTINGS-TRUTH-OPERATIONALIZATION-01:** `memberships.terms_text` is branch-effective on **initial sale invoices** (`MembershipSaleService::createSaleAndInvoice`), **renewal invoices** (`MembershipBillingService` renewal `InvoiceService::create` path), and **`client_memberships.notes`** on assign/activation (`MembershipService::assignToClientAuthoritative`), via `SettingsService::membershipTermsDocumentBlock`. UI still displays terms on assign; engine now persists the same text on authoritative records.
  - `grace_period_days` / `renewal_reminder_days` / `notifications.memberships_enabled` unchanged in meaning (see paths below). **Subscription billing foundation**: definition-level billing fields; `membership_billing_cycles`; CLI `memberships_process_billing.php` / `memberships_cron.php`. **No** payment gateway; **no** auto-charge.
- Runtime enforcement/read paths:
  - `MembershipService::markExpired()` defers `active` → `expired` until `today > ends_at + grace_period_days` (branch from `client_memberships.branch_id`); CLI `system/scripts/memberships_mark_expired.php`.
  - `MembershipService::isAccessible()` uses `ends_at`, `starts_at`, `grace_period_days`, and row branch (or passed branch) for the post-sale access window (grace ≠ benefit redemption).
  - `MembershipService::resolveClientMembershipLifecycleState()` / `isUsableForBenefits()` centralize usable-vs-not for benefits.
  - `MembershipService::consumeBenefitForAppointment()` records `membership_benefit_usages` when internal appointment create supplies `client_membership_id` (not public booking).
  - `MembershipService::dispatchRenewalReminders()` reads `renewal_reminder_days` and creates in-app notifications when `NotificationService::create` + `shouldEmitInAppNotificationForType` allow (`notifications.memberships_enabled` for `membership_*` types).
  - **`MembershipBillingService`**: `processDueRenewalInvoices`, `markOverdueCycles`, `applyPaidRenewalTerms`, `runScheduledBillingPass`; paid renewal extends `client_memberships.ends_at` to `billing_period_end` once per cycle (`renewal_applied_at`).
- Branch-aware read parity:
  - Runtime reads branch-effective membership settings where branch id exists.
- Branch-aware write parity:
  - Yes in `SettingsController` via shared branch context.
- Source-of-truth gaps:
  - **Dunning depth** (collections, notifications) beyond cycle/row state; **staff new-invoice** membership checkout wired (**`InvoiceController`** + **`MembershipSaleService::createSaleAndInvoice`**, **MEMBERSHIP-STAFF-CHECKOUT-INTEGRATION-01**); external card/ACH capture remains absent.
- Gap type:
  - Product/runtime depth (payments automation) + optional reporting on `membership_billing_cycles`.

## SETTINGS-DRIVEN-OPERATIONALIZATION-01 — stored vs runtime (strict audit)

**Stored but still without a real backend hook (snapshot):**

- No additional **establishment.\*** keys in this bucket; `establishment.language` is wired in **SETTINGS-STORED-VS-RUNTIME-OPERATIONALIZATION-TRUTH-CUT-01** as HTTP `Content-Language` only (no `setlocale`).

**Receipt printer (no driver):** `hardware.use_receipt_printer` is **not** storage-only — branch-effective truth via **`SettingsService::isReceiptPrintingEnabled`**; audits snapshot the flag; **no** print queue/driver in-repo.

**Stored and already operational elsewhere:** waitlist keys (`WaitlistService` — including **`waitlist.enabled`** gate on `updateStatus` for actual transitions to a status **other than** `cancelled`, plus `linkToAppointment` and `convertToAppointment`), online booking / appointment window keys (`PublicBookingService`, `AppointmentService`), payment/receipt notes on invoice show, membership terms/grace/reminders, in-app notifications via **`NotificationService`** + **`shouldEmitInAppNotificationForType`**, security, etc.

**Wired in SETTINGS-DRIVEN-OPERATIONALIZATION-01:** `marketing.default_opt_in` → `clients.marketing_opt_in` on **public** `resolveClient` and **staff** registration **convert** (new client path only), using **`SettingsService::getMarketingSettings`** branch merge.

**Wired in SETTINGS-STORED-VS-RUNTIME-OPERATIONALIZATION-TRUTH-CUT-01:** `establishment.language` → **`SettingsService::getEffectiveEstablishmentLanguageTag`** + **`ApplicationContentLanguage`** after **`BranchContextMiddleware`** (RFC 7231 `Content-Language`; invalid values omitted).

## BRANCH-EFFECTIVE-SETTINGS-OPERATIONALIZATION-01 — runtime truth (code-synced)

- **`SettingsService::shouldEmitInAppNotificationForType`**, **`isReceiptPrintingEnabled`** — narrow resolver methods on the existing merge path (no second settings system).
- **`NotificationService::create`** — authoritative in-app emission gate + **`notification_suppressed_by_settings`** audit when suppressed; returns **0** when suppressed.
- **`WaitlistService`** — when **`waitlist.enabled`** is false: **`updateStatus`** rejects any **actual** transition whose target is not **`cancelled`** (same-status no-op exits before the gate); **`linkToAppointment`** and **`convertToAppointment`** throw **`DomainException`** (cleanup via cancel still allowed).
- **`PaymentService` / `InvoiceService`** — receipt-printer snapshot on audits uses **`isReceiptPrintingEnabled`**.
- **No** migration; **no** new settings keys; public booking / `public_api_enabled` unchanged (already enforced).

## Stored-only vs operationalized settings (current snapshot)

- Strong operationalization: cancellation, appointments, online booking, payments, security, notifications.
- Partial operationalization: hardware (receipt printer = canonical boolean + audits only — **no** driver), memberships subscription billing; **marketing** = labels + default opt-in **now** applied on key automated client creates (above) — still **no** campaign engine; **establishment.language** = HTTP language tag only (not full i18n strings).
- Non-flat operational area outside SettingsService keys: VAT (dedicated VAT rates module).

## Places where flat key-value is no longer enough

- Memberships recurring **billing** lifecycle (cycles, dunning, ledger links) — **distinct from** shipped internal benefit redemption on appointments.
- Staff-group **admin paths:** JSON **`GET`/`POST /staff/groups/{id}/permissions`** + **`StaffGroupPermissionService`** are shipped. **Service ↔ group** pivot: authenticated **`ServiceController`** create/update + **`ServiceService`** validation → **`ServiceRepository`** / **`ServiceStaffGroupRepository::replaceLinksForService`** (**SERVICE-STAFF-GROUPS-HTTP-OPERATIONALIZATION-01**).
- Deeper document storage/upload pipelines (metadata lifecycle, workflow, retention). **Shipped:** `system/storage/` is not web-readable (Apache deny) and **internal authenticated** file delivery exists (`GET /documents/files/{id}/download`, same visibility gate as metadata read). **Not shipped:** public/anonymous document binary access.
- External notification channel configuration and per-channel policy.
- Rich marketing automation rules/triggers/audience segmentation.
- Deeper multi-branch policy matrices beyond simple scalar key/value.

## Explicit classification of future parity gaps (using current repo truth)

- Memberships operational engine:
  - Classification: **benefit redemption on internal appointments is shipped**; **renewal subscription billing foundation shipped** (`MembershipBillingService`, `membership_billing_cycles`, canonical renewal **`InvoiceService::create`**); **initial purchase** via staff **`POST /sales/invoices`** (membership plan selected) or **`POST /memberships/sales`**, both **`MembershipSaleService::createSaleAndInvoice`**.
  - Current truth: definitions + client memberships; lifecycle helpers; `membership_benefit_usages` + optional `appointments.client_membership_id`; audited `membership_usage_consumed`; optional `benefits_json.included_visits` cap. Reminder/grace settings operational. **Automated renewal invoices** + term extension on full pay; CLI `memberships_process_billing.php`.
- Staff groups:
  - Classification: **policy depth shipped**; **group↔permission** JSON admin **shipped**; **service↔group** enforcement + staff HTTP create/update for **`staff_group_ids`** **shipped**.
  - Current truth: backend foundation + authoritative `StaffGroupService::isStaffInScopeForBranch`; availability, appointment assignment, staff list filtered by group scope when branch has active groups. **`PermissionService`** merges role + `staff_group_permissions`. **`service_staff_groups`** maintained via standard service forms + **`staff_group_ids_sync`** on update.
- Document storage/upload backend:
  - Classification: partially closed; remaining depth is backend/runtime gap.
  - Current truth: internal document storage foundation exists (`documents` + `document_links`, internal upload/register/list/show/relink/detach/archive/delete metadata routes with owner-type allowlist); **non–web-readable** `system/storage/`; **authenticated internal** binary delivery `GET /documents/files/{id}/download` (documents.view, path-validated, audited). Broader workflow/retention/product integrations and **any public/anonymous** file access remain future / out of scope.
- Recurring/series appointments:
  - Classification: **internal operational baseline shipped**; remaining depth is UX + edit/split + richer recurrence (+ optional settings keys).
  - Current truth: weekly/biweekly `appointment_series`; `AppointmentSeriesService` (`resolveSeriesLifecycleState`, `materializeFutureOccurrences`, whole/forward/single-occurrence cancel); `AppointmentService::createFromSeriesOccurrence`; protected internal `POST /appointments/series`, `/appointments/series/materialize`, `/appointments/series/cancel`, `/appointments/series/occurrence/cancel`; unique `(series_id, start_at)` (`061`). **No** public series routes.
- Branch-level operational settings depth:
  - Classification: **improved for wired consumers**; VAT admin remains global-only; richer policy matrices beyond scalar keys remain future.
  - Current truth: **Shipped in BRANCH-LEVEL-SETTINGS-DEPTH-01 + CORE-RUNTIME-SCHEMA-TRUTH-SEAL-01 + SETTINGS-BRANCH-EFFECTIVE-CALLSITE-SEAL-01:** authoritative merge on `SettingsService`; `ApplicationTimezone` pre-middleware + post-`BranchContextMiddleware` sync; `ClientController` marketing reads use client branch or context. **Call-site inventory:** `system/docs/SETTINGS-READ-SCOPE.md` (entity-effective vs operator vs public vs CLI).
- Separate anonymous public API enablement:
  - Classification: already implemented (not a gap).
  - Current truth: `online_booking.public_api_enabled` exists and is enforced.
- Fuller security runtime expansion beyond password expiration:
  - Classification: future operationalization gap.
  - Current truth: password expiry + inactivity timeout enforced; broader controls remain future work.
- Waitlist auto-offer / expiry operationalization:
  - Classification: operational for slot-freeing events wired from `AppointmentService` (cancel, delete, reschedule, scheduling `update()`); `expireDueOffers` / `runWaitlistExpirySweep` (waitlist page / slot-freed / CLI `system/scripts/waitlist_expire_offers.php`) share one engine: revert expired offers and **chain** to the next waiting candidate when settings allow, excluding the just-expired row and blocking double-offer for the same slot context; optional `AvailabilityService` free-window check when `preferred_time_from` is set. **Serialization:** non-blocking MySQL `GET_LOCK('spa_waitlist_expiry_sweep', 0)` for the sweep body (same pattern as `PublicBookingAbuseGuardRepository`); global lock regardless of branch filter so full and partial sweeps cannot overlap. **Slot-freed auto-offer path:** per-context MySQL `GET_LOCK` inside **`WaitlistService::attemptAutoOfferForSlotContext`** (**WAITLIST-SLOT-FREED-OFFER-CONCURRENCY-HARDENING-01**). CLI also uses `flock` on `storage/locks/waitlist_expiry_sweep.lock` as a host-level outer guard. No daemon.
  - Current truth: `WaitlistService::onAppointmentSlotFreed` + `expireDueOffers`; settings via `getWaitlistSettings`; in-app rows use `NotificationService::create` (notification flags central). **`waitlist.enabled`** also gates staff mutations (`updateStatus` actual transitions unless target is `cancelled`, `linkToAppointment`, `convertToAppointment`).
- Payments defaults operationalization:
  - Classification: implemented baseline; remaining depth is future operationalization.
  - Current truth: controller + service runtime usage exists.
- Marketing operationalization:
  - Classification: **default opt-in is operational** for public book + registration convert client creates; campaign/automation remains future.
  - Current truth: `marketing.default_opt_in` + `consent_label` stored; branch-effective reads; **no** email/SMS automation engine.
- External notification channels:
  - Classification: backend/runtime gap.
  - Current truth: in-app notifications only.
- Richer config model where flat key-value is no longer enough:
  - Classification: settings-data-model gap.
  - Current truth: several parity targets require structured entities/workflows beyond scalar keys.

## PUBLIC-API-SECURITY-PARITY-HARDENING-01 — audit snapshot

**Scope:** Exposed routes in `system/routes/web.php` under `/api/public/booking/*` only; staff-auth flows (registrations, documents, payments) audited for comparison — **no** defect found requiring change in this pass outside public booking service.

| Area | Verdict |
|------|---------|
| Anonymous slots / book / consent-check | **Policy gates correct**; **abuse buckets deepened** in **PUBLIC-ABUSE-GUARD-IDENTITY-DEPTH-01** (split read buckets, slots scope, consent branch+IP, empty-token per-IP, book IP after validation). |
| Token manage lookup / cancel / reschedule / slots | **Already correct:** valid token only, rate limits, cancellation policy vs `cancellation.*`, generic errors, existing manage audits. |
| **Token reschedule window vs POST /book** | **Was inconsistent:** reschedule path relied on **appointment** settings via `validateTimes` only; could allow times **outside** anonymous **`online_booking`** lead/max-day window. **Fixed** in `PublicBookingService` (same window as `createBooking`). |
| Client registration CRUD | **Staff-auth only** — no public gap. |
| Document file download | **Session + permission** — no public gap. |
| CAPTCHA / magic-link / returning-client identity | **Still pending** product/hardening — not this pass. |

## PUBLIC-ABUSE-GUARD-IDENTITY-DEPTH-01 — audit snapshot

**Flows audited (routes only under `/api/public/booking/*`; registrations are staff-auth, no public POST):**

| Flow | Before (weakness) | After / verdict |
|------|-------------------|-----------------|
| **GET slots** | Single shared `read` IP bucket with consent-check; consume **before** param validation; **IP-only** identity. | **`read_slots_ip`** (per-IP) + **`read_slots_scope`** keyed by branch+service+date+staff+IP; validate **date/ids before consume**; JSON contract unchanged. |
| **GET consent-check** | Same shared `read` bucket as slots (cross-endpoint starvation). | **`read_consent_ip`** + **`read_consent_branch`** (branch+IP); validate `branch_id` before consume. |
| **POST book** | IP `book` consumed **before** invalid-payload rejection (wasted quota / odd UX). | **`book` IP consume after** required-field + `client_id` checks; **book_contact** / **book_slot** / **book_fingerprint** unchanged (already identity-aware). |
| **Manage token read/write** | Empty token used a **single global** throttle key (`hash('missing')`) for all clients → one actor could crowd others. | Empty token → **per-IP** key inside same buckets; non-empty token → unchanged per-token hash. |
| **Manage IP + mutation fingerprint** | Already composite | **Sufficient** — no change. |

**Intentionally not implemented (requires product/UI/external):** CAPTCHA, magic-link, email proof, third-party anti-abuse, authenticated returning-client path.

## REMAINING-BACKEND-PARITY-SWEEP-01 — cross-module audit (backend-only)

**Method:** Code review of branch guards, public flows (already hardened in prior tasks), and representative controllers/services. **Excluded from code changes per task rules:** documents, memberships, series, staff-groups, payments, settings consumers, public-booking — **no new defect found** requiring edits in those areas during this pass.

| Area | Classification |
|------|----------------|
| **Appointments / availability / waitlist** | **Already correct** — locked pipelines, `BranchContext`, settings-backed rules, audits as documented. |
| **Sales / invoices / checkout** | **Already correct** at sampled paths; broader subscription/membership pricing = **out of scope** (product). |
| **Memberships / series / staff-groups** | **Shipped foundations** per roadmap; deeper billing/UX = **later / product**. |
| **Settings** | **Authoritative merge + wired consumers** per prior tasks; unwired keys = **documented**, not changed here. |
| **Public / token / self-service** | **Recently hardened** (window parity, abuse buckets); CAPTCHA/magic-link = **product / external**. |
| **Client registration (staff)** | **Auth-only**; convert/create paths aligned with marketing settings in prior task. |
| **Documents** | **Internal auth + download** baseline; guest workflows = **Phase 3 / product**. |
| **Branch scoping** | **One narrow gap found** (below); other entities use `assertBranchMatch` / `ensureBranchAccess` in sampled CRUD. |
| **Audit trail** | **No inconsistency** requiring change in touched scope. |
| **Schema / migrations** | **No drift** addressed in this pass (verify via migrations when changing schema — N/A). |

**Fixed in this task (narrow):** `ClientController::destroy` now **`find` → 404 → `ensureBranchAccess` → 403** before `ClientService::delete`, matching `show`/`edit`/`update` and avoiding reliance on uncaught `DomainException` for branch mismatch.

**Fixed in CLIENT-CONTROLLER-MARKETING-BRANCH-RESOLVER-01 (narrow):** `ClientController::marketingSettingsReadBranchId` implemented — `getMarketingSettings` on client create/edit uses client `branch_id` when `> 0`, else `BranchContext::getCurrentBranchId()` (same `SettingsService` merge as elsewhere). **No** new routes; **no** bootstrap/DI change.

**Still pending (intentional):** membership recurring billing **product depth** (dunning, external capture), series admin/recurrence depth, optional permission-matrix HTML, VAT branch admin, dashboard KPIs, **outbound transport maturity** (queue shipped; provider deliverability is **§5.C P1**), marketing depth beyond baseline campaigns (**§5.D**), optional DB unique constraints for concurrency — see **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** / **§5.D**. **Public commerce:** foundation shipped; **client-trusted finalize path = §5.C P0** (not listed here before 2026-03-21 rebase).

## Final parity position for this task

- Memberships: internal appointment benefit consumption (**operational redemption**) is **audited** and **idempotent per appointment**; public booking does **not** accept membership redemption.
- Document files: internal **attachment** download is auth-gated and audited; storage paths are not exposed in responses; **no** public document file route.
- `online_booking.public_api_enabled` exists and is runtime-enforced.
- `SettingsController` already has branch-aware context load/save for:
  - `establishment`, `marketing`, `hardware`, `memberships`, `online_booking`, `payments`, `cancellation`, `appointments`, `waitlist`, `security`, `notifications`.
- **BRANCH-LEVEL-SETTINGS-DEPTH-01:** No new public routes; runtime wiring above plus centralized merge documentation only.
- **SETTINGS-DRIVEN-OPERATIONALIZATION-01:** `marketing.default_opt_in` applied in `PublicBookingService::resolveClient` and `ClientRegistrationService::convert` (new client); **no** new routes; **no** new audit events.
- **PUBLIC-API-SECURITY-PARITY-HARDENING-01:** Token reschedule aligned with `online_booking` schedule window; **no** new routes; **no** new audit events (existing `public_booking_manage_reschedule_denied` with deny_reason).
- **PUBLIC-ABUSE-GUARD-IDENTITY-DEPTH-01:** `PublicBookingController` only — deeper composite throttle keys + ordering; **no** new routes; existing `public_booking_rate_limited` audit with new `bucket` names only.
- **REMAINING-BACKEND-PARITY-SWEEP-01:** `ClientController::destroy` branch + 404 parity with other client routes; **no** new routes; **no** audit change.
- **CLIENT-CONTROLLER-MARKETING-BRANCH-RESOLVER-01:** `ClientController` marketing form reads call `marketingSettingsReadBranchId` → `getMarketingSettings` (resolver was previously referenced but missing, fatal on create/edit); **no** new routes; **no** audit change.
- **WAITLIST-AUTO-OFFER-EXPIRY-OPERATIONALIZATION-01:** `AppointmentService::reschedule` and scheduling `update()` now call the same `invokeWaitlistAutoOfferAfterSlotFreed` path as cancel/delete (freed slot = pre-change row); **no** new routes; **no** migration.
- **WAITLIST-AUTO-OFFER-NOTIFICATION-WIRING-01:** `WaitlistService` creates in-app notifications for types `waitlist_offer_created` / `waitlist_offer_expired` (no email/SMS); branch-effective waitlist channel enforced in **`NotificationService::create`**; duplicate guard via `existsByTypeEntityAndTitle`; **no** new routes; **no** migration.
- **WAITLIST-EXPIRED-OFFER-REOFFER-CHAIN-01:** After each expiry in `expireDueOffers`, `tryChainedAutoOfferAfterExpiredRow` may auto-offer the next waiting entry (same date/service/staff, `id !=` expired); `existsOpenOfferForSlot` prevents concurrent offers; **no** migration.
- **WAITLIST-EXPIRY-SWEEP-CRON-READINESS-01:** `runWaitlistExpirySweep` returns structured stats; `waitlist_expire_offers.php` calls it with `flock` overlap protection, key=value stdout, exit `0` finished / `11` lock held / `1` fatal; **no** migration.
- **WAITLIST-EXPIRY-SWEEP-SERVICE-LOCK-01:** `executeExpirySweep` acquires/releases MySQL `GET_LOCK` around the sweep so CLI, HTTP waitlist page, and `onAppointmentSlotFreed` cannot run the engine concurrently (per DB); `lock_held` in sweep stats when skipped; `expireDueOffers` returns `0` in that case; **no** migration.
- `PAYMENTS-DEFAULTS-OPERATIONALIZATION-01` is runtime-proven in `PaymentController` and `PaymentService`.
- `MEMBERSHIPS-RENEWAL-REMINDER-OPERATIONALIZATION-01` is code-proven (membership reminder dispatch runtime exists).
- **MEMBERSHIP-REMINDER-NOTIFICATION-SETTINGS-WIRING-01:** `notifications.memberships_enabled` enforced via **`NotificationService::create`** + **`shouldEmitInAppNotificationForType`**; `dispatchRenewalReminders` increments **`skipped_notifications_disabled`** when `create` returns 0; branch from client membership row; duplicate guard unchanged; **no** migration (defaults via `get(..., '1')`).
- **MEMBERSHIPS-SETTINGS-USABILITY-ENGINE-01:** `memberships.grace_period_days` drives `markExpired()` transition timing and `isAccessible()`; cron `memberships_mark_expired.php`; benefit/appointment rules unchanged; **no** migration.
- **SALES-INVOICE-SETTINGS-ENFORCEMENT-01:** `InvoiceService` applies `vat_rates.rate_percent` to **service** invoice lines from `services.vat_rate_id` on create/update; `ServiceListProvider::find` includes `vat_rate_id`; **no** migration; flat `SettingsService` VAT keys still N/A (catalog model).
- **SALES-RECEIPT-PRINT-SETTINGS-OPERATIONALIZATION-01:** Branch-effective `payments.receipt_notes` and `hardware.use_receipt_printer` snapshotted on `payment_recorded`, `payment_refunded`, `invoice_gift_card_redeemed` audits via **`SettingsService::isReceiptPrintingEnabled`** (+ payment settings for notes); invoice show receipt footer unchanged; **no** printer integration; **no** migration.
- **BRANCH-EFFECTIVE-SETTINGS-OPERATIONALIZATION-01:** Central in-app notification policy in **`NotificationService`** + **`SettingsService::shouldEmitInAppNotificationForType`**; suppress audit **`notification_suppressed_by_settings`**; waitlist staff mutations gated when **`waitlist.enabled`** false (cancel-only); **`isReceiptPrintingEnabled`** for audit snapshots; **no** migration; **no** new routes.
- **MEMBERSHIPS-SUBSCRIPTION-BILLING-ENGINE-FOUNDATION-01:** Migration **`067`**; `MembershipBillingService` + `membership_billing_cycles`; renewal **`InvoiceService::create`**; CLI **`system/scripts/memberships_process_billing.php`**; **no** payment gateway.
- **SALES-PAYMENT-METHODS-BRANCH-ENFORCEMENT-01:** Recorded invoice payments use branch-effective `payment_methods` (excluding `gift_card`) + `payments.default_method_code` resolver; `gift_card` rejected on `PaymentService::create`; empty active method set blocked; **no** migration.
- **SALES-CURRENCY-CANONICAL-ENFORCEMENT-01:** `invoices.currency` column + create-time `getEffectiveCurrencyCode`; gift-card vs invoice currency match on redemption; `invoice_currency` on payment/refund/gift-card-redeem audits; migration `062`.
- **SALES-PAYMENT-CURRENCY-PERSISTENCE-01:** `payments.currency` persisted on payment/refund/gift-card payment inserts from invoice truth; migration `063`.
- Remaining parity gaps are mostly breadth/depth and model-shape evolution, not absence of the above proven runtime behaviors.
