# Invoice / payment settlement truth (read-only audit)

**Wave:** `MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-06`  
**Purpose:** Prove whether **stored invoice money fields** and **linked payment rows** tell a coherent same-currency settlement story. **Truth and detection only** — no repairs, no recompute, no posting changes.

## Scope

- Non-deleted **`invoices`** rows and all **`payments`** rows whose `invoice_id` points at those invoices.
- **In scope:** header `status`, `currency`, `total_amount`, `paid_amount`, implied stored balance due (`total_amount − paid_amount`), and payment `status`, `currency`, `amount`, `entry_type`.
- **Out of scope:** FX conversion, gift-card ledger outside `payments`, storefront/public commerce, UI/routes, allocation algorithm design, refunds product depth beyond completed refund rows already on the invoice.

## Fields inspected

| Source | Fields |
|--------|--------|
| `invoices` | `id`, `status`, `branch_id`, `currency`, `total_amount`, `paid_amount` (only non-deleted headers) |
| `payments` | `invoice_id`, `status`, `currency`, `amount`, `entry_type` |

## Same-currency rule

- Invoice reference currency = `TRIM(UPPER(invoices.currency))`.
- **Same-currency bucket:** completed rows (`status = 'completed'`) whose `TRIM(UPPER(payments.currency))` **equals** that invoice reference string (non-empty).
- **Net in that bucket:** for each completed row, `+amount` when `entry_type` is not `refund`, otherwise `-amount` (aligned with `PaymentRepository::getCompletedTotalByInvoiceId`).

## Cross-currency rule (excluded from same-currency comparison)

- **Excluded from `successful_payment_total_same_currency`:** any completed row whose normalized currency **differs** from the invoice’s normalized currency, plus all completed rows when the invoice currency is **empty** (no bucket match — those amounts appear only under `successful_payment_total_cross_currency_excluded`).
- **Cross-currency presence** for an invoice when **either** more than one distinct currency appears among completed rows **or** any completed row’s normalized currency differs from the invoice’s (including empty invoice currency with non-empty payment currency).
- **No FX:** amounts in different currencies are never normalized into one scalar for comparison.

## Explicit thresholds (tiny, fixed in code)

| Constant | Value | Use |
|----------|-------|-----|
| `MONEY_EPSILON` | `0.01` | Negative stored balance due; “positive paid with no completed rows”; structural comparisons vs zero |
| `MATERIAL_PAID_VS_EVIDENCE_DELTA` | `0.02` | `abs(invoice.paid_amount − successful_payment_total_same_currency)` ⇒ contradiction |
| `MATERIAL_NET_VS_TOTAL_DELTA` | `0.02` | Completed same-currency net vs `total_amount` for paid/unpaid story checks |

## Contradiction reason codes (`contradiction_reason_codes`)

These flip **`settlement_truth_class`** to **`contradicted_settlement`**:

| Code | Meaning |
|------|---------|
| `negative_stored_balance_due` | `invoice_total_amount − invoice_paid_amount < −MONEY_EPSILON` |
| `positive_paid_amount_without_completed_payment_rows` | `invoice_paid_amount > MONEY_EPSILON` and zero completed payment rows on the invoice |
| `same_currency_completed_net_exceeds_invoice_total` | Same-currency completed net > `total_amount + MATERIAL_NET_VS_TOTAL_DELTA` and `total_amount > MONEY_EPSILON` |
| `paid_amount_mismatch_vs_same_currency_completed_net` | `abs(paid_amount − same_currency_net) > MATERIAL_PAID_VS_EVIDENCE_DELTA` |
| `status_paid_insufficient_same_currency_completed_net` | Header `status = paid`, `total_amount > MONEY_EPSILON`, same-currency net < `total_amount − MATERIAL_NET_VS_TOTAL_DELTA` |
| `status_unpaid_story_fully_covered_by_same_currency_completed_net` | Header `status` ∈ {`draft`,`open`,`partial`}, `total_amount > MONEY_EPSILON`, same-currency net ≥ `total_amount − MATERIAL_NET_VS_TOTAL_DELTA` |

**Informational (listed in `settlement_truth_reasons` only, does not alone contradict):**

- `cross_currency_completed_payment_evidence_present`

## Settlement classes

- **`coherent_settlement`** — no contradiction codes.
- **`contradicted_settlement`** — one or more contradiction codes.

## Aggregate counters (payload / CLI summary)

- **`same_currency_overpaid_count`** — invoices where same-currency completed net exceeds `total_amount` by more than `MATERIAL_NET_VS_TOTAL_DELTA` (and `total_amount > MONEY_EPSILON`).
- **`same_currency_underpaid_count`** — invoices where `invoice_paid_amount` exceeds same-currency completed net by more than `MATERIAL_PAID_VS_EVIDENCE_DELTA` (header claims more collected than same-currency evidence).
- **`paid_but_status_unpaid_count`** — `draft` / `open` / `partial` with `total_amount > MONEY_EPSILON` and same-currency net fully covers total within `MATERIAL_NET_VS_TOTAL_DELTA`.
- **`unpaid_but_status_paid_count`** — `paid` with `total_amount > MONEY_EPSILON` but same-currency net below total beyond `MATERIAL_NET_VS_TOTAL_DELTA`.
- **`negative_balance_due_count`** — stored `total_amount − paid_amount < −MONEY_EPSILON`.
- **`cross_currency_payment_presence_count`** — invoices matching the cross-currency presence rule above.
- **`invoice_without_payments_but_paid_amount_positive_count`** — zero **completed** payment rows and `paid_amount > MONEY_EPSILON`.

Overlaps across counters are allowed.

## CLI

From **`system/`**:

```bash
php scripts/audit_invoice_payment_settlement_truth_readonly.php
php scripts/audit_invoice_payment_settlement_truth_readonly.php --invoice-id=123
php scripts/audit_invoice_payment_settlement_truth_readonly.php --json
```

- **Exit `0`:** run finished (including when contradictions exist).
- **Exit `1`:** uncaught runtime exception only.

## Interpretation

- Use **`contradiction_reason_codes`** as the primary actionable list per invoice; **`settlement_truth_reasons`** also includes informational cross-currency markers.
- A **coherent** invoice can still show **cross-currency** completed rows — review ops and data entry; this audit does not judge FX or “true” economic settlement across currencies.
- **`paid_amount_mismatch_vs_same_currency_completed_net`** often indicates drift between header recompute and ledger, **or** mixed-currency completed rows where the header `paid_amount` followed the global net SQL (all currencies) while this audit compares **invoice currency only**.

## Non-goals (explicit)

This audit **does not** repair data, re-run `InvoiceService::recomputeInvoiceFinancials`, change `PaymentService`, implement credits/refunds beyond classifying existing rows, or alter inventory/stock settlement.

## Service entry point

- **`Modules\Sales\Services\InvoicePaymentSettlementTruthAuditService::run(?int $invoiceIdFilter)`**
- Registered in **`system/modules/bootstrap.php`**.
