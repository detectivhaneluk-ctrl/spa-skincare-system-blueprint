# Sales Phase 3.2 Progress

## Changed Files

- `system/modules/sales/services/InvoiceService.php`
- `system/modules/sales/services/PaymentService.php`
- `system/modules/sales/repositories/PaymentRepository.php`
- `system/modules/packages/services/PackageService.php`
- `system/docs/sales-phase-3-2-progress.md`

## Money Validation Rules Added

### Invoice-level rules (`InvoiceService`)

- Enforced strict line-item financial validation before totals are computed:
  - `quantity > 0`
  - `unit_price >= 0`
  - `discount_amount >= 0`
  - `tax_rate` in range `0..100`
  - line discount cannot exceed line subtotal (`quantity * unit_price`)
- Enforced invoice-level amount rules:
  - invoice discount cannot be negative
  - invoice tax cannot be negative
  - invoice discount cannot exceed computed subtotal
  - invoice total cannot become negative
  - `paid_amount` cannot be negative if provided

### Payment rules (`PaymentService`)

- Enforced payment amount safety in service layer:
  - finite numeric amount required
  - amount must be greater than zero
  - completed payment cannot exceed current invoice balance due
- Added duplicate payment-reference protection:
  - blocks duplicate completed payment on same invoice with same `transaction_reference`

## State Guards Added

- `PaymentService::create` now blocks writes for invoices in:
  - `cancelled`
  - `refunded`
- `InvoiceService::cancel` now:
  - no-ops if already `cancelled`
  - blocks canceling a `refunded` invoice
- Existing guard in invoice gift-card redemption (cancelled/refunded block) remains active.

## Transaction / Locking Changes

- Payment apply path now locks invoice row using `InvoiceRepository::findForUpdate` in `PaymentService::create`.
- Overpayment checks are executed inside the transaction and against locked invoice state.
- Payment insert + invoice recompute + audit remain atomic in one transaction.

## Recompute Path Used

- Added centralized recompute entrypoint in `InvoiceService`:
  - `recomputeInvoiceFinancials(int $invoiceId): ?array`
- `recomputePaidAmount()` now delegates to this method for backward compatibility.
- `PaymentService::create` and `InvoiceService::update` use centralized recompute path.
- Status derivation now preserves terminal statuses (`cancelled`, `refunded`) during recompute.

## Package Side-effect Fix

- Removed write side effects from package read/check path:
  - `PackageService::listEligibleClientPackages()` no longer updates `client_packages` while listing.
- Read path now stays read-only and returns derived remaining/status without mutating storage.
- Existing package usage/assignment/cancel/reverse behavior remains unchanged.

## What Is Postponed to Phase 3.3

- Full refund/reversal workflow (payment reversal orchestration + invoice transition policy).
- Structured ledger link model for reverse/refund chains.
- Cash register/shift lifecycle and cash in/out operations.
- Hardening invoice number generation concurrency strategy.
- Additional reconciliation controls for historical overpaid/legacy inconsistent rows.
