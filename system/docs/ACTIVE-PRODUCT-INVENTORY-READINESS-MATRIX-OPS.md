# Active product inventory readiness matrix (read-only audit)

## Why this exists

Accepted work already proves **active product domain readiness** (`ActiveProductDomainReadinessAuditService`, UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-01) and **negative on-hand exposure** (`ProductNegativeOnHandExposureReportService`, INVENTORY-OPERATIONAL-DEPTH-WAVE-01) separately. Operators still need **one** read-only matrix that classifies each **active** product by **both** domain readiness and inventory/on-hand signals before any future mixed-sales or storefront decisions.

This tool composes those audits **plus** current `products.stock_quantity` (read-only). It does **not** change data, repair stock, or implement sales or public exposure.

## Tooling (read-only)

From `system/`:

```bash
php scripts/audit_active_product_inventory_readiness_matrix_readonly.php
php scripts/audit_active_product_inventory_readiness_matrix_readonly.php --product-id=123
php scripts/audit_active_product_inventory_readiness_matrix_readonly.php --json
```

- **Exit 0:** success. **Exit 1:** uncaught exception. **No** writes or repairs.

## How this composes earlier audits

| Field | Source |
|--------|--------|
| `domain_readiness_class` | `readiness_class` from **`ActiveProductDomainReadinessAuditService`** (same rules as WAVE-01). |
| `negative_on_hand_exposure_class` | `exposure_class` from **`ProductNegativeOnHandExposureReportService`** when the product appears in that report (i.e. `stock_quantity < 0` in the DB at report time); otherwise `null`. |
| `stock_quantity` | Read-only **`SELECT`** on `products.stock_quantity` for scanned active product ids. |
| `negative_on_hand` | `stock_quantity < 0` on the matrix row. |

`ProductNegativeOnHandExposureReportService::run()` is invoked **in full** each time (no product filter on that service). The matrix **filters** to active products only.

## `inventory_readiness_class` definitions

Precedence in implementation:

1. **`unusable_inventory_state`** — `domain_readiness_class` is `unusable_catalog_state`, **or** `negative_on_hand_exposure_class` is `suspicious_policy_breach_history` (from the exposure report).
2. **`operationally_ready`** — `domain_readiness_class` is `domain_ready`; `stock_quantity >= 0`; **no** row in the negative exposure report for that product (consistent with non-negative on-hand).
3. **`domain_ready_but_negative_on_hand`** — `domain_readiness_class` is `domain_ready`; `stock_quantity < 0`; a negative exposure report row exists for that product.
4. **`domain_not_ready`** — `domain_readiness_class` is one of `identity_incomplete`, `reference_risk`, `taxonomy_cleanup_needed`, `normalization_needed`; `stock_quantity >= 0`; and no stronger class above applied.
5. **`mixed_operational_anomaly`** — `domain_readiness_class` is `mixed_domain_anomaly`; **or** a domain-not-ready class coexists with negative on-hand; **or** residual inconsistencies (e.g. negative stock without an exposure report row, or non-negative stock with an exposure row).
6. Any remaining unmatched combination rolls up to **`mixed_operational_anomaly`**.

`reason_codes` merge **exact** codes from the domain audit row, **exact** codes from the exposure report row when present, plus matrix trace keys (`inventory_readiness_rule:*`, `domain_readiness_class:*`, `negative_on_hand:*`, `stock_quantity:*`, `negative_on_hand_exposure_class:*`, and `composed_from:*`). The list is sorted and de-duplicated for stable JSON.

## Operator reading order

1. `products_scanned`, `affected_products_count`, `affected_product_ids_sample`.
2. `inventory_readiness_class_counts`.
3. `examples_by_inventory_readiness_class` (text mode) or full `products` with `--json` / `--product-id`.
4. Read `notes` and per-row `reason_codes`.

## Limitations

- Does **not** prove invoice eligibility, pricing, VAT, or sellable read-model consumers.
- Does **not** replace consolidated stock-health, ledger reconciliation, or orphan-FK tooling.
- Depends on **`ActiveProductDomainReadinessAuditService`** and **`ProductNegativeOnHandExposureReportService`** semantics; bump **`audit_schema_version`** in the matrix service if those contracts change.
- Exposure classification is **conservative** and movement-history-based; it is not a policy engine and does not mutate stock.

## Not in scope (explicit)

- **No** remediation: this audit does not repair catalog, taxonomy, or stock.
- **No** storefront, **no** public APIs, **no** mixed-sales basket or checkout behavior — **stored-fact read model only**.
