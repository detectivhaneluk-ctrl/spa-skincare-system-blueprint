# Inventory operational depth — read-only ops (invoice settlement drilldown + negative on-hand exposure)

## Why these tools exist

Stock-health consolidated and coherence audits prove **ledger integrity, classification, and payload contracts** across the inventory read model. They do **not** give operators a **line-level** view of **invoice product settlement** (target net vs movements per `invoice_item`), nor a dedicated **negative on-hand** slice with **conservative** movement-based explanations.

These two CLIs close that gap **without** changing stock behavior, settlement code, or schema.

**Explicit non-goals:** no repair, no automatic adjustment, no UI, no write paths. Both tools are **read-only**.

---

## 1) Invoice ↔ stock settlement drilldown

**CLI:** `php scripts/report_product_invoice_stock_settlement_drilldown_readonly.php` (from `system/`)  
**Flags:** `--invoice-id=<int>` (optional), `--json` (optional; stable machine payload)

**Service:** `Modules\Inventory\Services\ProductInvoiceStockSettlementDrilldownService`

### Field meanings (per line)

| Field | Meaning |
|--------|---------|
| `invoice_id` | Parent invoice. |
| `invoice_item_id` | `invoice_items.id` for the product line. |
| `invoice_status` | Current `invoices.status` (e.g. `paid`). |
| `invoice_branch_id` | Invoice `branch_id` (nullable = global/HQ invoice). |
| `product_id` | `invoice_items.source_id` for `item_type = product`. |
| `product_branch_id` | `products.branch_id` (nullable = global SKU). |
| `line_quantity` | Line `quantity` from the invoice item. |
| `target_net_quantity` | **`-line_quantity`** when `invoice_status = paid` **and** `line_quantity > 0`; otherwise **`0`**. Same target rule as `InvoiceStockSettlementService::syncProductStockWithInvoiceSettlement`. |
| `current_net_quantity_from_movements` | **Σ `stock_movements.quantity`** where `reference_type = 'invoice_item'`, `reference_id = invoice_item_id`, `movement_type IN ('sale','sale_reversal')`. |
| `settlement_delta` | `target_net_quantity - current_net_quantity_from_movements` (ε = `1e-6` for **aligned**). |
| `movement_count_sale` | Row count of settlement `sale` movements for that line. |
| `movement_count_sale_reversal` | Row count of settlement `sale_reversal` movements for that line. |
| `settlement_status` | Deterministic bucket (see below). |
| `reason_codes` | Short machine codes; **honest** when cause is not fully provable. |

### Aggregate payload (top level)

| Field | Meaning |
|--------|---------|
| `drilldown_schema_version` | Semver string for JSON stability. |
| `generated_at_utc` | ISO-8601 UTC timestamp. |
| `invoice_id_filter` | Filter used, or `null` if all invoices. |
| `lines_scanned` | Product invoice lines included. |
| `invoices_scanned` | Distinct invoices for those lines. |
| `settlement_status_counts` | Counts per `settlement_status`. |
| `affected_lines_count` | Lines where `settlement_status ≠ aligned`. |
| `affected_invoice_ids_sample` | Sorted sample of invoice ids (capped). |
| `line_examples` | Non-aligned lines only, **deterministic** sort, capped. |
| `lines` | Full line list (present in `--json` output for audit proof; may be large). |

### `settlement_status` definitions

| Status | When |
|--------|------|
| `aligned` | Product exists, active, branch contract OK, and \|`settlement_delta`\| &lt; ε. |
| `under_settled` | Contract OK but `settlement_delta < -ε` (movements **less negative** than target; would need more settlement deduction in a sync). |
| `over_settled` | Contract OK but `settlement_delta > ε` (movements **more negative** than target; would need reversal in a sync). |
| `missing_product` | Missing/invalid `product_id`, or no live `products` row. |
| `inactive_product` | Product row exists but not active; settlement would throw on sync. |
| `branch_contract_risk` | Active product fails `InvoiceProductStockBranchContract` vs invoice branch (same rules as settlement). |

