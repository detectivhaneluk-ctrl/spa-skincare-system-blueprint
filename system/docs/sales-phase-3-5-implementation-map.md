# Sales Phase 3.5 — Implementation Map: Invoice Finalization / Settlement Integrity + End-to-End Financial Consistency

**Objective:** Audit-only document. Defines when an invoice becomes final, when edits are blocked, what remains mutable, and the exact implementation plan for Phase 3.5. No business logic changes in this step unless a tiny safe prep change is unavoidable.

**Prerequisites:** Phase 3.1–3.4 completed (see `sales-phase-3-1-plan.md`, `sales-phase-3-2-progress.md`, `sales-phase-3-3-progress.md`, `sales-phase-3-4-progress.md`).

---

## 1. File-by-File Audit

### 1.1 InvoiceService (`system/modules/sales/services/InvoiceService.php`)

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Finalization** | No concept of “final” or “issued”. `issued_at` exists on schema but is never set. | Invoice is never explicitly finalized; only status and editability are used. |
| **Editability** | `EDITABLE_STATUSES = ['draft', 'open', 'partial']`. Update/cancel/delete enforce by status and paid_amount. | Edits allowed while `partial`; total can be reduced below existing paid_amount (see §5). |
| **Recompute** | `recomputeInvoiceFinancials()` reads stored `total_amount` from DB; does not recompute from line items. Paid amount = `PaymentRepository::getCompletedTotalByInvoiceId()` (payments − refunds). Status = `deriveStatusFromPaid(total, paid, currentStatus, hasRefund)`. | Total is not recomputed from items in recompute path; drift possible if items are changed outside service or DB is touched directly. |
| **Locking** | `update()` uses `find()` (no row lock). `redeemGiftCardPayment()` uses `findForUpdate()`. | Concurrent updates to same invoice can both pass editable check; no lock on update. |
| **Totals source** | `computeTotals()` used only in create/update; writes `subtotal_amount`, `discount_amount`, `tax_amount`, `total_amount` to DB. | Single write path for totals is create/update; recompute never recalculates total from items. |

### 1.2 InvoiceRepository (`system/modules/sales/repositories/InvoiceRepository.php`)

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Columns** | Normalize allows: `invoice_number`, `client_id`, `appointment_id`, `branch_id`, `status`, `subtotal_amount`, `discount_amount`, `tax_amount`, `total_amount`, `paid_amount`, `notes`, `issued_at`, `created_by`, `updated_by`. | `issued_at` never set by application code. |
| **Locking** | `findForUpdate($id)` exists and is used by PaymentService and gift-card redemption. | InvoiceService::update does not use it. |

### 1.3 InvoiceItemRepository (`system/modules/sales/repositories/InvoiceItemRepository.php`)

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Line totals** | `create()` accepts `line_total`; InvoiceService sets it via `computeLineTotal()`. No reconciliation with invoice `subtotal_amount`/`total_amount`. | If items are modified outside service, invoice totals can drift from sum of line totals. |
| **Update path** | InvoiceService::update deletes all items and recreates from input; no partial update. | Consistent within one update; no cross-request consistency check (e.g. total vs items). |

### 1.4 PaymentRepository (`system/modules/sales/repositories/PaymentRepository.php`)

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Net paid** | `getCompletedTotalByInvoiceId()` = SUM(CASE entry_type WHEN 'refund' THEN -amount ELSE amount END) WHERE status = 'completed'. | Single source of truth for “net paid”; correct. |
| **Refund totals** | `getCompletedRefundedTotalForParentPayment()`, `hasCompletedRefundForInvoice()` used for refund UI and status derivation. | No stored `refunded_total` on invoice; derived when needed. |

### 1.5 PaymentService (`system/modules/sales/services/PaymentService.php`)

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Payment create** | Locks invoice with `findForUpdate`; blocks cancelled/refunded; enforces amount ≤ balance_due; cash requires open register session; then recompute. | Aligned with Phase 3.2/3.3/3.4. |
| **Refund** | Locks payment and invoice; enforces refund ≤ remaining refundable; gift-card reversal via provider; cash refund requires open session; recompute after. | Aligned with Phase 3.4. |

### 1.6 InvoiceController (`system/modules/sales/controllers/InvoiceController.php`)

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Edit** | edit(): allows if status in `['draft', 'open', 'partial']`. update(): passes through to service (service throws if not editable). | Same as service: partial invoices are editable; no check that new total ≥ existing paid. |
| **Parse input** | parseInput() always sets `status => 'draft'`; service overwrites from current or validates. | Form does not send status for update; service uses current status for editability only. |

