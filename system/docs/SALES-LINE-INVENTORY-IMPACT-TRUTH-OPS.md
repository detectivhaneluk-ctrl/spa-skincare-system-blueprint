# Sales line inventory impact truth (read-only ops)

## Why this audit exists

**WAVE-01** (`SalesLineDomainBoundaryTruthAuditService`) proves what each `invoice_items` row *claims* to be (service vs retail vs ambiguous) from `item_type` + `source_id`. **This audit (WAVE-02)** adds the other half of the truth: **what the stock ledger actually did** for that line, using only rows where `stock_movements.reference_type = 'invoice_item'` and `reference_id` = the line id.

Together, operators can see **domain story vs inventory impact** without changing settlement, invoices, or movements.

**This tool is read-only, does not repair data, and does not implement mixed-sales behavior.**

---

## Scope and joins

- **Invoices / lines:** Same scope as WAVE-01 (non-deleted `invoices`, all current `invoice_items`; optional `--invoice-id=`).
- **Movements:** Only `stock_movements` linked via **`reference_type = 'invoice_item'`** and **`reference_id = invoice_items.id`**.
- **Composition:** Calls `SalesLineDomainBoundaryTruthAuditService::run` and enriches each returned line.

---

## `inventory_impact_shape` (deterministic)

Derived from linked rows (same reference contract as above):

| Value | Meaning |
|--------|--------|
| `no_linked_stock_movements` | No matching `stock_movements` rows. |
| `sale_only_movements` | All linked rows have `movement_type = sale` (only). |
| `sale_reversal_only_movements` | All linked rows have `movement_type = sale_reversal` (only). |
| `sale_and_sale_reversal_movements` | At least one `sale` and at least one `sale_reversal` row. |
| `non_sales_movement_types_linked` | At least one linked row has a type **other than** `sale` / `sale_reversal` (e.g. `internal_usage`). |
| `unsupported_movement_shape` | Blank/null `movement_type`, **or** more than one distinct `product_id` on the same `reference_id`, **or** other unclassified type sets after normalization. |

Signed **`quantity`** values are summed as stored (see `StockMovementService` sign rules for `sale` vs `sale_reversal`).

---

## `inventory_impact_class` (deterministic)

High-level rules (see `SalesLineInventoryImpactTruthAuditService` for full ordering and `reason_codes`):

| Class | Intent |
|--------|--------|
| `retail_line_with_expected_inventory_impact` | `line_domain_class = clear_retail_product_line` and linked movements + net quantity **match** the current settlement expectation model. |
| `service_like_line_with_no_inventory_impact` | `clear_service_line` and **no** linked movements. |
| `retail_line_missing_expected_inventory_impact` | `clear_retail_product_line`, invoice **`status = paid`**, and **no** linked movements (deduction expected under current architecture). |
| `service_like_line_with_unexpected_inventory_impact` | `clear_service_line` but linked movements exist. |
| `mixed_line_with_inventory_contradiction` | Ambiguous/mixed domain (or clear retail with **wrong** net / wrong product / unexpected activity vs non-paid header). |
| `orphaned_inventory_impact_story` | Domain line is **orphaned / unsupported contract** but ledger rows still reference the line. |
| `unsupported_inventory_contract` | Linked shape uses **non-settlement** types or **unsupported** movement shape while domain is otherwise clear enough to expect settlement-only rows. |
| `ambiguous_inventory_story` | Not enough clean domain + ledger pairing for a stronger class (e.g. unusable domain with no movements). |

### Expectation model (explicit)

- **Only** `invoices.status = 'paid'` is treated as “settlement target active” for retail lines, matching `InvoiceStockSettlementService` (`targetAllPaid = (status === 'paid')`).
- **Expected net** (sum of signed `quantity` on linked `sale` + `sale_reversal` rows): **`-line quantity`** when status is `paid`, else **`0`** (draft / open / partial / cancelled / refunded / etc. all use **0** for this audit’s expectation).
- Compared to actual net with tolerance **1e-6**.

---

## Operator reading order

1. Run the CLI (default summary, or `--json` for full `lines`).
2. Read `inventory_impact_class_counts` — anything other than `retail_line_with_expected_inventory_impact` + `service_like_line_with_no_inventory_impact` is “not cleanly aligned”.
3. Use `examples_by_inventory_impact_class` (capped) for concrete `invoice_item_id` / `invoice_id`.
4. Cross-check `line_domain_class` (from WAVE-01) vs `inventory_impact_shape` on the same row.

---

## Limitations

- Does **not** prove physical returns, partial refunds, or branch attribution beyond stored `stock_movements`.
- Does **not** read appointments, packages, gift cards, payroll, or non–`invoice_item` references.
- **Staff** is not on `invoice_items`; no performer-level consumption proof.
- “Expected” is **current shipped settlement** semantics, not a future mixed-basket design.

---

## How this differs from other audits

| Audit | Focus |
|--------|--------|
| **Sales line domain boundary (WAVE-01)** | Catalog/domain classification of the line only; **no** `stock_movements`. |
| **Inventory drilldown / stock-health audits** | Products, ledger health, global SKU, movement origin — **not** per-invoice-line domain pairing. |
| **This audit (WAVE-02)** | **Per line:** WAVE-01 `line_domain_class` **vs** invoice-item-linked ledger facts + settlement expectation. |

---

## CLI

From `system/`:

```bash
php scripts/audit_sales_line_inventory_impact_truth_readonly.php
php scripts/audit_sales_line_inventory_impact_truth_readonly.php --invoice-id=123
php scripts/audit_sales_line_inventory_impact_truth_readonly.php --json
```

- **Exit 0:** success. **Exit 1:** uncaught exception. **No** DB or file writes.

---

## Registration

- **Service:** `Modules\Sales\Services\SalesLineInventoryImpactTruthAuditService`
- **DI:** `system/modules/bootstrap.php`
- **Payload:** `audit_schema_version` (integer) + `composed_domain_audit_schema_version` (from WAVE-01).
