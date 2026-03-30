# Legacy vs normalized product taxonomy coherence (read-only audit)

## Why this exists

`ProductCatalogReferenceCoverageAuditService` (WAVE-01) proves whether normalized FKs exist, are live, and satisfy branch assignability. It does **not** compare legacy string columns `products.category` / `products.brand` to normalized taxonomy names.

This audit closes that gap with one deterministic read-only payload: **per axis**, whether legacy text, normalized FK, both, or neither line up (trim + case-insensitive equality), and whether normalized references are unusable.

## Tooling (read-only)

From `system/`:

```bash
php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php
php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php --product-id=123
php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php --json
```

- **Exit 0:** success. **Exit 1:** uncaught exception. **No** writes or repairs.

## Comparison limits

- **Only** `TRIM` on both sides, then **case-insensitive** comparison (`strcasecmp`-equivalent).
- No Unicode normalization, no fuzzy matching, no synonym tables, no automatic slug or canonical-name inference.

## Axis status (`category_axis_status` / `brand_axis_status`)

Evaluated in priority order (first match wins):

| Status | Meaning |
|--------|---------|
| `normalized_reference_unusable` | FK is non-null but the taxonomy row is missing, soft-deleted (`deleted_at`), or fails `ProductTaxonomyAssignabilityService` branch pairing for this product. |
| `blank_on_both` | No legacy text (after trim) and no normalized FK. |
| `legacy_only` | Legacy text present, normalized FK null. |
| `normalized_only` | Usable normalized FK, legacy text blank. |
| `aligned` | Usable FK, legacy text present, trimmed names equal case-insensitively. |
| `text_mismatch` | Usable FK, legacy text present, names differ under the comparison rules. |

## Coherence class (`coherence_class`)

| Class | Meaning |
|-------|---------|
| `taxonomy_coherent` | Both axes `aligned`. |
| `dual_blank_taxonomy` | Both axes `blank_on_both`. |
| `legacy_cleanup_only` | No unusable reference, no `text_mismatch`, no `legacy_only`; each axis is only `aligned`, `normalized_only`, or `blank_on_both`; not dual-blank; at least one axis is `normalized_only` (legacy blank where FK exists). |
| `normalization_missing_only` | At least one axis `legacy_only`, no unusable, no mismatch, and not the mixed pair `normalized_only` + `legacy_only` across the two axes. |
| `legacy_normalized_mismatch` | At least one axis `text_mismatch`, no unusable, and no axis combination that implies a different primary story (see service for mixed triggers). |
| `unusable_normalized_reference` | At least one axis unusable; combinations with mismatch / `legacy_only` / cross-axis `normalized_only`+`legacy_only` roll up to `mixed_taxonomy_anomaly` instead. |
| `mixed_taxonomy_anomaly` | Residual combinations (e.g. `legacy_only` with `normalized_only`, mismatch with blank axis, unusable paired with other failures). `reason_codes` include `category_axis_*` / `brand_axis_*` slugs. |

## Operator reading order

1. `products_scanned`, `affected_products_count`, `affected_product_ids_sample`.
2. `coherence_class_counts`, then `category_axis_status_counts` / `brand_axis_status_counts`.
3. `examples_by_coherence_class` or full `products` in JSON / `--product-id`.
4. Read `notes` and per-row `reason_codes`.

## How this differs from other tools

| Tool | Role |
|------|------|
| **Orphan FK audit** | Missing/soft-deleted FK targets and optional clear — not legacy string coherence. |
| **Catalog reference coverage (WAVE-01)** | FK presence + branch assignability for “catalog-safe” normalized refs — no legacy string compare. |
| **Legacy taxonomy backfill / repair** | **Writes** (or apply steps) to align data — **out of scope** for this read-only audit. |

## Read-only guarantee

This service performs **SELECT**-only access. It does not backfill, clear FKs, or change products, categories, or brands.
