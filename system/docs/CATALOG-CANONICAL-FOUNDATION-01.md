# CATALOG-CANONICAL-FOUNDATION-01

**Task:** Backend-only foundation for a single future sellable catalog truth. **No** mixed checkout, **no** POS depth, **no** payment/register/VAT/membership/notification changes.

---

## 1. What is newly canonical

| Area | Canonical artifact | Role |
|------|-------------------|------|
| Service taxonomy shape | `service_categories.parent_id` (nullable) | Optional tree; `NULL` = root (same as pre-migration flat rows). |
| Product taxonomy tables | `product_categories`, `product_brands` | Normalized hierarchy (categories) and names (brands), branch-aware like other catalog entities (`branch_id` NULL = global). |
| Product row linkage | `products.product_category_id`, `products.product_brand_id` | Optional FKs to the tables above. |
| Unified **read** contract | `Core\Contracts\CatalogSellableReadModelProvider` | `listActiveSellableSlice(?branchId, limit, offset)` returns normalized rows: `kind` (`service` \| `product`), `id`, `name`, `catalog_code` (service: `SVC:{id}`; product: `sku`), `branch_id`, `is_active`, `unit_price`. Implementation: `Modules\Sales\Providers\CatalogSellableReadModelProviderImpl` (merges `ServiceRepository::list` + `ProductRepository::listActiveForUnifiedCatalog`). |

**Migration:** `system/data/migrations/085_catalog_canonical_foundation.sql`.

---

## 2. What remains legacy-compatible (unchanged authority for existing flows)

| Artifact | Status |
|----------|--------|
| `products.category`, `products.brand` (VARCHAR) | Still present and used by existing inventory/product code paths; **not** removed or rewritten in this task. New FK columns are nullable; no destructive data migration. |
| `services` / `ServiceRepository` / booking & sales service lines | Unchanged; services remain the operational row for appointments and invoices. |
| Invoice lines, payments, register, stock settlement | **Not** wired to the new read model or taxonomy tables in this task. |
| Mixed cart / single basket | **Out of scope** — no activation. |

---

## 3. Safety rules implemented (backend)

- **Service categories:** `ServiceCategoryService` validates parent assignment (existence, no cycles, branch/global rules: global parent allowed for any child; branch parent must match child branch; global child cannot use a branch-scoped parent). `ServiceCategoryRepository` assists with ancestor checks.
- **Product taxonomy:** Repositories `ProductCategoryRepository`, `ProductBrandRepository` are list/find helpers only; no automatic migration from legacy strings.

---

## 4. Next task (retail line operationalization — suggested sequencing)

**Shipped:** **PRODUCT-TAXONOMY-HTTP-OPERATIONALIZATION-01** — see **`system/docs/PRODUCT-TAXONOMY-HTTP-OPERATIONALIZATION-01.md`**.

**Recommended next:** **backfill / alignment** from legacy `products.category` / `products.brand` into normalized rows + optional report filters (still no checkout).

**Then:** **SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01** (roadmap §5.C Phase 3.2).

**Later (explicit separate tasks):** **UNIFIED-CATALOG-DOMAIN-TRUTH-CUT-01** (relationships, visibility), **MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-01** (invoice line discipline), **NATIVE-STOREFRONT-CATALOG-EXPOSURE-FOUNDATION-01** (public APIs).

---

## 5. Files touched by this task (reference)

- `system/data/migrations/085_catalog_canonical_foundation.sql`
- `system/data/full_project_schema.sql` (kept aligned)
- `system/core/contracts/CatalogSellableReadModelProvider.php`
- `system/modules/sales/providers/CatalogSellableReadModelProviderImpl.php`
- `system/modules/inventory/repositories/ProductRepository.php` (`listActiveForUnifiedCatalog`, normalize)
- `system/modules/inventory/repositories/ProductCategoryRepository.php`
- `system/modules/inventory/repositories/ProductBrandRepository.php`
- `system/modules/services-resources/repositories/ServiceCategoryRepository.php`
- `system/modules/services-resources/services/ServiceCategoryService.php`
- `system/modules/services-resources/controllers/ServiceCategoryController.php` (optional `parent_id` in POST)
- `system/modules/bootstrap.php` (DI)
