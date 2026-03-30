# Product catalog reference coverage (read-only audit)

## Why this exists

Stock-health and inventory operational-depth audits do not answer one focused question: **for each live product, are normalized `product_category_id` and `product_brand_id` present, pointing at live taxonomy rows, and allowed under current branch assignability rules?**

This tool produces a **single deterministic payload** so operators can separate **catalog-reference–safe** products from **missing**, **soft-deleted reference**, **branch-contract–risky**, or **mixed** cases—without changing products, categories, or brands.

## Tooling (read-only)

From `system/`:

```bash
php scripts/audit_product_catalog_reference_coverage_readonly.php
php scripts/audit_product_catalog_reference_coverage_readonly.php --product-id=123
php scripts/audit_product_catalog_reference_coverage_readonly.php --json
```

- **Exit 0:** run completed successfully.
- **Exit 1:** uncaught exception.
- **No** writes, **no** repairs, **no** schema changes.

JSON output includes every scanned product under `products` (ordered by `product_id`). Text mode prints rollups and capped examples.

## `coverage_class` definitions

| `coverage_class` | When it applies |
|------------------|-----------------|
| `catalog_reference_ok` | Both normalized FKs are non-null, target rows exist, taxonomy rows are not soft-deleted, and both satisfy `ProductTaxonomyAssignabilityService` rules (global product ⇒ global taxonomy only; branch product ⇒ global or same-branch taxonomy). |
| `missing_category_reference` | `product_category_id` is null, **or** FK set but no `product_categories` row exists (orphan). Brand side has **no** issue. |
| `inactive_category_reference` | Category FK resolves to a row with `deleted_at` set (soft-deleted taxonomy). Brand side has **no** issue. |
| `category_branch_contract_risk` | Category row is live but branch pairing fails assignability (e.g. global product with branch-scoped category, or category scoped to a different branch). Brand side has **no** issue. |
| `missing_brand_reference` | Same as missing category, for `product_brand_id` / `product_brands`, category side clean. |
| `inactive_brand_reference` | Brand row soft-deleted; category side clean. |
| `brand_branch_contract_risk` | Brand row live but assignability fails; category side clean. |
| `mixed_reference_anomaly` | **Both** category and brand axes have at least one issue (any combination). `reason_codes` lists both axes’ reasons. |

Axis priority when classifying a single axis internally: **missing → inactive → branch_risk** (first failing condition wins for that axis).

## Operator reading order

1. `products_scanned`, `affected_products_count`, `affected_product_ids_sample`.
2. `coverage_class_counts` — focus on anything other than `catalog_reference_ok`.
3. `examples_by_coverage_class` (text) or full `products` in JSON / `--product-id` filter.
4. Read `notes` and per-product `reason_codes` for conservative wording.

## Limitations

- **Legacy strings** `products.category` / `products.brand` are **not** evaluated; only normalized FKs.
- **No `is_active` on taxonomy tables** in current schema; “inactive” means **soft-deleted taxonomy** (`deleted_at`), not a separate flag.
- Does **not** validate category tree cycles, duplicate-name clusters, or stock movement references—those are other audits.
- Does **not** prove sellable/catalog UX or storefront readiness.

## How this differs from older stock / reference-integrity audits

| Tool | Focus |
|------|--------|
| **This audit** | Per **product**: normalized category + brand **coverage** and **branch assignability** in one payload. |
| **Stock movement reference integrity** | `stock_movements` `reference_type` / `reference_id` vs invoices, counts, products—not product taxonomy FKs. |
| **Product taxonomy orphan FK audit** | Orphan / duplicate-group metrics and optional clear of bad FKs—**not** the full “ok vs missing vs inactive vs branch risk vs mixed” coverage contract. |
| **Stock quality consolidated** | Composes ledger, movement origin, drift, etc.—**not** dedicated taxonomy coverage semantics. |

## Read-only guarantee

This CLI and service perform **SELECT**-only access. They do **not** clear FKs, retire taxonomy, or change assignability enforcement in application code.
