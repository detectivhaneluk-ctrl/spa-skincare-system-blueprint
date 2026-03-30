# Active product operational gate (read-only audit)

## Why this exists

Accepted audits already cover **active product inventory readiness** (`ActiveProductInventoryReadinessMatrixAuditService`, WAVE-02), **consolidated stock quality** (`ProductStockQualityConsolidatedAuditService`), and **preflight advisory policy** (`ProductStockQualityPreflightAdvisoryService`). Operators still need **one** read-only per-product rollup: whether an active SKU is **operationally clear**, needs **manual review**, is **blocked** by domain/inventory or stock-health policy, or is in an **unusable / mixed** state—without mentally merging three tools.

This audit composes those layers **read-only**. It does **not** repair data, implement storefronts, publishing, or mixed-sales behavior.

## Tooling (read-only)

From `system/`:

```bash
php scripts/audit_active_product_operational_gate_readonly.php
php scripts/audit_active_product_operational_gate_readonly.php --product-id=123
php scripts/audit_active_product_operational_gate_readonly.php --json
```

- **Exit 0:** success. **Exit 1:** uncaught exception. **No** writes or repairs.

## How this composes earlier audits

| Field | Source |
|--------|--------|
| `inventory_readiness_class` | **`ActiveProductInventoryReadinessMatrixAuditService`** (WAVE-02). |
| `stock_health_issue_codes`, counts, `stock_health_max_severity` | **Attributed** from **`ProductStockQualityConsolidatedAuditService`** `component_results.*.report` **example rows only** (same capped evidence as consolidated JSON). |
| `preflight_blocking_signal` | **`true`** only when **`ProductStockQualityPreflightAdvisoryService::evaluate(current consolidated, baseline null)`** returns **`advisory_decision = hold`**. |

Preflight is evaluated **without** a baseline file (current snapshot only), matching conservative default policy.

## `operational_gate_class` definitions

Evaluation order in code:

1. **`unusable_operational_state`** — `inventory_readiness_class` is `unusable_inventory_state`, **or** (`stock_health_max_severity` is `critical` **and** `preflight_blocking_signal` is true).
2. **`mixed_gate_anomaly`** — `inventory_readiness_class` is `mixed_operational_anomaly`, **or** residual unmatched combinations, **or** (`domain_ready_but_negative_on_hand` / `domain_not_ready` with **either** attributed stock-health issues **or** preflight hold).
3. **`blocked_by_stock_health`** — `inventory_readiness_class` is `operationally_ready`, preflight **hold**, and the row is **not** already classified as unusable by the critical+hold rule above (i.e. no **critical** max severity on attributed issues for that product).
4. **`manual_review_required`** — `operationally_ready`, `stock_health_issue_count > 0`, `preflight_blocking_signal` false.
5. **`operationally_clear`** — `operationally_ready`, zero attributed issues, `preflight_blocking_signal` false.
6. **`blocked_by_domain_inventory_state`** — `domain_ready_but_negative_on_hand` or `domain_not_ready`, **no** attributed stock-health issues, **no** preflight hold.

`reason_codes` include matrix trace lines, gate rule keys, preflight decision/reason echoes, and attributed issue codes (sorted, de-duplicated).

## Operator reading order

1. `products_scanned`, `products_with_stock_health_issues_count`, `blocked_products_count`, `affected_product_ids_sample`.
2. `operational_gate_class_counts`.
3. `examples_by_operational_gate_class` (text) or full `products` with `--json` / `--product-id`.
4. Read `notes` (includes consolidated schema version and preflight decision for this run).

## Limitations

- Per-product stock-health attribution is **example-capped** exactly like consolidated component reports; products with real problems but **no** example row are shown with **zero** attributed issues until drill-down CLIs are used.
- Some global consolidated issues (e.g. counts without per-product examples) are **not** mapped to individual products.
- This does **not** replace deep ledger, reference-integrity, or drift audits.
- Bump **`audit_schema_version`** if matrix, consolidated, or preflight contracts change.

## Not in scope (explicit)

- **No** remediation, **no** stock repair, **no** storefront or public publish pipeline, **no** mixed-sales implementation — **read-only operational classification only**.
