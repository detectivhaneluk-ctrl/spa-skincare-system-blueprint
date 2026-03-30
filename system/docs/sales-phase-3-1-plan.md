# Sales Phase 3.1 Plan

## Objective

Map current sales/payments/cash-related foundations before financial hardening, without changing business behavior in this phase.

## Current Architecture

- **Routing**
  - Core sales routes live in `system/routes/web.php`.
  - Gift cards and packages are mounted from:
    - `system/modules/gift-cards/routes/web.php`
    - `system/modules/packages/routes/web.php`
- **Module boundaries**
  - Sales depends on contracts/providers for cross-module integration:
    - `AppointmentCheckoutProvider`
    - `GiftCardAvailabilityProvider`
    - `InvoiceGiftCardRedemptionProvider`
  - Appointments package consumption also uses contracts:
    - `PackageAvailabilityProvider`
    - `AppointmentPackageConsumptionProvider`
- **DI wiring**
  - Provider and service bindings are in `system/modules/bootstrap.php`.
- **Persistence pattern**
  - Repositories for read/write SQL.
  - Service layer owns business rules and transactions (mostly).
  - Controllers handle input validation, CSRF forms, and rendering.

## Routes Mapped

### Sales

- `GET /sales`
- `GET /sales/invoices`
- `GET /sales/invoices/create`
- `POST /sales/invoices`
- `GET /sales/invoices/{id}`
- `GET /sales/invoices/{id}/edit`
- `POST /sales/invoices/{id}`
- `POST /sales/invoices/{id}/cancel`
- `POST /sales/invoices/{id}/redeem-gift-card`
- `POST /sales/invoices/{id}/delete`
- `GET /sales/invoices/{id}/payments/create`
- `POST /sales/invoices/{id}/payments`

### Gift Cards

- `GET /gift-cards`
- `GET /gift-cards/issue`
- `POST /gift-cards/issue`
- `GET /gift-cards/{id}`
- `GET /gift-cards/{id}/redeem`
- `POST /gift-cards/{id}/redeem`
- `GET /gift-cards/{id}/adjust`
- `POST /gift-cards/{id}/adjust`
- `POST /gift-cards/{id}/cancel`

### Packages

- `GET /packages`
- `GET /packages/create`
- `POST /packages`
- `GET /packages/{id}/edit`
- `POST /packages/{id}`
- `GET /packages/client-packages`
- `GET /packages/client-packages/assign`
- `POST /packages/client-packages/assign`
- `GET /packages/client-packages/{id}`
- `GET /packages/client-packages/{id}/use`
- `POST /packages/client-packages/{id}/use`
- `GET /packages/client-packages/{id}/adjust`
- `POST /packages/client-packages/{id}/adjust`
- `POST /packages/client-packages/{id}/reverse`
- `POST /packages/client-packages/{id}/cancel`

### Appointment-linked billing/consumption

- `POST /appointments/{id}/consume-package`
- Invoice prefill supports `GET /sales/invoices/create?appointment_id={id}` via `AppointmentCheckoutProvider`.

## Existing Working Features

### Invoices

- Create/edit/show/list/cancel/delete invoice flows.
- Appointment prefill for invoice line item via provider contract.
- Totals and status are recomputed in `InvoiceService`:
  - line totals
  - subtotal/discount/tax/total
  - paid amount and derived status

### Payments

- Manual payment recording against invoice.
- Payment statuses supported in backend: `pending`, `completed`, `failed`, `refunded`, `voided`.
- `paid_amount` on invoice is recomputed from completed payments.

### Gift cards

- Issue/redeem/adjust/cancel flows.
- Balance source of truth is `gift_card_transactions.balance_after`.
- Sales integration for invoice redemption is provider-based and transactional.

### Packages

- Package definitions CRUD (minimal).
- Client package assign/use/adjust/reverse/cancel.
- Appointment package consumption via provider contract, with duplicate guard by appointment reference.

### Sales screens

