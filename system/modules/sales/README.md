# Sales Module

## Invoice Status Rules
- **draft**: Editable, not yet finalized
- **open**: Ready for payment (total > 0, paid = 0)
- **partial**: Partially paid
- **paid**: Fully paid
- **cancelled**: Cancelled
- **refunded**: Refunded (minimal support for now)

## Payment Status Rules
- **pending**: Recorded but not yet received
- **completed**: Successfully received (counted in paid_amount)
- **failed**: Payment failed
- **refunded**: Refunded
- **voided**: Voided

## Calculation Rules
- **Line**: line_total = (quantity × unit_price - discount_amount) × (1 + tax_rate/100)
- **Service lines**: On create/update, `tax_rate` for `item_type = service` is set from `services.vat_rate_id` → `vat_rates.rate_percent` before totals run (manual lines use posted `tax_rate`).
- **Invoice**: subtotal = sum(line_total), total = subtotal - invoice_discount + invoice_tax
- **paid_amount**: Sum of payments with status='completed'
- **Status**: Derived from total vs paid (paid >= total → paid; paid > 0 → partial; else open)


## Invoice currency (canonical)
- **`invoices.currency`:** set on **create** only via `SettingsService::getEffectiveCurrencyCode($invoiceBranchId)` (establishment.currency + legacy merge); not updatable via `InvoiceService::update` (ignored if sent).
- **Gift card redemption:** `GiftCardAvailabilityProvider::getBalanceSummary` currency must equal invoice currency or redemption fails with `DomainException`.

## Payment currency (canonical)
- **`payments.currency`:** set on every payment row insert (`PaymentService::create`, `PaymentService::refund`, `InvoiceService::redeemGiftCardPayment`) from the **invoice** row’s `currency`, or `getEffectiveCurrencyCode(invoice branch)` if the invoice field is empty (legacy). Request input cannot override.
- **Legacy backfill:** migration `064_backfill_payments_currency_from_invoices.sql` copies non-empty `invoices.currency` onto payment rows that still differ (e.g. post-063 default `USD`). For invoices with empty `currency`, run `php system/scripts/repair_payments_currency_empty_invoice.php` (uses the same `getEffectiveCurrencyCode` stack; idempotent).
- **Backend read/report truth:** `ReportRepository` revenue/refunds summaries use **`payments.currency`** for `by_currency`; **`mixed_currency`** is true when multiple currency buckets exist, and **`total_revenue` / `total_refunded`** are **null** in that case (no misleading cross-currency scalar). Payments-by-method groups by `(payment_method, currency)`. `ClientSalesProfileProviderImpl::listRecentPayments` returns `currency` resolved as payment column → invoice column → `getEffectiveCurrencyCode` (no conversion).
- **Client sales profile summary:** `getSummary` uses **`invoices.currency`** for **`billed_by_currency`** / **`total_billed`** (scalar null when **`billed_mixed_currency`**), and **`payments.currency`** for **`paid_by_currency`** / **`total_paid`** (net of completed rows: payments minus refunds; scalar null when **`paid_mixed_currency`**). **`total_due`** is null when either side is mixed or billed/paid single currencies disagree (**CLIENT-SALES-PROFILE-MULTI-CURRENCY-SAFETY-01**).

## Recorded invoice payments (branch + settings)
- **Allowed methods:** `PaymentMethodService::listForPaymentForm` / `isAllowedForRecordedInvoicePayment` — active `payment_methods` for invoice `branch_id` (global ∪ branch), **`gift_card` excluded** on `PaymentService::create` (use gift card redemption).
- **Default method:** `payments.default_method_code` from `SettingsService::getPaymentSettings($invoiceBranchId)` applied only when that code is in the allowed set; otherwise first allowed code via `resolveDefaultForRecordedPayment`. No hardcoded `cash` fallback.

## Register close (multi-currency safety)
- **`RegisterSessionService::closeSession`** aggregates **completed cash** payments by **`payments.currency`** for the session.
- **Single currency (or no cash payments):** `cash_sales_total` is the sum in that unit; `expected_cash_amount` / `variance_amount` are computed as before (opening + cash sales + cash in − cash out vs closing). `cash_sales_mixed_currency` is **false**.
- **Multiple cash currencies:** `cash_sales_mixed_currency` is **true**; `cash_sales_total`, `expected_cash_amount`, and `variance_amount` are **null** (no single-unit drawer math). `cash_sales_by_currency` lists per-currency totals. **`cash_movements`** amounts remain unlabeled in schema; they are still summed into expected only in the single-currency cash-sales case (same as before).