---

## 2) Negative on-hand exposure

**CLI:** `php scripts/report_product_negative_on_hand_exposure_readonly.php`  
**Flags:** `--json` (optional)

**Service:** `Modules\Inventory\Services\ProductNegativeOnHandExposureReportService`

**Scope:** non-deleted products with `stock_quantity < 0`.

**Recent window:** `recent_*` counts use movements with `created_at >= UTC_TIMESTAMP() - INTERVAL 90 DAY` (see payload `recent_window_days`).

### Field meanings (per product)

| Field | Meaning |
|--------|---------|
| `product_id`, `sku`, `name`, `branch_id` | Product identifiers (repo style). |
| `stock_quantity` | Current on-hand (negative). |
| `latest_movement_at`, `latest_movement_type` | Latest row by `(created_at DESC, id DESC)`. |
| `recent_*_count` | Counts in the recent window for the listed movement types only. |
| `exposure_class` | Conservative classification (see below). |
| `reason_codes` | Supporting codes; weaker claims when evidence is thin. |

### Aggregate payload

| Field | Meaning |
|--------|---------|
| `exposure_schema_version` | Semver for JSON stability. |
| `generated_at_utc` | ISO-8601 UTC. |
| `recent_window_days` | Window for `recent_*` counts. |
| `products_scanned` | Count of non-deleted products (denominator context). |
| `negative_on_hand_products_count` | Rows with `stock_quantity < 0`. |
| `exposure_class_counts` | Counts per class. |
| `critical_exposure_count` | Count with `exposure_class = suspicious_policy_breach_history`. |
| `examples` | First N negative products by `id` (capped). |
| `products` | Full negative list in `--json` (deterministic order by `id`). |

### `exposure_class` definitions

| Class | When (deterministic priority) |
|--------|--------------------------------|
| `suspicious_policy_breach_history` | **Latest** movement type is `sale`, `internal_usage`, or `damaged`. Under normal `ProductStockQuantityPolicy` enforcement, those types should not leave on-hand negative **when applied through `StockMovementService`**; negative on-hand plus a **latest** guarded deduction is flagged for investigation (import, legacy SQL, or inconsistency). |
| `count_adjustment_tail_likely` | Latest movement is `count_adjustment` (count variance can drive negative on-hand per policy). |
| `adjustment_tail_likely` | Latest movement is `manual_adjustment` (manual signed qty can drive negative on-hand per policy). |
| `mixed_history_unproven` | Anything else: e.g. latest is `purchase_in`, `sale_reversal`, unknown type, or no movements — **no strong label** without deeper timeline analysis. |

---

## Operator reading order

1. Run **consolidated stock quality** (and coherence audit if you need contract proof): see `system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md` and `PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md`.
2. If invoice settlement is suspected, run **settlement drilldown**; narrow with `--invoice-id=` when you have a suspect invoice.
3. If negative on-hand is the concern, run **negative on-hand exposure**; treat `suspicious_policy_breach_history` as highest priority review; use `mixed_history_unproven` as “needs human timeline / SQL,” not “clean.”

---

## How this differs from stock-health consolidated / coherence audits

| Concern | Consolidated / coherence | These two tools |
|---------|---------------------------|-----------------|
| Ledger Σ movements vs `stock_quantity` | Yes | No (not recomputed here) |
| Origin classification / reference integrity | Yes | No |
| Per–invoice-item settlement target vs movements | No | **Yes (drilldown)** |
| Negative on-hand movement narrative | Partial / indirect | **Yes (exposure)** |
| Contract / fingerprint / advisory gates | Yes | No |

---

## Read-only guarantee

Neither service opens transactions for mutation, calls `StockMovementService` / `InvoiceStockSettlementService`, nor writes files. They only **SELECT** (and aggregate) existing rows. They **do not repair** data; any fix remains a separate, explicitly scoped change.