### 1.7 Register / gift-card touchpoints

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Cash** | Payment create/refund require branch-scoped invoice and open register session for cash. | Consistent. |
| **Gift card** | Redemption blocked for cancelled/refunded; duplicate redemption blocked; refund reverses via provider. | Consistent. |

### 1.8 Appointment-linked sales

| Area | Current behavior | Gap / risk |
|------|------------------|------------|
| **Prefill** | `AppointmentCheckoutProviderImpl::getCheckoutPrefill()` returns client_id, service_id, price, branch_id. Invoice create can prefill one line. | No lock between appointment and invoice; if appointment is cancelled/changed after prefill, invoice is not updated. |
| **Link** | `invoices.appointment_id` stored; no automatic sync of amounts or status. | Editing invoice (draft/open/partial) can change or remove line that came from appointment; no consistency check. |

---

## 2. When an Invoice Becomes “Final” (Current State)

- **Current behavior:** There is no explicit finalization event. The only notion of “no longer editable” is:
  - **Cancel:** Allowed only when `paid_amount == 0` and status ≠ refunded.
  - **Edit (update):** Allowed only when status ∈ {draft, open, partial}.
- So effectively an invoice is “final” in the sense of “no line/total edits” only when status becomes **paid** or **refunded** or **cancelled** (and for cancel, only when it had no payments).
- **Gap:** There is no “issued” moment; `issued_at` is never set. There is no rule that “first payment finalizes the invoice” (today we allow edit while partial, so total can be changed after first payment).

---

## 3. When Edits Should Be Blocked (Target vs Current)

| Condition | Current | Recommended for 3.5 |
|-----------|--------|----------------------|
| Status = paid | Edit blocked (not in EDITABLE_STATUSES) | Keep. |
| Status = refunded | Edit blocked | Keep. |
| Status = cancelled | Edit blocked | Keep. |
| Status = partial | Edit allowed | Option A: block all line/total edits once paid_amount > 0. Option B: allow edits but enforce new total ≥ paid_amount and recompute. |
| Status = open | Edit allowed | Keep or tighten (e.g. block after “issued” if we add it). |
| Status = draft | Edit allowed | Keep. |

---

## 4. Mutability After First Payment vs After Full Settlement

### 4.1 After first payment (status = partial)

**Current:**

- **Mutable:** All line items, quantities, prices, discounts, tax, invoice-level discount/tax, notes, client_id, branch_id, appointment_id (via full replace in update). So total_amount can be reduced below current paid_amount.
- **Recalculated:** paid_amount and status via `recomputeInvoiceFinancials()` after update (paid from payments table; status from deriveStatusFromPaid).
- **Not enforced:** total_amount ≥ paid_amount after edit.

**Risks:**

- Reducing total below paid creates “overpaid” state (negative balance_due); no guard.
- No distinction between “can add lines” vs “can only add lines (cannot reduce total below paid)”.

### 4.2 After full settlement (status = paid)

**Current:**

- **Mutable:** Nothing via normal edit (edit blocked by EDITABLE_STATUSES).
- **Mutable via other paths:** Only refund can change net paid; refund path recomputes. No other path changes totals or items.
- **Safe:** No edit of lines/totals; paid is derived from payments.

---

## 5. What Must Happen When Lines/Totals Change After Payment/Refund Exists

**Current:**

- If status is draft/open/partial, update replaces items and recomputes totals from new items, then runs `recomputeInvoiceFinancials()`. So:
  - New total can be &lt; old total.
  - If new total &lt; current paid_amount, result is paid_amount &gt; total_amount (negative balance_due). Status becomes `paid` (deriveStatusFromPaid). No validation error.

**Required for 3.5:**

1. **Option A (strict finalization):** Once `paid_amount > 0`, block any update that changes line items or invoice-level money (subtotal/discount/tax/total). Only allow non-financial fields (e.g. notes) or explicit “credit memo”/adjustment flow later.
2. **Option B (constrained edit):** Allow line/total edits while partial but enforce `new_total_amount >= paid_amount` (and optionally `new_total_amount >= 0`). Recompute after; if new total &lt; paid, reject the update.

Current code does neither; this is the main settlement-integrity gap.

---

## 6. Single Source of Truth for balance_due, paid_total, refunded_total, status, totals

