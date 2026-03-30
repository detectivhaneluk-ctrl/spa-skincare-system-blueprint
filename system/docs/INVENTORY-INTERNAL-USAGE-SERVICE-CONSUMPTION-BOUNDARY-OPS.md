# Internal usage — service consumption boundary (read-only audit)

## Why this exists

Operators already have invoice settlement drilldown, negative-on-hand exposure, and refund/return visibility. They still lack a **single read-only view** that states what `internal_usage` stock movements mean **today** in terms of stored `reference_type` / `reference_id`, and where those rows sit **outside** the contracts enforced by current inventory writers.

This audit answers: “Is this row plain manual internal usage, a recognizable product-reference shape, or an unexpected / malformed linkage?” It does **not** claim that a performed **service** consumed retail stock unless future data models explicitly store that fact.

## Tooling (read-only)

From `system/`:

```bash
php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php
php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php --product-id=123
php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php --json
```

- **Exit 0:** run completed successfully.
- **Exit 1:** uncaught exception (e.g. DB connectivity).
- **No** schema changes, **no** migrations, **no** writes, **no** repairs.

JSON output includes every scanned `internal_usage` row under `movements` (stable order: `movement_id` ascending). Text mode prints rollups and capped examples only.

## `boundary_class` definitions (deterministic; first matching rule wins)

| `boundary_class` | Meaning |
|------------------|---------|
| `malformed_reference_pair` | `reference_id` without a non-empty `reference_type`, or non-empty `reference_type` without `reference_id` (same malformed pair idea as reference-integrity / drift audits). |
| `missing_reference_target` | Pair is well-formed for `invoice_item`, `inventory_count`, or `product`, but the referenced row does not exist (orphan reference target). |
| `invoice_item_linked_internal_usage_unexpected` | `reference_type = invoice_item` and the invoice line row exists. Current settlement writers use `sale` / `sale_reversal` with `invoice_item`; `internal_usage` here is **unsupported / anomalous** for that reference story. |
| `inventory_count_linked_internal_usage_unexpected` | `reference_type = inventory_count` and the count row exists. Count adjustments use `count_adjustment` with that reference; `internal_usage` is **unexpected**. |
| `product_self_reference_internal_usage` | `reference_type = product`, `reference_id = product_id`, referenced product row exists. Opening stock on product create uses **`manual_adjustment`** with this self-reference pattern (`ProductService`); `internal_usage` with the same shape is a **stored atypical** shape, **not** proof of service consumption. |
| `product_reference_mismatch` | `reference_type = product`, referenced product exists, but `reference_id ≠ product_id` (does not match the opening-stock self-reference contract). |
| `unknown_reference_type_internal_usage` | Non-empty `reference_type` not in the inventory writer vocabulary (`invoice_item`, `inventory_count`, `product`), or a residual unclassified shape. |
| `manual_operator_internal_usage` | Both `reference_type` and `reference_id` are null/empty — matches `StockMovementService::createManual`, which forces null references for operator form entries. |

## Operator reading order

1. Read `total_internal_usage_movements`, `unlinked_manual_internal_usage_count`, and `anomalous_internal_usage_count`.
2. Scan `counts_by_boundary_class` for unexpected buckets (`*_unexpected`, `malformed_*`, `missing_*`, `unknown_*`, `product_reference_mismatch`, `product_self_reference_internal_usage`).
3. Use `examples_by_boundary_class` (text) or `movements` / filtered `--product-id` (JSON) for drill-down.
4. Read aggregate `notes` and per-row `reason_codes` for the conservative rationale (no service semantics invented).

## Explicit limitation: no proven service-level product consumption

The current system **does not** persist a dedicated “service X consumed product Y” link on `stock_movements` for `internal_usage`. This audit **does not** infer that from appointments, packages, or services. Any future service-consumption design must add explicit stored facts before operators can treat rows as service-backed consumption.

## How this differs from other read-only tools

| Area | This audit | Elsewhere |
|------|------------|-----------|
| **Stock movement origin classification** | Only `internal_usage`; classifies reference shape vs **writer contracts** for that movement type. | `report_product_stock_movement_origin_classification_readonly.php` — all movement types, origin buckets (`invoice_settlement`, `manual_operator_entry`, etc.). |
| **Reference integrity / drift** | Scoped slice: internal_usage + boundary meaning for service-consumption **honesty**. | `audit_product_stock_movement_reference_integrity_readonly.php` / `audit_product_stock_movement_classification_drift_readonly.php` — all movements, anomalies / `other_uncategorized` drill-down. |
| **Negative on-hand exposure** | Does not evaluate on-hand sign or policy breach history. | `report_product_negative_on_hand_exposure_readonly.php` — products with `stock_quantity < 0` and movement context. |

## Contract version

Payload field `audit_schema_version` is bumped only when the aggregate or per-row shape changes in a breaking way.
