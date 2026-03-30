# Phase C — Payment Settings, VAT Types, VAT Distribution Report

Backend-first implementation. No UI redesign; minimal settings UI and service VAT dropdown added.

---

## What was changed

### STEP C1 — Payment Settings foundation

- **SettingsService** (`system/core/app/SettingsService.php`): Added `PAYMENT_KEYS`, `getPaymentSettings(?int $branchId)`, `setPaymentSettings(array $data, ?int $branchId)` for: payments.default_method_code, payments.allow_partial_payments, payments.allow_overpayments, payments.receipt_notes.
- **PaymentController** (`system/modules/sales/controllers/PaymentController.php`): Injected SettingsService. `create()` / `parseInput()`: branch-effective default via **`PaymentMethodService::resolveDefaultForRecordedPayment`** (`payments.default_method_code` when allowed, else first allowed method; **no** hardcoded `cash`). **SALES-PAYMENT-METHODS-BRANCH-ENFORCEMENT-01:** `PaymentService::create` enforces allowed list + blocks `gift_card` on this path.
- **PaymentService** (`system/modules/sales/services/PaymentService.php`): Injected SettingsService. In `create()`: after computing balanceDue, loads payment settings for invoice branch; if status === 'completed': when allow_overpayments is false and amount > balanceDue, throws "Payment amount cannot exceed invoice balance due."; when allow_partial_payments is false and amount < balanceDue, throws "Full payment required; partial payments are not allowed."
- **InvoiceController show path** (`system/modules/sales/controllers/InvoiceController.php` + `system/modules/sales/views/invoices/show.php`): invoice detail output now loads branch-aware `getPaymentSettings($branchId)` and renders `payments.receipt_notes` under invoice summary when non-empty (with global fallback when branch override is absent).
- **SettingsController** and **Settings view**: Payments section (default method code, allow partial, allow overpayments, receipt notes); store handles payments.*; isGroupedKey and Other exclude payments.*.
- **Branch-write parity update (backend-only):** `SettingsController` now reuses the existing `online_booking_context_branch_id` context to load/save `payments.*` per selected branch (fallback global when context is 0), matching branch-aware runtime reads in `PaymentController`/`PaymentService`.
- **Seed** (`system/data/seeders/005_seed_phase_c_payment_settings.php`): Sets payment defaults for branch_id 0 via setPaymentSettings (default_method_code=cash, allow_partial_payments=true, allow_overpayments=false, receipt_notes='').

### STEP C2 — VAT Types foundation

- **Migration** (`system/data/migrations/047_create_vat_rates_table.sql`): New table `vat_rates` (id, branch_id NULL, code, name, rate_percent, is_active, sort_order, created_at, updated_at). UNIQUE(branch_id, code). No FK from services.vat_rate_id to vat_rates in this migration to preserve backward compatibility with existing data.
- **Full schema** (`system/data/full_project_schema.sql`): Added `vat_rates` table definition.
- **VatRateRepository** (`system/modules/sales/repositories/VatRateRepository.php`): find(id), listActive(?branchId), findByCode(code, ?branchId). Branch-aware: global (branch_id NULL) or branch-scoped.
- **VatRateService** (`system/modules/sales/services/VatRateService.php`): getById(), listActive(?branchId), getRatePercentById(vatRateId) for resolving service.vat_rate_id to percentage, findByCode().
- **ServiceController** and **service create/edit views**: Injected VatRateService; create(), store (error path), edit(), update (error path) pass `$vatRates = $this->vatRateService->listActive(branchId)`. Create and edit views include VAT rate dropdown (vat_rate_id) with options from $vatRates.
- **Seed** (`system/data/seeders/006_seed_vat_rates.php`): Inserts global (branch_id NULL) vat_rates: zero (0%), standard (20%), reduced (10%). Skips if any global rate already exists.
- **Bootstrap**: Registered VatRateRepository, VatRateService; ServiceController constructor updated with VatRateService.