## Receipt / print settings (backend)
- **Receipt footer text:** `SettingsService::getEffectiveReceiptFooterText($branchId)` prefers `receipt_invoice.receipt_message`, else `payments.receipt_notes` (branch-effective); shown on invoice detail and audit payloads for `payment_recorded`, `payment_refunded`, `invoice_gift_card_redeemed`. Saving receipt message from Payment Settings mirrors the first 500 characters into `payments.receipt_notes` for legacy parity.
- **`hardware.use_receipt_printer`:** branch merge; audits snapshot the flag; when enabled, {@see \Core\Contracts\ReceiptPrintDispatchProvider} runs after committed payments (default binding is a no-op).

## Read-only: sales line domain boundary truth audit
- **Purpose:** classify `invoice_items` on non-deleted `invoices` by stored `item_type` + `source_id` vs `products` / `services` (inventory audits cover stock ledger; this covers **line domain** only).
- **Service:** `SalesLineDomainBoundaryTruthAuditService`; **CLI:** `system/scripts/audit_sales_line_domain_boundary_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/SALES-LINE-DOMAIN-BOUNDARY-TRUTH-OPS.md` — read-only; no repairs; not mixed-sales implementation.

## Read-only: sales line → inventory impact truth audit (WAVE-02)
- **Purpose:** same lines as WAVE-01 plus `stock_movements` where `reference_type = invoice_item` / `reference_id` = line id; compares **line domain class** to **ledger net** vs current `InvoiceStockSettlementService` expectation (`paid` ⇒ target net −line qty).
- **Service:** `SalesLineInventoryImpactTruthAuditService`; **CLI:** `system/scripts/audit_sales_line_inventory_impact_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/SALES-LINE-INVENTORY-IMPACT-TRUTH-OPS.md` — read-only; no settlement/invoice changes; not mixed-sales implementation.

## Read-only: sales line lifecycle consistency truth audit (WAVE-03)
- **Purpose:** composes WAVE-02 with **`invoices.status`** into one **`lifecycle_consistency_class`** per line (paid/unpaid vs domain vs accepted inventory impact).
- **Service:** `SalesLineLifecycleConsistencyTruthAuditService`; **CLI:** `system/scripts/audit_sales_line_lifecycle_consistency_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/SALES-LINE-LIFECYCLE-CONSISTENCY-TRUTH-OPS.md` — read-only; no repairs; not mixed-sales implementation.

## Read-only: invoice domain composition truth audit (WAVE-04)
- **Purpose:** aggregates WAVE-03 line rows by **`invoice_id`** into **`invoice_domain_shape`** and **`invoice_domain_composition_class`** (clean service / retail / mixed vs anomalies).
- **Service:** `InvoiceDomainCompositionTruthAuditService`; **CLI:** `system/scripts/audit_invoice_domain_composition_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/INVOICE-DOMAIN-COMPOSITION-TRUTH-OPS.md` — read-only; no repairs; not mixed-sales implementation.

## Read-only: invoice operational gate truth audit (WAVE-05)
- **Purpose:** one deterministic **`operational_gate_class` per invoice** by composing WAVE-02 + WAVE-03 + WAVE-04 outputs only (no new SQL).
- **Service:** `InvoiceOperationalGateTruthAuditService`; **CLI:** `system/scripts/audit_invoice_operational_gate_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/INVOICE-OPERATIONAL-GATE-TRUTH-OPS.md` — read-only; no repairs; not mixed-sales implementation.

## Read-only: invoice / payment settlement truth audit (WAVE-06)
- **Purpose:** compare **invoice header money** (`status`, `currency`, `total_amount`, `paid_amount`, balance due) to **linked `payments`** completed net in the **same currency** only (no FX); detect contradictions only.
- **Service:** `InvoicePaymentSettlementTruthAuditService`; **CLI:** `system/scripts/audit_invoice_payment_settlement_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/INVOICE-PAYMENT-SETTLEMENT-TRUTH-OPS.md` — truth/audit only; **no** repair, recompute, or posting change.

## Read-only: invoice financial rollup truth audit (WAVE-07)
- **Purpose:** compare **invoice header** `subtotal_amount` / `discount_amount` / `tax_amount` / `total_amount` to **persisted line money** (`quantity`, `unit_price`, `discount_amount`, `tax_rate`, `line_total`) and the **recompute contract** in `InvoiceService::recomputeInvoiceFinancials`; detect contradictions only.
- **Service:** `InvoiceFinancialRollupTruthAuditService`; **CLI:** `system/scripts/audit_invoice_financial_rollup_truth_readonly.php` (`--invoice-id=`, `--json`).
- **Ops:** `system/docs/INVOICE-FINANCIAL-ROLLUP-TRUTH-OPS.md` — truth/audit only; **no** repair or production recompute. **Stop after WAVE-07** — await full ZIP truth audit (**no WAVE-08** in this track).

## Appointment Integration (AppointmentCheckoutProvider)
- When creating invoice with ?appointment_id=X, AppointmentCheckoutProvider prefills:
  - client_id, client name
  - One service line item (service_id, name, price)
  - branch_id
- Uses contract only; no direct appointment/service repository access.
