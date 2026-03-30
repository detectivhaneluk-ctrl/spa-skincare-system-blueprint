# Invoice financial rollup truth audit (read-only)

**Wave:** `MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-07`  
**Service:** `Modules\Sales\Services\InvoiceFinancialRollupTruthAuditService`  
**CLI:** `system/scripts/audit_invoice_financial_rollup_truth_readonly.php`

## Scope

- **In scope:** Non-deleted `invoices` rows and their `invoice_items` lines; read-only SQL plus one composed pass of `SalesLineLifecycleConsistencyTruthAuditService` (WAVE-03) for `mixed_domain_line_count` only.
- **Out of scope:** Schema/migrations, any write path (invoice, payment, sales, inventory), tax engine changes, discount allocation design, repairs, storefront/public commerce, UI/routes/reports.

This document describes **truth/audit only** — **not** repair, **not** production recompute, **not** a substitute for `InvoiceService::recomputeInvoiceFinancials`.

## Header fields inspected

From `invoices` (non-deleted rows only):

| Field | Role in this audit |
|--------|---------------------|
| `id` | Invoice identity |
| `status` | Echoed as `invoice_status` (lowercased trim) |
| `branch_id` | Echoed as `invoice_branch_id` |
| `currency` | Echoed as `invoice_currency` (uppercase trim) |
| `subtotal_amount` | Compared to line rollup evidence (see below) |
| `discount_amount` | Invoice-level discount; used in total rollup and compared to Σ line `discount_amount` |
| `tax_amount` | Invoice-level tax; used in total rollup only (no per-line tax money column) |
| `total_amount` | Compared to `Σ line_total − discount_amount + tax_amount` |

## Line fields inspected

From `invoice_items` joined to non-deleted `invoices`:

| Field | Use |
|--------|-----|
| `quantity` | Must be finite; drives pre-tax net and `computeLineTotal` check |
| `unit_price` | Same |
| `discount_amount` | Summed to `line_discount_evidence`; pre-tax net = `quantity × unit_price − discount_amount` |
| `tax_rate` | Used only in the **same** formula as `InvoiceService::computeLineTotal` (not a separate “tax engine”) |
| `line_total` | Summed to `line_total_evidence`; compared to header `subtotal_amount` |

## Evidence derivation rules

1. **`line_subtotal_evidence`** (pre-tax net roll-up):  
   `Σ round((quantity × unit_price) − discount_amount, 2)` per line, accumulated with rounding to 2 decimals between lines.  
   This is **not** the same as header `subtotal_amount` in code (header subtotal is tax-inclusive line totals).

2. **`line_discount_evidence`:** `Σ line discount_amount` (per-line channel).

3. **`line_total_evidence`:** `Σ line_total`.

4. **`line_tax_evidence`:** **Unavailable** — there is no persisted per-line tax **amount** column; **`tax_rate` alone is not money**. This audit does **not** infer tax from rates. Output uses `null`; **`tax_mismatch_count` is always 0**; **`tax_delta` is always `null`**.

5. **Header subtotal contract (authoritative for mismatch):**  
   `InvoiceService::recomputeInvoiceFinancials` defines `subtotal_amount = Σ line_total`.  
   Therefore **`subtotal_delta = stored_subtotal_amount − line_total_evidence`** and a **subtotal mismatch** is when `|subtotal_delta| > material_rollup_delta`.

6. **Header total contract:**  
   `total_amount` should equal `line_total_evidence − stored_discount_amount + stored_tax_amount` (same structure as recompute).  
   **`total_delta = stored_total_amount − (line_total_evidence − stored_discount + stored_tax)`**.

7. **Per-line persisted total drift:**  
   For each line, expected `line_total` = `round((qty×unit − disc) × (1 + tax_rate/100), 2)` per `InvoiceService::computeLineTotal`.  
   If `|expected − stored line_total| > line_total_component_epsilon`, emit reason `line_persisted_total_drift_from_line_money_fields`.

## Unavailable evidence handling

- **Tax:** No line tax money → `line_tax_evidence` null; no tax contradiction flags.
- **Missing / non-finite** numeric line fields: invoice is classed **`insufficient_line_financial_evidence`**; deltas/evidence fields that require a full sum are `null`.

## Thresholds (tiny, explicit)

Declared on the payload as `thresholds`:

| Key | Value | Meaning |
|-----|-------|---------|
| `material_rollup_delta` | `0.02` | Subtotal/total rollup material mismatch |
| `material_discount_channel_delta` | `0.02` | Header discount vs Σ line discount |
| `line_total_component_epsilon` | `0.01` | Stored `line_total` vs formula from components |

## Contradiction classes / reasons

**`financial_rollup_truth_class`**

| Class | Meaning |
|--------|---------|
| `coherent_financial_rollup` | No provable contradictions under this contract |
| `contradicted_financial_rollup` | At least one provable contradiction |
| `insufficient_line_financial_evidence` | Lines exist but required numeric fields are missing or non-finite |

**Typical `financial_rollup_truth_reasons` codes**

| Code | Meaning |
|------|---------|
| `stored_subtotal_differs_from_sum_line_total_evidence` | Header subtotal ≠ Σ `line_total` beyond ε |
| `stored_total_differs_from_line_total_evidence_minus_header_discount_plus_header_tax` | Header total ≠ rollup formula |
| `stored_invoice_discount_differs_from_sum_line_discount_amount` | Header `discount_amount` ≠ Σ line `discount_amount` beyond ε |
| `line_persisted_total_drift_from_line_money_fields` | At least one line fails component-vs-`line_total` check |
| `invoice_has_lines_but_non_finite_or_missing_money_fields` | Insufficient numeric line data |
| `invoice_has_no_lines_but_header_money_non_zero` | Structural header money without lines |

**Operator note (discount channel):** In this product, **invoice-level** `discount_amount` and **per-line** `discount_amount` are **different channels**. A **discount mismatch** is **numeric truth** that the two differ; it is **not** always a defect. Interpret together with subtotal/total drift and line component drift.

## CLI usage

From repository `system/` directory:

```bash
php scripts/audit_invoice_financial_rollup_truth_readonly.php
php scripts/audit_invoice_financial_rollup_truth_readonly.php --invoice-id=123
php scripts/audit_invoice_financial_rollup_truth_readonly.php --json
```

- **Exit 0:** Audit finished (contradictions do **not** change exit code).  
- **Exit 1:** Uncaught runtime failure (bootstrap, DB, etc.).

## Interpretation

- Run after WAVE-01–06 acceptance when checking whether **stored header money** still matches **persisted line money** and the **documented recompute contract**.
- **High signal:** `line_persisted_total_drift_from_line_money_fields`, subtotal/total rollup mismatches (direct recompute drift).
- **Context-heavy:** discount channel mismatch alone (see above).

**This is truth/audit only — not repair.**