### STEP C3 — VAT Distribution / report foundation

- **ReportRepository** (`system/modules/reports/repositories/ReportRepository.php`): `getVatDistribution(array $filters)`: joins invoice_items to invoices (deleted_at IS NULL), optional LEFT JOIN vat_rates on rate_percent = tax_rate and (branch_id = invoice.branch_id OR branch_id IS NULL); filters by branch_id and date range on COALESCE(invoices.issued_at, invoices.created_at); groups by invoice_items.tax_rate; returns list of { tax_rate, vat_rate_id, vat_code, vat_name, taxable_base_total, tax_total, gross_total }.
- **ReportService** (`system/modules/reports/services/ReportService.php`): `getVatDistribution(array $filters)` delegates to repo.
- **ReportController** (`system/modules/reports/controllers/ReportController.php`): `vatDistribution()` builds filters from GET (date_from, date_to, branch_id) and returns JSON from getVatDistribution.
- **Routes** (`system/routes/web.php`): GET /reports/vat-distribution with auth and reports.view permission.

---

## Files changed

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | PAYMENT_KEYS; getPaymentSettings, setPaymentSettings. |
| `system/modules/sales/controllers/PaymentController.php` | SettingsService; default / fallback via `resolveDefaultForRecordedPayment` (see SALES-PAYMENT-METHODS-BRANCH-ENFORCEMENT-01). |
| `system/modules/sales/services/PaymentService.php` | SettingsService; create() enforces allow_partial_payments, allow_overpayments. |
| `system/modules/settings/controllers/SettingsController.php` | index: pass payment; store: payments.* block; isGroupedKey includes payments. |
| `system/modules/settings/views/index.php` | Payments section; Other excludes payments. |
| `system/data/seeders/005_seed_phase_c_payment_settings.php` | **New.** Payment defaults. |
| `system/data/migrations/047_create_vat_rates_table.sql` | **New.** vat_rates table. |
| `system/data/full_project_schema.sql` | vat_rates table. |
| `system/modules/sales/repositories/VatRateRepository.php` | **New.** |
| `system/modules/sales/services/VatRateService.php` | **New.** |
| `system/modules/services-resources/controllers/ServiceController.php` | VatRateService; create/edit/update pass vatRates. |
| `system/modules/services-resources/views/services/create.php` | VAT rate dropdown. |
| `system/modules/services-resources/views/services/edit.php` | VAT rate dropdown. |
| `system/data/seeders/006_seed_vat_rates.php` | **New.** Global vat_rates (zero, standard, reduced). |
| `system/scripts/seed.php` | require 005, 006. |
| `system/modules/bootstrap.php` | VatRateRepository, VatRateService; PaymentController, PaymentService with SettingsService; ServiceController with VatRateService. |
| `system/modules/reports/repositories/ReportRepository.php` | getVatDistribution(). |
| `system/modules/reports/services/ReportService.php` | getVatDistribution(). |
| `system/modules/reports/controllers/ReportController.php` | vatDistribution(). |
| `system/routes/web.php` | GET /reports/vat-distribution. |

---

## Backward compatibility

- **Payment settings:** New keys; getPaymentSettings() defaults (default_method_code=cash, allow_partial_payments=true, allow_overpayments=false, receipt_notes='') preserve previous behaviour. Store updates payments only when form sends at least one payments.* key.
- **VAT rates:** services.vat_rate_id already existed; no FK added so existing invalid or null values remain valid. Products continue to use vat_rate decimal; no change. Invoice lines remain tax_rate only; no vat_rate_id on invoice_items.
- **VAT distribution:** Report uses existing invoice_items.tax_rate; vat_rates join is for display (code/name) only; historical data without matching vat_rates still appears grouped by tax_rate.

---

## Where payment settings are enforced

