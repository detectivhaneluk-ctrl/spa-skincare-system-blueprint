# Product stock ledger reconciliation — operations

**Tasks:** `PRODUCTS-STOCK-LEDGER-RECONCILIATION-DOCS-AND-OPS-01`; truth alignment `PRODUCTS-LEDGER-OPERATIONS-TRUTH-ALIGNMENT-01`; companion listings `PRODUCTS-LEDGER-OPS-TERMINOLOGY-CONSISTENCY-01` / **WAVE-04** (below).  
**Scope:** Read-only proof that `products.stock_quantity` matches the rolled-up movement ledger for each **non-deleted** product. **No** auto-fix, **no** writes.

## Operational truth model (current; no redesign)

- **Single on-hand column:** `products.stock_quantity` is the authoritative on-hand quantity per SKU. There is **no** separate per-branch on-hand column; all movements apply to that one number.
- **Movement `branch_id` is attribution, not a second stock truth:** Rows in `stock_movements` may carry a non-null `branch_id` (operator context, inventory count branch, or **invoice branch** for `sale` / `sale_reversal` on global catalog SKUs). The reconciliation sum still includes **all** movement rows for the product regardless of `branch_id` — correct for the current model.
- **`manual_adjustment` dual use:** The same `movement_type` is used for (a) **product opening stock** on create — `reference_type = 'product'`, `reference_id = product_id` — and (b) **staff form adjustments** — `reference_type` / `reference_id` both **NULL** (`StockMovementService::createManual`). Infer origin from **references**, not from the type alone.
- **Canonical distinction between writers:** System-linked rows use explicit `reference_type` / `reference_id` (`invoice_item`, `inventory_count`, `product` as above). Operator UI rows intentionally have **no** reference pair so they cannot spoof settlement links.

**Companion read-only reports (no column-vs-sum math):**

- Global SKU **branch-tagged movements:** `php scripts/audit_product_global_sku_branch_attribution_readonly.php` — see `PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md` / `ProductGlobalSkuBranchAttributionAuditService`.
- **Origin classification** rollup: `php scripts/report_product_stock_movement_origin_classification_readonly.php` — `system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md`.
- **Reference integrity** (orphan / malformed `reference_*`): `php scripts/audit_product_stock_movement_reference_integrity_readonly.php` — `system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md`.
- **`other_uncategorized` drift** + manual-bucket movement-type shape: `php scripts/audit_product_stock_movement_classification_drift_readonly.php` — `system/docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md`.

## Exact purpose

- Detect **drift** between:
  - **On-hand column:** `products.stock_quantity` (authoritative on-hand in the current **single global on-hand per SKU** model).
  - **Implied ledger balance:** `SUM(stock_movements.quantity)` for the same `product_id`, **across all movement rows** (any `branch_id`, any `movement_type`, any `reference_*`).

Use this after incidents, partial failures, manual SQL, or to validate backups/migrations — not as a substitute for transactional correctness in application code.

## Exact formula

For each product row `p` with `p.deleted_at IS NULL`:

- **On-hand:** `p.stock_quantity` (treated as a float for comparison).
- **Implied from movements:** `COALESCE(SUM(sm.quantity), 0)` over `stock_movements sm` where `sm.product_id = p.id` (no filter on `branch_id`, `movement_type`, or references).

**Match condition:**

\[
\left| \texttt{on\_hand} - \texttt{implied} \right| \le \varepsilon
\]

with **ε = `1e-6`** (`ProductStockLedgerReconciliationService::QTY_EPS`).

**Delta reported in examples:** `on_hand - implied_net_from_movements` (positive ⇒ column higher than sum of movements).

## Sign semantics

- Each `stock_movements.quantity` is stored **already signed** at insert time (`StockMovementService::signedQuantity`). The report **does not** re-derive sign from `movement_type`.
- **Increases** (e.g. `purchase_in`, positive `manual_adjustment`, `sale_reversal`) and **decreases** (e.g. `sale`, `internal_usage`, negative adjustments) are reflected **only** as stored numeric values.
- Historical bad rows (wrong sign or type) are counted as-is; reconciliation does not reinterpret them.

## What counts as a mismatch

Any non-deleted product where `|on_hand - implied| > QTY_EPS`.

Common interpretations (investigation, not automatic classification):

- Movements applied without updating `products.stock_quantity`, or the column updated without a movement.
- Direct SQL edits to either table.
- Aborted transactions / application bugs outside the normal `StockMovementService` path.
- **Note:** Rows that are **logically** fine but use extremely small float noise beyond ε may appear as mismatches; ε is fixed at `1e-6`.

## What the script and service do **not** do

- **No** `INSERT`, `UPDATE`, or `DELETE`.
- **No** branch-scoped stock model: movements are **not** split per branch for the sum; `branch_id` on movements is **ignored** for this check (appropriate while on-hand is a **single** column per product).
- **No** reconciliation to invoices, counts, or references — only the numeric column vs sum of movement quantities.
- **No** inclusion of soft-deleted products (`products.deleted_at IS NOT NULL` are excluded from the scan).
- **No** guarantee of listing every mismatch in console output (examples are **capped** at `MISMATCH_EXAMPLE_CAP` = 50).

## How to run

From `system/`:

```bash
php scripts/audit_product_stock_ledger_reconciliation.php
```

Implementation: `ProductStockLedgerReconciliationService::run()` (same logic if invoked from PHP).

Exit code: **0** on success (including when mismatches exist — the script only fails on runtime errors). **1** on uncaught failure.

## Recommended usage

- Run in **staging** after schema or data restores; then **production** on a quiet window if drift is suspected.
- Capture stdout for audit trails; investigate each mismatch before changing data.
- Pair with application logs / `AuditService` entries for `stock_movement_created` when tracing a specific SKU.

## How to interpret mismatches

1. Identify `product_id` / `sku` from the output line.
2. Compare `on_hand` vs `implied_net_from_movements`; **delta** = how much the column is “ahead” or “behind” the movement sum.
3. Inspect `stock_movements` for that `product_id` (chronology, types, references). Remember: **invoice settlement** uses `reference_type = 'invoice_item'` and types `sale` / `sale_reversal`; **inventory counts** use `reference_type = 'inventory_count'`; **product create opening qty** uses `reference_type = 'product'` and `movement_type = 'manual_adjustment'`.
4. **Manual UI movements** (`StockMovementController` → `createManual`) always have **`reference_type` / `reference_id` = NULL** (operators cannot attach settlement references from the form).
5. Repair is **out of scope** for this tool: use a separate, reviewed data-fix or application process after root-cause analysis.

## Code references

- `system/modules/inventory/services/ProductStockLedgerReconciliationService.php`
- `system/scripts/audit_product_stock_ledger_reconciliation.php`
- `system/modules/inventory/services/StockMovementService.php` (signing and write path)
