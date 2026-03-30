# Stock movement classification drift — operations

**Task:** `PRODUCTS-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-AUDIT-01`  
**Scope:** Read-only explanation of rows in **`other_uncategorized`** from `ProductStockMovementOriginClassificationReportService`, plus a **shape** check on the **`manual_operator_entry`** bucket. **No** writes.

## Purpose

- Turn the **`other_uncategorized`** total into a **mutually exclusive** breakdown (ordered `CASE`: first reason wins).
- Surface **null reference pair** rows that are still classified as `manual_operator_entry` but use a `movement_type` outside `StockMovementService::MANUAL_ENTRY_MOVEMENT_TYPES` (e.g. `sale` / `sale_reversal` with both references null — not creatable from `createManual`; indicates legacy SQL or imports).

## Origin expression

The filter **`(origin) = 'other_uncategorized'`** uses the **same** `CASE` expression as `ProductStockMovementOriginClassificationReportService` (must stay aligned in code).

## Drift reasons (within `other_uncategorized`)

| Drift reason key | Meaning |
|------------------|---------|
| `reference_id_set_reference_type_missing` | `reference_id` set, `reference_type` null/blank |
| `reference_type_set_reference_id_missing` | `reference_type` non-blank, `reference_id` null |
| `invoice_item_unexpected_movement_type` | `reference_type = 'invoice_item'` but `movement_type` not in `sale`, `sale_reversal` |
| `inventory_count_unexpected_movement_type` | `reference_type = 'inventory_count'` but `movement_type` ≠ `count_adjustment` |
| `product_reference_id_ne_product_id` | `reference_type = 'product'`, `reference_id` set, and `reference_id` ≠ `product_id` |
| `product_reference_other_shape` | `reference_type = 'product'` and not the opening-stock pattern (`manual_adjustment` + `reference_id = product_id`) |
| `unknown_reference_type` | Non-blank type not in `invoice_item`, `inventory_count`, `product` (e.g. other modules’ writers) |
| `residual_other_uncategorized` | Rows still in `other_uncategorized` after the above |

## Manual operator bucket check

- **`manual_operator_entry_total`**: rows with null/empty `reference_type` and null `reference_id` per origin rules.
- **`manual_operator_unexpected_movement_type_*`**: subset whose `movement_type` is **not** one of: `purchase_in`, `manual_adjustment`, `internal_usage`, `damaged`, `count_adjustment`.

This satisfies the operational question of **null-reference rows with unexpected movement type** without requiring those rows to sit inside `other_uncategorized` (origin rule 4 would misclassify them as manual if both references are null).

## How to run

From `system/`:

```bash
php scripts/audit_product_stock_movement_classification_drift_readonly.php
php scripts/audit_product_stock_movement_classification_drift_readonly.php --json
```

Implementation: `ProductStockMovementClassificationDriftAuditService::run()`.

Exit code: **0** on success, **1** on failure.

## Examples cap

Up to **5** rows per drift reason (`EXAMPLE_CAP`), ascending `stock_movements.id`.

## See also

- `system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md` — origin buckets and writer mapping.
