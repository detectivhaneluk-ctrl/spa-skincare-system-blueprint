# Sales Phase 3.3 Progress

## Changed Files

- `system/data/migrations/042_create_register_sessions_and_cash_movements.sql`
- `system/data/full_project_schema.sql`
- `system/modules/sales/repositories/RegisterSessionRepository.php`
- `system/modules/sales/repositories/CashMovementRepository.php`
- `system/modules/sales/repositories/PaymentRepository.php`
- `system/modules/sales/services/RegisterSessionService.php`
- `system/modules/sales/services/PaymentService.php`
- `system/modules/sales/controllers/RegisterController.php`
- `system/modules/sales/views/register/index.php`
- `system/modules/sales/views/index.php`
- `system/modules/bootstrap.php`
- `system/routes/web.php`
- `system/docs/sales-phase-3-3-progress.md`

## Routes Added / Touched

- Added:
  - `GET /sales/register`
  - `POST /sales/register/open`
  - `POST /sales/register/{id}/close`
  - `POST /sales/register/{id}/movements`
- Existing invoice/payment/gift-card/package routes unchanged.

## Tables Added

- `register_sessions`
- `cash_movements`

## Table Changes

- `payments`
  - added nullable `register_session_id`
  - added index `idx_payments_register_session`
  - added FK `fk_payments_register_session -> register_sessions(id)` (`ON DELETE SET NULL`)

## Session Rules Used

- Only one open register session per branch is allowed (service-level transactional guard).
- Opening requires:
  - valid `branch_id`
  - non-negative `opening_cash_amount`
  - authenticated opener
- Closing requires:
  - target session exists
  - session is still `open`
  - non-negative `closing_cash_amount`
- Cash movement requires:
  - target session exists
  - session is `open`
  - type in `cash_in | cash_out`
  - amount > 0
  - non-empty reason

## Expected Cash Formula on Close

Expected cash is computed as:

- `expected_cash_amount = opening_cash_amount + completed_cash_sales + cash_in_total - cash_out_total`

Where:
- `completed_cash_sales` = sum of `payments.amount` for session-linked payments with:
  - `payment_method = 'cash'`
  - `status = 'completed'`
- `cash_in_total` / `cash_out_total` come from `cash_movements`.

Variance:
- `variance_amount = closing_cash_amount - expected_cash_amount`

## Payment / Session Linkage Behavior

- For cash payments in `PaymentService`:
  - invoice must be branch-scoped (`invoice.branch_id` required)
  - open register session in the same branch is required
  - payment is linked via `payments.register_session_id`
- For non-cash payments:
  - no register session dependency
  - `register_session_id` stays `null`
- Existing non-cash invoice payment behavior stays compatible.

## State Guards Added

- Cash payment write is blocked when:
  - invoice is `cancelled` or `refunded` (existing 3.2 safety retained)
  - invoice branch is null (cash requires branch register)
  - branch has no open register session
- Cash movement write is blocked for closed/missing sessions.
- Close action blocks already-closed sessions.

## Audit / Transaction Coverage

- Audit events added:
  - `register_session_opened`
  - `register_session_closed`
  - `register_cash_in_recorded`
  - `register_cash_out_recorded`
- Open/close/movement flows use transactional service-layer writes.
- Cash payment apply remains transactional and now binds cash payment to session atomically.

## What Is Postponed to Phase 3.4

- Cashier ownership/assignment policies (who can operate which register).
- Multi-register-per-branch hardware modeling.
- End-of-day reconciliation/report exports beyond current expected/variance storage.
- Shift transfer/handover workflows.
- Enhanced permission segmentation (register-open/close/movement as dedicated permission codes).
- UI ergonomics improvements (kept intentionally minimal in this phase).
