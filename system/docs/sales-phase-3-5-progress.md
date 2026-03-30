# Sales Phase 3.5 Progress — Invoice Finalization / Settlement Integrity

## Changed Files

- `system/modules/sales/services/InvoiceService.php`
- `system/modules/sales/services/PaymentService.php`
- `system/docs/sales-phase-3-5-progress.md`

## Business Rules Added

### 1. Row lock during invoice update

- `InvoiceService::update()` now uses `InvoiceRepository::findForUpdate($id)` instead of `find($id)` so the invoice row is locked for the duration of the transaction. Concurrent updates to the same invoice serialize.

### 2. Financial edit constraint when paid_amount > 0

- When the invoice has any completed payments (`paid_amount > 0`), the update is **constrained** (Option B): line items and totals may still be edited, but the new `total_amount` must be ≥ current paid amount.
- If the submitted data would result in `new_total_amount < paid_amount`, the update is rejected with: *"Invoice total cannot be reduced below the amount already paid."*
- Paid amount is taken from `PaymentRepository::getCompletedTotalByInvoiceId()` (payments − refunds) at update time, inside the same transaction.

### 3. Total vs paid guard

- Before persisting the update, the service checks: `newTotal = round(data['total_amount'], 2)` and `paid = round(getCompletedTotalByInvoiceId(id), 2)`. If `paid > 0` and `newTotal < paid`, the update is rejected. This prevents negative balance_due from edits.

### 4. Totals vs items consistency check (update path only)

- After writing invoice and line items in `update()`, the service calls `assertInvoiceTotalsConsistentWithItems($invoiceId, $data)`.
- It re-reads line items from DB, computes `subtotal = sum(line_total)`, then `expectedTotal = subtotal - discount_amount + tax_amount` (rounded to 2 decimals).
- If `|expectedTotal - stored total_amount| > 0.01`, it throws: *"Invoice total is inconsistent with line items."*
- This guards against drift from bugs or direct DB changes; only runs in the update path (not in `recomputeInvoiceFinancials()`), so payment/refund flows are unchanged.

### 5. issued_at at first financial finalization

- **When:** The first time the invoice has a completed payment (net paid goes from 0 to > 0).
- **Where set:**
  - **PaymentService::create()** — after recording a completed payment and calling `recomputeInvoiceFinancials()`, if `paid` was 0 before this payment and the invoice now has `paid_amount > 0` and `issued_at` is null, the service sets `issued_at = now()` and `updated_by`.
  - **InvoiceService::redeemGiftCardPayment()** — after creating the gift-card payment and calling `recomputePaidAmount()`, same logic: if paid was 0 before and invoice now has paid > 0 and `issued_at` is null, set `issued_at = now()`.
- **Safety:** Only sets when `issued_at` is null; does not overwrite. Refund and subsequent payments do not change `issued_at`. Compatible with register-session and gift-card flows.

### 6. Compatibility preserved

- **Refund:** No change to refund logic; recompute still updates paid/status; `issued_at` is not cleared or changed on refund.
- **Register session:** Cash payment/refund rules unchanged; no new register logic.
- **Gift card:** Redemption still uses `findForUpdate`; first payment via gift card also sets `issued_at` when applicable.

## Manual Test Checklist

- [ ] **Lock:** Open invoice edit in two tabs; submit update in both quickly; one should wait and then succeed, the other should see current data (no lost updates). Alternatively use two concurrent requests and confirm no duplicate/no corrupt state.
- [ ] **Total below paid blocked:** Create invoice, add one payment (e.g. partial). Edit invoice and reduce total below the paid amount (e.g. remove a line or lower price). Submit; expect error *"Invoice total cannot be reduced below the amount already paid."*
- [ ] **Total equal to or above paid allowed:** Same partial invoice; edit to add a line or increase total so new total ≥ paid. Submit; expect success and correct balance_due.
- [ ] **Draft/open (paid = 0):** Edit and change total freely; no paid guard; update succeeds.
- [ ] **Consistency check:** The check runs after every update that replaces items; it ensures stored total matches sum(line_total) − discount + tax. To verify it works, temporarily force a mismatch in code (e.g. set `$data['total_amount'] = 0` before `repo->update` in one test) and confirm the exception *"Invoice total is inconsistent with line items."* is thrown.
- [ ] **issued_at:** Create new invoice, add first payment (cash or card). Load invoice; confirm `issued_at` is set. Add second payment; confirm `issued_at` unchanged. Create another invoice; redeem gift card as first “payment”; confirm `issued_at` set.
- [ ] **Refund:** Invoice with payments; perform full or partial refund. Confirm status/paid/balance_due correct; confirm `issued_at` not cleared.
- [ ] **Gift card refund:** Invoice paid (partially or fully) with gift card; refund that payment. Confirm gift-card reversal and invoice recompute; `issued_at` unchanged.

## Intentionally Postponed

- **Recompute path total validation:** `recomputeInvoiceFinancials()` still uses stored `total_amount`; no validation against invoice_items in recompute (to avoid breaking payment/refund when historical drift exists). Only the update path asserts total vs items.
- **Separate “issued” status:** No new status value; “issued” is represented by `issued_at` set and/or paid_amount > 0 for edit rules. No UI change to show “issued” as a status.
- **Block all financial edits when paid > 0 (Option A):** Not implemented; we use Option B (allow edits with new total ≥ paid) so partial invoices can still add lines or adjust totals upward.
- **Controller/UI:** No change to hide edit link when paid_amount > 0; service enforces the rule. UI can later hide or disable edit for “final” invoices if desired.
- **partially_refunded status / refunded_total on invoice:** Not added; behavior and reporting remain as in Phase 3.4.
