# Stock movement origin classification — operations

**Task:** `PRODUCTS-MOVEMENT-ORIGIN-CLASSIFICATION-REPORT-01`  
**Scope:** Read-only report: every row in `stock_movements` is assigned to exactly one **origin** bucket using the rules below (evaluated in order). **No** writes.

## Purpose

- Give operators and auditors a **single rollup** of ledger rows by *practical* provenance before any future per-branch stock model work.
- Make explicit that `movement_type` alone is not enough: **`reference_type` / `reference_id`** (when present) are the canonical distinction between system-generated links (invoice lines, counts, product create) and operator form entry.

## Exact rules (first match wins)

Rules mirror current application writers as of this wave:

| Order | Origin key | Predicate | Code reference |
|------:|------------|-------------|----------------|
| 1 | `invoice_settlement` | `reference_type = 'invoice_item'` **and** `movement_type IN ('sale', 'sale_reversal')` | `InvoiceStockSettlementService::syncProductStockWithInvoiceSettlement` |
| 2 | `inventory_count_adjustment` | `reference_type = 'inventory_count'` **and** `movement_type = 'count_adjustment'` | `InventoryCountService::create` (apply adjustment path) |
| 3 | `product_opening_stock` | `movement_type = 'manual_adjustment'` **and** `reference_type = 'product'` **and** `reference_id = product_id` | `ProductService::create` (`initial_quantity` → opening movement) |
| 4 | `manual_operator_entry` | `reference_type` is NULL or empty string **and** `reference_id` is NULL | `StockMovementService::createManual` (forces both references null for allowed manual types) |
| 5 | `other_uncategorized` | All remaining rows | Legacy SQL, partial imports, malformed pairings, or future writers not yet classified here |

## Important semantics

- **`manual_adjustment` is dual-use:** opening stock on product create uses **`product` + id** (rule 3); staff form adjustments use **null references** (rule 4). Do not infer origin from `movement_type` alone.
- **Invoice settlement** is only classified as such when **both** the reference and movement type match rule 1. A row with `reference_type = 'invoice_item'` but an unexpected `movement_type` falls into **`other_uncategorized`** (data anomaly).
- **Operator form** may record `purchase_in`, `manual_adjustment`, `internal_usage`, `damaged`, or `count_adjustment` — all with **null** references when created through `createManual`. A `count_adjustment` without `inventory_count` reference is **not** rule 2 and lands in **`other_uncategorized`**.
- Rows tied to **soft-deleted** products are still counted in the main buckets; the report field **`movements_on_deleted_or_missing_product`** counts movements whose `product_id` is missing or on a **soft-deleted** product (`products.deleted_at IS NOT NULL`).
- **Drill-down** for `other_uncategorized`: `system/docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md` and `php scripts/audit_product_stock_movement_classification_drift_readonly.php`.
- **Reference integrity** (orphan targets / bad pairs): `system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md` and `php scripts/audit_product_stock_movement_reference_integrity_readonly.php`.

## How to run

From `system/`:

```bash
php scripts/report_product_stock_movement_origin_classification_readonly.php
php scripts/report_product_stock_movement_origin_classification_readonly.php --json
```

Implementation: `ProductStockMovementOriginClassificationReportService::run()`.

Exit code: **0** on success, **1** on failure.

## Code references

- `system/modules/inventory/services/ProductStockMovementOriginClassificationReportService.php`
- `system/scripts/report_product_stock_movement_origin_classification_readonly.php`
- `system/modules/inventory/services/ProductStockMovementClassificationDriftAuditService.php` + `system/scripts/audit_product_stock_movement_classification_drift_readonly.php`
- `system/modules/inventory/services/ProductStockMovementReferenceIntegrityAuditService.php` + `system/scripts/audit_product_stock_movement_reference_integrity_readonly.php`
