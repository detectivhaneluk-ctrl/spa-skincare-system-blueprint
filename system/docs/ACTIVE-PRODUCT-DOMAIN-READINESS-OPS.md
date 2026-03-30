# Active product domain readiness (read-only audit)

## Why this exists

Accepted audits already prove **normalized FK coverage** (`ProductCatalogReferenceCoverageAuditService`, catalog tail WAVE-01) and **legacy vs normalized taxonomy coherence** (`ProductLegacyNormalizedTaxonomyCoherenceAuditService`, WAVE-02) separately. Operators still lack **one** read-only rollup for **`is_active = 1` products** that answers: “Is this product domain-ready on current stored facts (identity + coverage + coherence) for future catalog/sales exposure work?”

This tool composes those layers **without** changing data and **without** implementing storefront, public APIs, or mixed-sales behavior.

## Tooling (read-only)

From `system/`:

```bash
php scripts/audit_active_product_domain_readiness_readonly.php
php scripts/audit_active_product_domain_readiness_readonly.php --product-id=123
php scripts/audit_active_product_domain_readiness_readonly.php --json
```

- **Exit 0:** success. **Exit 1:** uncaught exception. **No** writes or repairs.

## Scope

- **Only** rows with `deleted_at IS NULL` **and** `is_active = 1`.
- If `--product-id` points at an inactive or missing product, **`products_scanned`** is **0** (empty `products`).

## How this composes earlier audits

| Field | Source |
|--------|--------|
| `category_reference_coverage_class` | `coverage_class` from **`ProductCatalogReferenceCoverageAuditService`** (same rules as WAVE-01). |
| `taxonomy_coherence_class` | `coherence_class` from **`ProductLegacyNormalizedTaxonomyCoherenceAuditService`** (same rules as WAVE-02). |
| `identity_present` | This audit: `TRIM(sku) !== ''` **and** `TRIM(name) !== ''`. |

Underlying services are invoked in-process; this audit **filters** to active products and applies **`readiness_class`** mapping only.

## `readiness_class` definitions

Evaluation order in code: **mixed_domain_anomaly** checks first, then **unusable_catalog_state**, then **domain_ready**, **normalization_needed**, **taxonomy_cleanup_needed**, **reference_risk**, **identity_incomplete**, then residual **mixed_domain_anomaly**.

| Class | Meaning |
|-------|---------|
| `domain_ready` | `identity_present`; coverage `catalog_reference_ok`; coherence `taxonomy_coherent`. |
| `identity_incomplete` | SKU or name blank after trim; no stronger class matched. |
| `reference_risk` | Coverage is branch-contract risk or `mixed_reference_anomaly`, and coherence `taxonomy_coherent`. |
| `taxonomy_cleanup_needed` | Coverage `catalog_reference_ok`; coherence `legacy_cleanup_only` or `legacy_normalized_mismatch`. |
| `normalization_needed` | Coverage `catalog_reference_ok`; coherence `normalization_missing_only`. |
| `unusable_catalog_state` | Missing/inactive coverage class, or coherence `unusable_normalized_reference`, without mixed-domain precedence. |
| `mixed_domain_anomaly` | Cross-family combinations (e.g. OK coverage + mixed coherence; reference risk + non-coherent taxonomy; unusable coverage + cleanup/normalization/mixed coherence) or residual unmatched pairs. |

`reason_codes` include `readiness_rule:*`, `coverage_class:*`, `taxonomy_coherence_class:*`, and `identity_present:*` for traceability.

## Operator reading order

1. `products_scanned`, `affected_products_count`, `affected_product_ids_sample`.
2. `readiness_class_counts`.
3. `examples_by_readiness_class` (text) or full `products` in JSON / `--product-id`.
4. Read `notes` and per-row `reason_codes`.

## Limitations

- Does **not** validate pricing, stock, VAT, invoice eligibility, or sellable read-model consumers.
- Does **not** replace consolidated stock-health or orphan-FK tooling.
- Composition assumes WAVE-01/WAVE-02 semantics remain stable; bump **`audit_schema_version`** if those contracts change.

## Not in scope (explicit)

- **No** public product exposure, **no** storefront implementation, **no** mixed-sales basket logic — this is **inventory-only stored-fact** classification.
- **No** remediation: the CLI does not repair FKs, legacy strings, or branch assignments.