| Field | Source of truth | Recalculated from | Consistent? |
|-------|------------------|--------------------|------------|
| **total_amount** | Written by `computeTotals()` in create/update only. Stored on `invoices`. | Not recalculated in recompute; recompute uses stored value. | Risk: if items table is changed outside service or migration, total can drift from sum(line_total). |
| **subtotal_amount, discount_amount, tax_amount** | Same as total_amount. | Only in create/update. | Same as above. |
| **paid_amount** | Always from `PaymentRepository::getCompletedTotalByInvoiceId()` in `recomputeInvoiceFinancials()`; then written to `invoices.paid_amount`. | Payments table (completed payments − completed refunds). | Yes. |
| **balance_due** | Not stored. Computed as `total_amount - paid_amount` in controller/view and in return value of recompute. | total_amount and paid_amount. | Yes, as long as total and paid are correct. |
| **refunded_total** | Not stored. For display, sum of completed refund amounts; for “refundable per payment”, `getCompletedRefundedTotalForParentPayment()`. | Payments table. | Yes. |
| **status** | Written by `recomputeInvoiceFinancials()` via `deriveStatusFromPaid(total, paid, currentStatus, hasRefund)`. | total, paid, current status, hasRefund. | Yes. |

**Conclusion:** paid_amount, status, balance_due, and refunded totals are driven by one source of truth (payments + recompute). total_amount is only correct if it is only ever changed by create/update and matches sum of line totals by design; there is no runtime check that `invoices.total_amount` equals sum of `invoice_items.line_total` after invoice-level discount/tax.

---

## 7. Lifecycle Gaps (draft → issued → partial → paid → partially_refunded → refunded → voided/cancelled)

| State | Exists? | Transition | Gaps |
|-------|--------|------------|------|
| **draft** | Yes (status) | create; update keeps draft if total=0. | No “issued” transition; issued_at never set. |
| **issued** | No | N/A | No status “issued”; only open used for “has total, no payment”. |
| **open** | Yes | draft → open when total &gt; 0 and paid = 0. | — |
| **partial** | Yes | open → partial when 0 &lt; paid &lt; total. | Edits allowed; total can be reduced below paid. |
| **paid** | Yes | partial → paid when paid ≥ total. | — |
| **partially_refunded** | No separate status | Same as “partial” after some refunds (paid &lt; total, hasRefund). deriveStatusFromPaid returns `partial` when paid &gt; 0 and paid &lt; total; hasRefund doesn’t force a distinct status. | “Partially refunded” is not a distinct status; it’s still partial. |
| **refunded** | Yes | When paid ≤ 0 and hasCompletedRefund. | — |
| **cancelled** | Yes | Explicit cancel when paid_amount = 0 and not refunded. Terminal in deriveStatusFromPaid. | — |
| **voided** | No (invoice) | Payment status has voided; invoice does not. | Invoice cannot be “voided”; only cancelled or refunded. |

**Risks:**

- No “issued” step: cannot enforce “no line edits after issue” separately from “no edits after first payment”.
- Partial state allows edits that can create total &lt; paid.
- No concurrency control on invoice update (no `findForUpdate` in update path).

---

## 8. Can Invoice Totals Drift from Line Items / Payments / Refunds?

- **Totals vs line items:** Yes. `recomputeInvoiceFinancials()` does not read `invoice_items`; it uses stored `total_amount`. So if someone updates `invoice_items` without going through InvoiceService, or if a bug writes wrong total, totals and items can drift. No periodic reconciliation.
- **Totals vs payments/refunds:** No. paid_amount and status are always recomputed from payments when recompute runs; they don’t drift from payments.
- **Refunds:** Refunds are just negative contributions in `getCompletedTotalByInvoiceId()`; no separate stored “refunded_total” to get out of sync.

---

## 9. Appointment-Linked Sales and Gift-Card Flows — Inconsistent Financial State?

- **Appointment prefill:** Creates invoice with optional appointment_id and one prefill line. If user then edits invoice (draft/open/partial), they can change or remove that line. No check that “appointment-linked invoice” keeps a minimum consistency with appointment (e.g. same service/price). So operational inconsistency possible (e.g. invoice says different amount than appointment), but not a double-spend or wrong paid_amount; financial totals are still driven by invoice lines + payments.
- **Gift card:** Redemption creates payment; refund reverses it. If invoice is then edited (while partial) and total is reduced below paid, the “overpaid” state is a business rule issue, not a gift-card-specific bug. Gift card balance and payment records stay consistent.

---

## 10. Exact Risk List (Summary)