- `sales` home page, invoice list/create/edit/show, payment create.
- Gift card redemption form on invoice show page.

## Tables Involved

- `invoices`
- `invoice_items`
- `payments`
- `gift_cards`
- `gift_card_transactions`
- `packages`
- `client_packages`
- `package_usages`
- Related links:
  - `appointments` (via `invoices.appointment_id`, appointment checkout/provider consumption references)
  - `clients` (ownership and profile links)
  - `audit_logs` (state-change trace)

## Migrations Involved

- `027_create_invoices_table.sql`
- `028_create_invoice_items_table.sql`
- `029_create_payments_table.sql`
- `034_create_gift_cards_table.sql`
- `035_create_gift_card_transactions_table.sql`
- `036_create_packages_table.sql`
- `037_create_client_packages_table.sql`
- `038_create_package_usages_table.sql`

## Permissions Mapped

- Sales:
  - `sales.view`
  - `sales.create`
  - `sales.edit`
  - `sales.delete`
  - `sales.pay`
- Gift cards:
  - `gift_cards.view`
  - `gift_cards.create`
  - `gift_cards.issue`
  - `gift_cards.redeem`
  - `gift_cards.adjust`
  - `gift_cards.cancel`
- Packages:
  - `packages.view`
  - `packages.create`
  - `packages.edit`
  - `packages.assign`
  - `packages.use`
  - `packages.adjust`
  - `packages.reverse`
  - `packages.cancel`
- Cross-module:
  - `appointments.edit` + `packages.use` on appointment package consumption route.

## Financial Invariants (Must Always Hold)

### Invoice invariants

- `subtotal_amount = sum(invoice_items.line_total)` under service calculation rules.
- `total_amount = subtotal_amount - discount_amount + tax_amount`.
- `paid_amount = sum(payments.amount where payments.status = 'completed')`.
- `balance_due = total_amount - paid_amount`.
- Invoice status should be consistent with financial state (unless explicitly overridden by final domain rules in later phases).

### Payment invariants

- Payment amount must be positive.
- Completed payments are the only values counted in `paid_amount`.
- Payment insert + invoice recompute must commit atomically.

### Gift card invariants

- Gift card balance must never be negative.
- `gift_card_transactions` sequence must produce correct `balance_after`.
- Redeem/adjust/cancel/expire must be transactional and branch-safe.
- Invoice redemption should not duplicate the same `(invoice_id, gift_card_id)` redemption.

### Package invariants

- `remaining_sessions` must not go below `0`.
- `remaining_sessions` must not exceed `assigned_sessions`.
- Usage history in `package_usages` is source of truth; snapshot fields must remain aligned.
- Appointment-based consumption should be idempotent per `(client_package_id, appointment_id)`.

### Branch invariants

- Branch-owned rows (`branch_id != null`) require matching branch context on state change.
- Global rows (`branch_id = null`) can be used in allowed contexts.
- Invoice list filters and provider queries must keep global vs branch behavior explicit.

## Risky Areas Detected

1. **Overpayment and status integrity risk**
- `PaymentService::create` does not enforce `amount <= invoice balance_due`.
- No explicit block for payments against `cancelled/refunded` invoices.
- Possible financially inconsistent states (overpaid invoices without explicit policy).

2. **Weak input validation on invoice money fields**
- Invoice create/edit validation only enforces "at least one line item".
- No backend guardrails for negative/invalid money components (`discount_amount`, `tax_amount`, line quantities/prices/discounts), which can produce negative totals.

3. **Refund/reversal foundation incomplete**
- Payment statuses include `refunded`/`voided`, invoice status includes `refunded`, but there is no complete refund workflow or ledger-safe reversal sequence.
- Gift card refund/reversal handling for previously redeemed invoice payments is absent as an explicit flow.

4. **Read-path side effects in packages**
- `PackageService::listEligibleClientPackages` updates `client_packages` snapshot/status while listing (read operation with writes), increasing hidden side-effect risk.