- **Default payment method:** `PaymentController` + **`PaymentMethodService::resolveDefaultForRecordedPayment`** (settings default when in branch-effective allowed set, else first allowed). **`PaymentService::create`** rejects disallowed / `gift_card` / empty catalog.
- **Allow partial / overpayments:** `PaymentService::create()` for status === 'completed', using invoice branch for settings.

---

## How VAT rate resolution works

- **Services:** services.vat_rate_id references vat_rates.id (logical; no DB FK in this phase). Service create/edit forms load active VAT rates for branch via `VatRateService::listActive(?branchId)` (global + branch-scoped). Saving service stores vat_rate_id.
- **Invoice lines:** Invoice items store `tax_rate` (percentage) only; they do not store vat_rate_id. On persist (`InvoiceService::create` / `update`), service lines (`item_type = service`, `source_id` set) get `tax_rate` from `VatRateService::getRatePercentById(service.vat_rate_id)` (**SALES-INVOICE-SETTINGS-ENFORCEMENT-01**). Manual lines keep posted `tax_rate`.
- **VAT distribution report:** Groups by invoice_items.tax_rate. Optionally joins vat_rates where rate_percent = tax_rate and (branch_id = invoice.branch_id OR branch_id IS NULL) to show vat_rate_id, vat_code, vat_name. So distribution is by numeric rate; labels come from vat_rates when a matching rate exists.

---

## Manual QA checklist

### Payment settings (C1)

1. Run migrations and seed (including 005). Open /settings; confirm Payments section: default method code (e.g. cash), allow partial payments, allow overpayments, receipt notes. Save.
2. Open record payment for an invoice; confirm default method is the one set (e.g. Cash). Change to another method and submit; confirm success.
3. Set allow partial payments OFF; save. Record a payment for less than balance due — should fail with "Full payment required; partial payments are not allowed." Record full amount — should succeed.
4. Set allow overpayments OFF (partial can stay ON). Record payment for more than balance due — should fail with "Payment amount cannot exceed invoice balance due."
5. Set allow overpayments ON; record overpayment — should succeed. Set allow partial payments ON again for normal use.

### VAT types (C2)

1. Run migration 047 and seed (006). Confirm vat_rates has rows: zero 0%, standard 20%, reduced 10%.
2. Open Services, create or edit a service; confirm VAT rate dropdown lists Zero, Standard, Reduced. Select one and save; confirm service saves with vat_rate_id.
3. Confirm existing services without vat_rate_id still load; optional VAT rate can be set.

### VAT distribution report (C3)

1. Create invoices with line items that have different tax_rate values (e.g. 0, 10, 20). Issue/pay so they have issued_at or created_at in range.
2. GET /reports/vat-distribution?date_from=Y-m-d&date_to=Y-m-d (and optional branch_id). Confirm JSON array of rows with tax_rate, taxable_base_total, tax_total, gross_total; when vat_rates exist for that rate_percent, vat_rate_id, vat_code, vat_name should be present.
3. Confirm branch and date filters apply (same pattern as other reports).

---

## Postponed / limitations

- Receipt printing/emailing: not implemented; **`payments.receipt_notes`** is shown on invoice show and **snapshotted on payment-related audit entries** (`payment_recorded`, `payment_refunded`, gift-card redemption); **`hardware.use_receipt_printer`** is snapshotted on those audits only (no device driver in-repo).
- Invoice line vat_rate_id: not added; lines use tax_rate only. Defaulting line tax from service.vat_rate_id can be added later.
- Products: still use vat_rate decimal; no link to vat_rates in Phase C.
- VAT distribution: based on invoice_items.tax_rate; perfect historical reporting by vat_rate_id would require persisting vat_rate_id on invoice lines (future migration).
- FK from services.vat_rate_id to vat_rates.id: not added to avoid breaking existing data; can be added after backfill.
- Notifications, memberships, waitlist settings, marketing settings, series, document storage: not in Phase C.