1. **Edits after first payment:** Line/total edits allowed while partial; total can be set below paid_amount → negative balance_due, no guard.
2. **No finalization moment:** `issued_at` never set; no “issued” status; no policy like “no line edits after first payment” or “no line edits after issue”.
3. **Recompute does not derive total from items:** total_amount is never recomputed from invoice_items in recompute path → risk of drift if items are changed outside service or DB is manipulated.
4. **No row lock on invoice update:** InvoiceService::update uses `find()`; concurrent updates can both pass editable check and double-write.
5. **No invariant check:** No check that `invoices.total_amount` equals recomputed value from current invoice_items (subtotal − discount + tax).
6. **Partially refunded:** No distinct status; UX/audit might want “partially_refunded” vs “partial” (optional).
7. **Appointment link:** No enforcement that appointment-linked invoice stays consistent with appointment after prefill (optional for 3.5).

---

## 11. Exact Implementation Plan for Phase 3.5

### 11.1 Finalization and edit rules

- **Define “final” (choose one or both):**
  - **Option A:** Invoice is final when `paid_amount > 0` (first payment). Then: block all line-item and invoice-level money edits when `paid_amount > 0`; only allow notes/non-financial fields (and optionally explicit adjustment flow later).
  - **Option B:** Add “issued” and set `issued_at` when invoice is first sent/confirmed; block line/money edits after `issued_at` is set.
- **Implement edit guard:** In `InvoiceService::update()`, if chosen policy is “no line/total edits when paid_amount > 0”, then:
  - Reject update when `paid_amount > 0` and submitted data changes any of: items, discount_amount, tax_amount (or any field that would change total).
  - Alternatively, allow edits but validate `new_total_amount >= paid_amount` and reject otherwise.

### 11.2 Totals consistency

- **Option A:** In `recomputeInvoiceFinancials()`, optionally recompute total from current invoice_items (same formula as computeTotals) and either (a) overwrite invoices.total_amount with it, or (b) compare and log/fail if different. Prefer (a) only if we always want total to be derived from items; otherwise (b) for detection.
- **Option B:** In `InvoiceService::update()` only, after writing items and totals, assert that stored total equals sum of line totals (with discount/tax rules). No change to recompute path.
- **Recommendation:** Start with 11.1 (edit block or new_total ≥ paid_amount); add 11.2 as a consistency check in update (and optionally in recompute) without changing behavior for paid/refunded invoices that are not edited.

### 11.3 Concurrency

- In `InvoiceService::update()`, use `findForUpdate($id)` instead of `find($id)` inside the transaction so concurrent updates serialize.

### 11.4 Optional

- Set `issued_at` when first payment is recorded (or when a new “issue” action is added); keep or introduce “issued” in docs only (no new status required).
- Add stored or derived “refunded_total” on invoice for reporting if needed.
- Add status “partially_refunded” in deriveStatusFromPaid when paid &gt; 0 and paid &lt; total and hasRefund (optional; currently we still show partial).

### 11.5 Files to touch (implementation phase)

| File | Change |
|------|--------|
| `InvoiceService.php` | Add edit guard (no line/total when paid &gt; 0, or new_total ≥ paid_amount); use findForUpdate in update; optional total-vs-items check. |
| `InvoiceController.php` | No change if guard is in service; optionally hide edit link/button when invoice is “final” (e.g. paid_amount &gt; 0). |
| `InvoiceRepository.php` | No change (findForUpdate already exists). |
| `PaymentService.php` | Optional: set invoice `issued_at` on first successful payment (if we adopt “issued on first payment”). |
| Docs | Update lifecycle description and “when edits are blocked” in README or docs. |

### 11.6 What not to do in 3.5 (unless minimal and safe)

- No UI redesign.
- No new approval workflows or roles.
- No change to refund or payment logic beyond optional `issued_at`.
- No new tables; optional new column only if strictly needed (e.g. refunded_total for reporting).

---

## 12. Deliverables Checklist (When Implementing)

- [ ] Documented rule: when is an invoice “final” (paid_amount &gt; 0 and/or issued_at).
- [ ] Edit guard: block or constrain line/total edits when paid_amount &gt; 0 (or when issued).
- [ ] Concurrency: use findForUpdate in InvoiceService::update().
- [ ] Optional: total vs items consistency check in update or recompute.
- [ ] Optional: set issued_at on first payment.
- [ ] Progress note: `system/docs/sales-phase-3-5-progress.md` (after implementation).

---

*End of implementation map. No business logic implemented in this step; implementation to follow in next step.*
