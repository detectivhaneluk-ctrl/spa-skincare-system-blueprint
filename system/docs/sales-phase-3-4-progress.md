# Sales Phase 3.4 Progress

## Changed Files

- `system/data/migrations/043_payment_refunds_and_invoice_sequence.sql`
- `system/data/full_project_schema.sql`
- `system/core/contracts/InvoiceGiftCardRedemptionProvider.php`
- `system/modules/bootstrap.php`
- `system/routes/web.php`
- `system/modules/sales/repositories/InvoiceRepository.php`
- `system/modules/sales/repositories/PaymentRepository.php`
- `system/modules/sales/services/InvoiceService.php`
- `system/modules/sales/services/PaymentService.php`
- `system/modules/sales/controllers/PaymentController.php`
- `system/modules/sales/controllers/InvoiceController.php`
- `system/modules/sales/views/invoices/show.php`
- `system/modules/gift-cards/repositories/GiftCardTransactionRepository.php`
- `system/modules/gift-cards/providers/GiftCardSalesProviderImpl.php`
- `system/modules/gift-cards/services/GiftCardService.php`
- `system/docs/sales-phase-3-4-progress.md`

## New Routes

- `POST /sales/payments/{id}/refund`

## Refund / Reversal Rules Added

### Payment refund foundation

- Added structured refund entries using `payments.entry_type='refund'` and `payments.parent_payment_id`.
- Refund can only target:
  - existing payment row
  - `entry_type='payment'`
  - `status='completed'`
- Supports partial and full refund.
- Hard cap:
  - `refund_amount <= original_payment_amount - already_refunded_amount`
- Refund writes are transactional:
  - lock original payment + invoice
  - create refund payment row
  - apply stored-value reversal (gift card when applicable)
  - recompute invoice financials
  - audit log

### Duplicate / unsafe refund protection

- Prevents refunding a refund row.
- Prevents refunding non-completed original payments.
- Prevents over-refund across multiple partial refunds.

## Invoice State Transition Rules Hardened

- Lifecycle statuses remain compatible: `draft`, `open`, `partial`, `paid`, `cancelled`, `refunded`.
- Recompute now uses net completed amount:
  - payments increase balance coverage
  - refunds decrease coverage
- `cancelled` is terminal in recompute path.
- `refunded` is derived when net paid is zero/negative and completed refunds exist.
- Unsafe operations blocked:
  - cancel blocked if invoice has posted paid amount
  - cancel blocked for already refunded invoices
  - delete blocked for financially posted invoices (`paid`, `partial`, `refunded`, or paid_amount > 0)

## Register-Session Behavior for Refunds

- For cash refunds:
  - invoice must be branch-scoped
  - requires open register session in same branch
  - refund row links `payments.register_session_id`
- Non-cash refunds:
  - no register dependency

## Gift Card / Package Protections Added

### Gift cards

- Added provider/service refund hook for invoice redemption reversal:
  - `refundInvoiceRedemption(...)`
- On gift-card payment refund:
  - creates safe compensating gift-card transaction (`adjustment`) tied to refund reference
  - rejects duplicate reversal for same refund payment
  - updates card status safely
  - keeps operation transactional with payment refund flow
- Protects against corruption by disallowing refund credits to expired/cancelled cards.

### Packages

- Phase 3.2 read-only fix retained:
  - package availability/read paths remain non-mutating.
- No new package write-side read mutation introduced in Phase 3.4.

## Invoice Number Safety

- Added centralized sequence allocator foundation:
  - table `invoice_number_sequences`
  - transactional allocation path `InvoiceRepository::allocateNextInvoiceNumber()`
- `InvoiceService::create` now uses centralized allocation path.
- Legacy `getNextInvoiceNumber()` remains as compatibility wrapper delegating to allocator.

## New / Updated Data Model Pieces

- `invoice_number_sequences` (new)
- `payments.entry_type` (new enum: `payment|refund`)
- `payments.parent_payment_id` (new self-reference FK/index)

## Audit Events Added

- `payment_refunded`
- `gift_card_invoice_refunded`

## What Is Postponed to Phase 3.5

- Dedicated refund approval workflows and role-based separation.
- Register policy enrichment for refund exceptions and manager overrides.
- Enhanced ledger/reporting layer for end-of-day and refund reconciliation.
- UI enhancements for refund history drill-down and grouped reversal chains.
- Additional immutable posting/finalization policy for archived financial documents.