5. **Concurrency/locking gaps on money writes**
- Invoice row locking is used in gift-card redemption, but not consistently on all payment creation/recompute paths.
- Recompute paths can race under concurrent payment writes.

6. **Soft-delete semantics not financially finalized**
- Invoice delete is soft-delete; payments remain in DB by FK design (not cascaded unless hard-delete).
- No explicit archival/immutability policy for financially posted documents.

7. **Operational cash controls absent**
- No cash register/shift open-close, cash in/out, drawer accountability, or cashier session controls.

## Booker-Level Gaps (Operational)

- Missing cash shift lifecycle (`open_shift`, `close_shift`, expected vs counted cash).
- Missing cash in/cash out operations tied to shifts.
- Missing configurable payment method control at branch level (enabled/disabled methods, limits).
- Missing suspended/held sale workflow.
- Missing robust refund/reversal UX and backend ledger policy.
- Front-desk flow is basic invoice+payment; no guided POS-style transaction state.
- No explicit receipt/settlement lifecycle states beyond current invoice/payment statuses.

## Exact Files to Modify Later (Phase 3.2+)

### Sales core

- `system/modules/sales/services/InvoiceService.php`
- `system/modules/sales/services/PaymentService.php`
- `system/modules/sales/repositories/InvoiceRepository.php`
- `system/modules/sales/repositories/PaymentRepository.php`
- `system/modules/sales/controllers/InvoiceController.php`
- `system/modules/sales/controllers/PaymentController.php`
- `system/modules/sales/views/invoices/create.php`
- `system/modules/sales/views/invoices/edit.php`
- `system/modules/sales/views/invoices/show.php`
- `system/modules/sales/views/payments/create.php`
- `system/routes/web.php`

### Gift cards / packages integration touchpoints

- `system/modules/gift-cards/services/GiftCardService.php`
- `system/modules/gift-cards/repositories/GiftCardTransactionRepository.php`
- `system/modules/packages/services/PackageService.php`
- `system/modules/packages/repositories/PackageUsageRepository.php`
- `system/modules/appointments/services/AppointmentService.php` (package consumption boundary and audit consistency)

### Contracts/provider boundaries (if policy evolution needed)

- `system/core/contracts/InvoiceGiftCardRedemptionProvider.php`
- `system/core/contracts/GiftCardAvailabilityProvider.php`
- `system/core/contracts/AppointmentPackageConsumptionProvider.php`
- `system/modules/bootstrap.php`

### Data model hardening files

- `system/data/migrations/0xx_*` (new phase migrations for refund/cash shift layers)
- `system/data/full_project_schema.sql`

## Recommended Build Order (Phase 3.2+)

### Phase 3.2 — Money write safety baseline

- Enforce strong validation for invoice lines, invoice-level money fields, and payment amounts.
- Add overpayment policy (block or explicit credit strategy) and enforce consistently.
- Block invalid payment writes on cancelled/refunded invoices.
- Add consistent row-locking strategy for invoice payment mutation paths.

### Phase 3.3 — Refund/reversal foundation

- Define canonical refund domain flow:
  - payment reversal records
  - invoice status transitions
  - gift-card redemption rollback policy when applicable
- Add explicit audit events and metadata structure for every reversal.

### Phase 3.4 — Cash register/shift foundation

- Add cash shift tables/services/routes (open/close, cash in/out, counted vs expected).
- Bind cash payments to open shift context where required.

### Phase 3.5 — Front-desk workflow hardening

- Suspended/held sale flow.
- Payment method policy controls by branch.
- Safer operator UX around finalization and post-finalization mutation limits.

### Phase 3.6 — Reconciliation and reporting hooks

- Add end-of-day reconciliation endpoints and report-ready aggregates after invariants are enforced.

## Recommended Next Phase

Proceed with **Phase 3.2 — Money write safety baseline** first, because it reduces immediate financial inconsistency risk without introducing major workflow complexity.
