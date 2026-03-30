# Phase A — Establishment / General Settings + Custom Payment Methods

Backend-only implementation. No UI redesign.

---

## What was changed

### STEP A1 — Establishment / General Settings foundation

- **SettingsService** (`system/core/app/SettingsService.php`): Added `ESTABLISHMENT_KEYS` (establishment.name, establishment.phone, establishment.email, establishment.address, establishment.currency, establishment.timezone, establishment.language), `getEstablishmentSettings(?int $branchId)`, `setEstablishmentSettings(array $data, ?int $branchId)`. Validation: max lengths, email format for establishment.email. Fallback in get: establishment.name ← company_name, establishment.currency ← currency_code, establishment.timezone ← timezone when establishment key is missing.
- **SettingsController** (`system/modules/settings/controllers/SettingsController.php`): index() passes `$establishment` from `getEstablishmentSettings()`. store() reads `settings[establishment.*]`, calls `setEstablishmentSettings()` (with InvalidArgumentException caught and flash error); other keys still saved via existing set() loop (establishment keys skipped in loop).
- **Settings view** (`system/modules/settings/views/index.php`): Added "Establishment" section with one field per key; "Other" section lists non-establishment keys only. Same form POST style.
- **Seed** (`system/data/seeders/002_seed_baseline_settings.php`): Added establishment.* keys (name, phone, email, address, currency, timezone, language) with group `establishment`, branch_id 0. Legacy keys (company_name, currency_code, timezone) kept for backward compatibility.
- **Invoice output operationalization (backend-only):** `InvoiceController::show()` now resolves `getEstablishmentSettings($invoiceBranchId)` and passes `name/phone/email/address` into `modules/sales/views/invoices/show.php`, where each field renders only when non-empty (branch override with global fallback via `SettingsService`).

### STEP A2 — Custom Payment Methods foundation

- **Migration** (`system/data/migrations/046_create_payment_methods_table.sql`): New table `payment_methods` (id, branch_id NULL, code, name, is_active, sort_order, created_at, updated_at). UNIQUE(branch_id, code). branch_id NULL = global.
- **Full schema** (`system/data/full_project_schema.sql`): Added `payment_methods` table definition.
- **PaymentMethodRepository** (`system/modules/sales/repositories/PaymentMethodRepository.php`): listActive(?branchId, ?excludeCode), isActiveCode(code, ?branchId). Branch-aware: global (branch_id IS NULL) or branch-scoped.
- **PaymentMethodService** (`system/modules/sales/services/PaymentMethodService.php`): listForPaymentForm(?branchId) — active methods excluding gift_card; isValidMethod(code, ?branchId).
- **PaymentService** (`system/modules/sales/services/PaymentService.php`): Injects PaymentMethodService. create() validates payment_method via isValidMethod(invoice branch). Removed VALID_METHODS constant and validateMethod().
- **PaymentController** (`system/modules/sales/controllers/PaymentController.php`): Injects PaymentMethodService. create() and store() pass `$paymentMethods` from listForPaymentForm(invoice branch). parseInput($invoiceId) validates submitted method against listForPaymentForm (defaults to first method if invalid).
- **Payment form view** (`system/modules/sales/views/payments/create.php`): Select options built from `$paymentMethods`; fallback single "Cash" option if empty.
- **Seed** (`system/data/seeders/003_seed_payment_methods.php`): Inserts global (branch_id NULL) methods: cash, card, bank_transfer, other, gift_card (all active). Skips if already present.
- **Seed runner** (`system/scripts/seed.php`): require 003_seed_payment_methods.php.
- **Bootstrap** (`system/modules/bootstrap.php`): Registered PaymentMethodRepository, PaymentMethodService; PaymentService and PaymentController updated constructors.

---

## Files changed

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | Establishment keys, getEstablishmentSettings, setEstablishmentSettings, validation. |
| `system/modules/settings/controllers/SettingsController.php` | index: pass establishment; store: handle establishment.*, validate, skip in loop. |
| `system/modules/settings/views/index.php` | Establishment section; Other section excludes establishment keys. |
| `system/data/seeders/002_seed_baseline_settings.php` | Added establishment.* keys. |
| `system/data/migrations/046_create_payment_methods_table.sql` | **New.** |
| `system/data/full_project_schema.sql` | payment_methods table. |
| `system/modules/sales/repositories/PaymentMethodRepository.php` | **New.** |
| `system/modules/sales/services/PaymentMethodService.php` | **New.** |
| `system/modules/sales/services/PaymentService.php` | PaymentMethodService, validate from DB. |
| `system/modules/sales/controllers/PaymentController.php` | PaymentMethodService, listForPaymentForm, parseInput(invoiceId). |
| `system/modules/sales/views/payments/create.php` | Options from $paymentMethods. |
| `system/data/seeders/003_seed_payment_methods.php` | **New.** |
| `system/scripts/seed.php` | require 003. |
| `system/modules/bootstrap.php` | PaymentMethodRepository, PaymentMethodService; PaymentService, PaymentController constructor args. |

---

## Backward compatibility

- **Settings:** Legacy keys `company_name`, `currency_code`, `timezone` remain in seed and are used by getEstablishmentSettings() as fallback when establishment.* is missing. GiftCardService continues to use `settings->get('currency_code', 'USD', ...)`.
- **Payments:** Existing payments rows keep payment_method as string (cash, card, etc.). New payments use the same codes from DB. Gift card redemption still sets payment_method = 'gift_card' via **`InvoiceService::redeemGiftCardPayment`** (not `PaymentService::create`). **`PaymentService::create`** rejects `gift_card` and requires an active non–gift-card method for the invoice branch (**SALES-PAYMENT-METHODS-BRANCH-ENFORCEMENT-01**). Reports and views that display payment_method string unchanged.

---

## Postponed (later phases)

- Payment **settings** (default method, receipt options): not in Phase A.
- Admin CRUD UI for payment methods: not implemented; list comes from seed. Can be added in a later phase.
- Branch-scoped payment methods: table and repo support branch_id; seed only uses global (NULL). Admin can add branch-specific methods later.
- Settings UI grouped by tabs/sections beyond Establishment + Other: not in scope.
- Audit logging for settings changes: project uses audit for entities; settings store() not audited in this pass.

---

## Manual QA checks

1. **Settings:** Run migrations and seed. Open /settings; confirm Establishment section (name, phone, email, address, currency, timezone, language). Edit and save; confirm validation (e.g. invalid email). Confirm Other section still shows company_name, currency_code, timezone if present.
2. **Payments:** Run migration 046 and seed (so payment_methods has rows). Record payment on an invoice; confirm dropdown lists Cash, Card, Bank Transfer, Other (no Gift Card). Submit; confirm payment saved. Redeem gift card on invoice; confirm payment_method gift_card still works.
3. **New install:** Run migrate then seed; create invoice, record payment; confirm no errors and methods from DB.
