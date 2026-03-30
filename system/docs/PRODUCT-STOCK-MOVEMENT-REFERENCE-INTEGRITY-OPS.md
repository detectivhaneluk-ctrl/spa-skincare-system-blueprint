# Stock movement reference integrity — operations

**Task:** `PRODUCTS-STOCK-MOVEMENT-REFERENCE-INTEGRITY-AUDIT-01`  
**Scope:** Read-only audit of `stock_movements.reference_type` / `reference_id` against expected targets. **No** writes.

## Purpose

Expose **orphan references** and **half-set reference pairs** that break the mental model in `InvoiceStockSettlementService`, `InventoryCountService`, `ProductService::create` (opening stock), and `StockMovementService::createManual` (forced null pair).

## Checks (counts may overlap)

Each anomaly bucket is a **separate** `COUNT(*)` over `stock_movements` with its own predicate. **The same row can match more than one predicate** (for example orphan `product_id` **and** a malformed `reference_*` pair). Therefore **the sum of `counts_by_anomaly` values may exceed `total_movements`**; treat buckets as independent signals, not a partition.

| Anomaly key | Meaning |
|-------------|---------|
| `invoice_item_reference_missing_row` | `reference_type = 'invoice_item'` and `reference_id` set, but no `invoice_items.id` row |
| `inventory_count_reference_missing_row` | `reference_type = 'inventory_count'` and `reference_id` set, but no `inventory_counts.id` row |
| `movement_product_id_missing_row` | `product_id` does not resolve to `products.id` (should be impossible with FK `ON DELETE RESTRICT`; flags imports / FK-disabled environments) |
| `product_reference_target_missing_row` | `reference_type = 'product'` and `reference_id` set, but no `products.id` row |
| `reference_id_set_reference_type_missing` | `reference_id` not null while `reference_type` is null or blank (after trim) |
| `reference_type_set_reference_id_missing` | `reference_type` non-blank while `reference_id` is null |

Writer contracts (inventory layer): `system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md`, `system/modules/inventory/README.md`.

**Note:** A movement with `reference_type = 'invoice_item'` and `movement_type IN ('sale','sale_reversal')` is classified as **`invoice_settlement`** even when the `invoice_items` row is missing — origin classification does not join targets. Use this audit for **referential** truth; use `audit_product_stock_movement_classification_drift_readonly.php` for **`other_uncategorized`** reasons.

## How to run

From `system/`:

```bash
php scripts/audit_product_stock_movement_reference_integrity_readonly.php
php scripts/audit_product_stock_movement_reference_integrity_readonly.php --json
```

Implementation: `ProductStockMovementReferenceIntegrityAuditService::run()`.

Exit code: **0** on success, **1** on failure.

## Examples cap

Up to **5** rows per anomaly type (`EXAMPLE_CAP`), ascending `stock_movements.id`.
