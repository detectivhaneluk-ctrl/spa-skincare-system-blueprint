# PRODUCT-TAXONOMY-HTTP-OPERATIONALIZATION-01

**Status: accepted** together with **`PRODUCT-TAXONOMY-INTEGRITY-HARDENING-01`** (ZIP audit). HTTP operationalization and integrity hardening (branch immutability under branch context, edit-form branch lock, soft-delete FK detach on products) are **done**.

**Follow-up shipped in `PRODUCT-TAXONOMY-BACKFILL-AND-REPORT-ALIGNMENT-01`:** idempotent legacy → normalized **backfill** CLI (`system/scripts/backfill_product_taxonomy_from_legacy.php`) plus inventory **product list/show** display and optional **list filters** that prefer resolved normalized labels with legacy fallback. **Not in scope there:** sales checkout, POS, invoice lines, mixed basket, storefront, VARCHAR removal.

**Operational integrity after backfill (`PRODUCT-TAXONOMY-ORPHAN-FK-AUDIT-AND-REPAIR-01`):** CLI **`system/scripts/audit_product_taxonomy_orphan_fks.php`** — default **dry-run** (audit + duplicate-name diagnostics only); **`--apply`** NULLs **`product_category_id` / `product_brand_id`** only when the referenced taxonomy row is **missing** or **soft-deleted** (legacy VARCHAR untouched; no auto-relink). Run this **before** wider taxonomy adoption where drift is possible. Backfill + list alignment are **not** the end of the integrity rollout.

**Duplicate trimmed-name sealing (`PRODUCT-TAXONOMY-DUPLICATE-NAME-INTEGRITY-HARDENING-01`):** Orphan-FK **diagnostics** alone do not stop new duplicates. **`ProductCategoryService` / `ProductBrandService`** now reject create/update when another **live** row in the same **`branch_id` scope** (NULL = global) already has the same **`TRIM(name)`** (`DomainException` with explicit copy). Legacy **backfill** resolves links to the **lowest-id** canonical row per scope + trimmed name and uses per-product transactions with an in-transaction **re-select** before inserting a category row, plus brand **duplicate-key** fallback to re-resolve canonical — this **reduces** duplicate inserts under concurrency but does **not** guarantee absence of duplicate live rows without a DB **unique** constraint on the same semantics. **Existing** duplicate live rows still need operational cleanup for product FK consistency. DB **unique** index on trimmed name is still **out of scope**.

**Duplicate canonical product FK relink (`PRODUCT-TAXONOMY-DUPLICATE-CANONICAL-RELINK-01`):** CLI **`system/scripts/relink_product_taxonomy_duplicate_canonical.php`** — default **dry-run**; **`--apply`** moves **`products.product_category_id` / `product_brand_id`** from **noncanonical** duplicate live taxonomy ids (same scope + **`TRIM(name)`**, canonical = **lowest id**) to that canonical id only. **Does not** soft-delete, delete, or rename taxonomy rows; **does not** change legacy **`products.category` / `products.brand`**. Use after duplicate prevention + optional orphan repair when historical duplicate live rows remain.

**Noncanonical duplicate retirement — early pass (`PRODUCT-TAXONOMY-DUPLICATE-NONCANONICAL-RETIRE-01`):** After **product** duplicate canonical FK relink, optional CLI **`system/scripts/retire_product_taxonomy_duplicate_noncanonical.php`** — default **dry-run**; **`--apply`** **soft-deletes** only **noncanonical** live duplicate rows when **active product references = 0** and (categories) **live child categories with `parent_id` = that row = 0**. Canonical row **never** soft-deleted; **no** name rewrite, **no** forced parent rewiring, **no** legacy VARCHAR change. Duplicate **category** rows that still have **live children** (often because children pointed at a noncanonical duplicate parent) are **skipped** here — they become eligible only after the category parent/tree sequence below.

**Noncanonical duplicate retirement — post–parent/tree finalization (`PRODUCT-TAXONOMY-DUPLICATE-NONCANONICAL-POST-TREE-FINALIZATION-01`):** **Last** taxonomy retire step in the safe chain. CLI **`system/scripts/finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php`** — same duplicate semantics (live scope + **`TRIM(name)`**, canonical = lowest id), same safety rules as the early retire CLI, default **dry-run**, **`--apply`** with **per-row transactions** and in-transaction rechecks (row still live, still noncanonical, duplicate group still exists, product refs still zero, category live-child count still zero). Adds explicit counts including **duplicate groups remaining** after the pass (category, brand, and a printed total). Run only **after** duplicate-parent canonical relink, tree integrity safe repair, and cycle-cluster safe break (and optional consolidated recheck).

**Duplicate category parent canonical relink (`PRODUCT-CATEGORY-DUPLICATE-PARENT-CANONICAL-RELINK-01`):** Safe retire still skips noncanonical duplicate **category** rows that are **parents**. CLI **`system/scripts/relink_product_category_duplicate_parent_canonical.php`** — default **dry-run**; **`--apply`** sets **`product_categories.parent_id`** from each noncanonical duplicate id to the **canonical** id (same scope + **`TRIM(name)`**, lowest id) for **live** children only. **Cycle-safe** (skips when the move would close a loop). **Does not** soft-delete categories, change **products**, or touch brands.

**Full live category tree integrity (`PRODUCT-CATEGORY-TREE-INTEGRITY-AUDIT-AND-SAFE-REPAIR-01`):** Duplicate-parent relink does not fix **all** tree issues. CLI **`system/scripts/audit_product_category_tree_integrity.php`** scans **every** live **`product_categories`** row: missing/deleted parents, self-parent, **scope-invalid** parent (same rules as **`ProductCategoryService`** / §2), and **multi-node cycle** detection via **`ancestorChainContainsId`**. **`--apply`** only **`NULL`s `parent_id`** for the **safe** cases (not cycles). **Cycle-risk** rows are **report-only**; operators must fix manually. **No** product/brand changes, **no** category delete/merge.

**Multi-node cycle clusters + fix plan (`PRODUCT-CATEGORY-TREE-CYCLE-CLUSTER-AUDIT-AND-FIXPLAN-01`):** Safe **`parent_id`** clears do not resolve **multi-node** cycles by themselves. CLI **`system/scripts/audit_product_category_tree_cycle_clusters.php`** is **read-only**: Tarjan SCC on the live **child → parent** graph (self-parent edges omitted), **`over_cap_ancestor_walk_count`** aligned with the **64**-step ancestor guard, capped **cluster examples**, and a deterministic recommendation (**highest id** in the cluster → manual **`SET parent_id = NULL`**). **No** writes, **no** `--apply`.

**Cycle-cluster safe break apply (`PRODUCT-CATEGORY-TREE-CYCLE-CLUSTER-SAFE-BREAK-APPLY-01`):** CLI **`system/scripts/apply_product_category_tree_cycle_cluster_safe_break.php`** reuses **`ProductCategoryTreeCycleClusterAuditService::discoverLiveMultiNodeCycleClusters()`**; default **dry-run** lists **`would_apply`** per cluster; **`--apply`** runs **per-cluster transactions** with **`clearParentIdForLiveCategoryIfParentMatches`** on the **max member id** only. Summary includes **`clusters_remaining_after_apply`** (re-audit in memory after apply). Still **not** full tree normalization—no other parent rewiring or deletes.

**Post-repair consolidated recheck (`PRODUCT-CATEGORY-TREE-POST-REPAIR-CONSOLIDATED-RECHECK-01`):** After duplicate-parent relink, integrity **`--apply`**, cycle audit, and optional safe-break apply, run **`system/scripts/audit_product_category_tree_post_repair_consolidated.php`** (read-only; optional **`--json`**). **`ProductCategoryTreePostRepairConsolidatedRecheckService`** composes **`ProductCategoryTreeIntegrityAuditService::run(false)`** and **`ProductCategoryTreeCycleClusterAuditService::run()`** into one summary: invalid parents, **`cycle_risk_count`**, multi-node **SCC** counts, **`over_cap_ancestor_walk_count`**, **`safe_repair_candidates_remaining`**, and capped **anomaly** + **cycle_cluster** examples. Use as the operational checkpoint before wider taxonomy rollout; anything still flagged here is a remaining blocker (until addressed by the scoped CLIs or data fixes).

**Recommended safe cleanup order (taxonomy duplicate + category tree):** (1) optional **`relink_product_taxonomy_duplicate_canonical.php`** (product FKs); (2) optional **early** **`retire_product_taxonomy_duplicate_noncanonical.php`**; (3) **`relink_product_category_duplicate_parent_canonical.php`**; (4) **`audit_product_category_tree_integrity.php`** ( **`--apply`** safe clears as needed); (5) **`audit_product_category_tree_cycle_clusters.php`** (read-only plan) / **`apply_product_category_tree_cycle_cluster_safe_break.php`** as needed; (6) **`audit_product_category_tree_post_repair_consolidated.php`** (checkpoint); (7) **`finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php`** (final noncanonical retire pass). Orphan FK audit/repair and legacy backfill (see **Deferred** below) depend on your data — typically backfill before relink; orphan repair when FKs point at missing/deleted taxonomy.

**Scope (this doc / original HTTP task):** Backend HTTP CRUD for normalized **`product_categories`** and **`product_brands`**, plus optional **`products.product_category_id` / `products.product_brand_id`** on create/update with branch-safe validation. **No** mixed checkout, POS depth, storefront expansion, VAT/payments/register/memberships/notifications changes.

---

## 1. Canonical product taxonomy truth (after this task)

| Layer | Authority |
|-------|-----------|
| **`product_categories`** | Hierarchical rows (`parent_id`, `branch_id` NULL = global). Soft-deleted rows excluded from `find` / `list`. **Duplicate prevention (writes):** **`ProductCategoryService`** rejects another **live** row in the same **`branch_id` scope** with the same **`TRIM(name)`**; there is **no** DB unique constraint on category name—repository queries used by the service are the operational truth. |
| **`product_brands`** | Flat rows. **Duplicate prevention (writes):** **`ProductBrandService`** rejects another **live** row in the same **`branch_id` scope** (NULL = global) with the same **`TRIM(name)`**, same pattern as categories. **`uk_product_brands_branch_name (branch_id, name)`** applies to the stored **`name`** column (not **`TRIM(name)`**), so it **supplements** the service checks but is **not** a complete specification of trimmed-name uniqueness (e.g. whitespace variants the app normalizes). |
| **`products`** | Optional FKs **`product_category_id`**, **`product_brand_id`**. Legacy **`category`** / **`brand`** VARCHAR columns remain fully supported and unchanged in meaning. |
| **HTTP** | Routes under **`/inventory/product-categories*`** and **`/inventory/product-brands*`** (same **`inventory.*`** permissions as products). Product forms POST optional normalized IDs. |

---

## 2. Validation / scope rules

### Product ↔ taxonomy assignment (`ProductTaxonomyAssignabilityService`)

- **Product `branch_id` = N (branch product):** may attach a category/brand that is **global** (`taxonomy.branch_id IS NULL`) **or** scoped to **N**.
- **Product `branch_id` IS NULL (global product):** may attach **only** global taxonomy rows (branch-scoped category/brand rejected).
- **Missing / deleted taxonomy:** `find()` excludes soft-deleted rows → **InvalidArgumentException** (“not found or is deleted”).
- **Updates:** Effective product branch and merged FKs are validated **after** overlaying the payload on the current row (so changing **branch** invalidates incompatible existing FKs until cleared or replaced).

### Product categories (tree + branch)

- **Inventory index (`/inventory/product-categories`):** table shows **Scope** (Global vs **Branch: {name}**) and **Parent** (trimmed live parent name, or **`{name} (deleted)`** / **`Parent #{id} (deleted)`** when the parent row is soft-deleted, **Missing parent (#id)** when `parent_id` points at no row, **—** when there is no parent). Uses active branch names from **`BranchDirectory`**; unknown branch ids fall back to **`Branch: #id`**.
- **Inventory show (`/inventory/product-categories/{id}`):** **Scope** and **Parent** use the **same** strings as the index (truthful parent even when soft-deleted or missing).
- Same structural rules as service categories: **no cycles**, **no self-parent**, parent must exist and not be soft-deleted.
- **Parent branch scope:** global parent allowed for any child; **branch-scoped parent** requires child **same branch**; **global child** cannot use a **branch-scoped parent**.
- **Delete:** children’s **`parent_id`** cleared to **NULL** before soft-delete of the parent (avoids dangling references to deleted rows). Active products’ **`product_category_id`** cleared when that category is soft-deleted (see §2).

### Product brands

- **Inventory index (`/inventory/product-brands`):** table shows **Scope** (Global vs **Branch: {name}**) with the same branch-name rules as categories above.
- **Inventory show (`/inventory/product-brands/{id}`):** **Scope** matches the index (**Global** / **Branch: {name}** / **`Branch: #id`** when the branch is not in the active directory).
- **Name** required (trim non-empty).
- **Uniqueness:** **`ProductBrandService`** blocks a second **live** row in the same scope with the same **`TRIM(name)`**. The DB unique key is on raw **`name`**, not trimmed equality; concurrent inserts are **not** guaranteed impossible without a DB constraint aligned to the same semantics.
- **Delete:** active products’ **`product_brand_id`** cleared when that brand is soft-deleted (see §2).

### Branch context (`BranchContext`)

- **create:** `enforceBranchOnCreate` on taxonomy and products (unchanged).
- **update/delete:** `assertBranchMatch` on entity **`branch_id`** (global entity editable from any context; branch entity only when context matches or context is unset).
- **update (branch context set):** `enforceBranchIdImmutableWhenScoped` — posted **`branch_id`** must match the row’s existing value (no move to another branch or to global); matching payload key is stripped before persist.

### Taxonomy delete → product FK hygiene

- **Category soft-delete:** before deleting the category row, active products with **`product_category_id`** pointing at it get **`product_category_id = NULL`** (same transaction); legacy **`products.category`** unchanged. Audit metadata: **`products_cleared_product_category_id`** (count).
- **Brand soft-delete:** same for **`product_brand_id`**; legacy **`products.brand`** unchanged. Audit: **`products_cleared_product_brand_id`** (count).

---

## 3. Compatibility strategy

- **Additive:** uses **`085`** schema; no additional migration in these tracks.
- **Legacy strings:** still written/read/searchable; UI can show **`category_display` / `brand_display`** (normalized preferred, legacy fallback) on product list/show.
- **Normalized product FKs (`product_category_id`, `product_brand_id`):** nullable in the database. **`ProductTaxonomyAssignabilityService`** runs on the **effective** values (after merge on update). **Shipped inventory create/edit forms** always POST both keys via `<select>`: empty option → parsed as `null` → clears the FK; a chosen id → stored after validation. **Update + partial payloads:** if a key is **absent** from `$_POST`, `ProductController` omits it from the service payload and **`ProductService::update`** keeps the existing column; empty string still clears. **Create:** if a key is absent from `$_POST`, the column is left unset on INSERT (NULL); empty string clears explicitly. (Legacy `category` / `brand` text fields are separate.)
- **List/read:** products index/show expose **`product_category_label` / `product_brand_label`** when a live normalized row resolves, plus **`category_display` / `brand_display`** for the preferred label; core persisted row shape from **`products`** unchanged. **Branch context (`BranchContext` set):** the products index lists **global ∪ current branch** rows (`branch_union_global` in **`ProductRepository::list` / `::count`**), matching **`BranchContext::assertBranchMatch`** (global rows remain visible alongside branch rows). HQ (no context) keeps the explicit branch filter dropdown: all / global-only / one branch / mix as before.

---

## 4. Deferred (explicit)

- **Operational backfill:** operators run **`php scripts/backfill_product_taxonomy_from_legacy.php`** (`--apply` when ready); see **`PRODUCT-TAXONOMY-BACKFILL-AND-REPORT-ALIGNMENT-01`** implementation in-repo.
- **Orphan FK cleanup:** after backfill or taxonomy deletes, run **`php scripts/audit_product_taxonomy_orphan_fks.php`** (dry-run), then **`--apply`** if you intend to clear dangling normalized FKs only.
- **Pre-existing duplicate live taxonomy rows** (same scope + `TRIM(name)`): new writes blocked; follow the **recommended safe cleanup order** above — in particular, run **`finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php`** (dry-run, then **`--apply`**) **after** duplicate-parent relink + tree integrity repair + cycle-cluster safe break so noncanonical categories that were blocked by live children can be retired. Optional early **`retire_product_taxonomy_duplicate_noncanonical.php`** remains valid right after product FK relink for rows already safe. **Multi-node cycles**: prefer safe-break apply over ad-hoc SQL when the deterministic plan matches your data. **Checkpoint:** **`audit_product_category_tree_post_repair_consolidated.php`** before the **finalize** retire CLI.
- **Unified catalog domain truth** (relationships, visibility, sellable graph beyond read-model).
- **Mixed sales architecture** (invoice line `item_type` / basket).
- **Storefront / PublicCommerce** catalog consumption.
- **Service rich model** (roadmap 3.2).
- **Retiring legacy VARCHAR authority** (explicit future task; not started).

---

## 5. Recommended next operational focus (not §5.C execution)

After taxonomy foundation cleanup (backfill, optional orphan FK audit/repair, and duplicate/tree CLIs as needed per environment), the **next practical step** is an **inventory module page-by-page operational audit**: confirm each screen’s branch scope, selectors, and labels behave as operators expect against real data. **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01` (roadmap 3.2)** and **unified catalog depth beyond the read-model slice (roadmap 3.4)** remain **separate, later roadmap work** under **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** — do **not** treat this doc’s operational checklist as a direct “go execute 3.2 / 3.4 next” instruction. **Mixed checkout / invoice line architecture (4.1)** stays out of scope until explicitly tasked.

---

## 6. Files introduced or materially changed (reference)

- `system/modules/inventory/services/ProductTaxonomyAssignabilityService.php`
- `system/modules/inventory/services/ProductCategoryService.php`
- `system/modules/inventory/services/ProductBrandService.php`
- `system/modules/inventory/controllers/ProductCategoryController.php`
- `system/modules/inventory/controllers/ProductBrandController.php`
- `system/modules/inventory/controllers/ProductController.php`
- `system/modules/inventory/services/ProductService.php`
- `system/modules/inventory/repositories/ProductCategoryRepository.php`
- `system/modules/inventory/repositories/ProductBrandRepository.php`
- `system/modules/inventory/views/product-categories/*`
- `system/modules/inventory/views/product-brands/*`
- `system/modules/inventory/views/products/create.php`, `edit.php`, `show.php`, `index.php`
- `system/modules/inventory/views/index.php`
- `system/routes/web.php`
- `system/modules/bootstrap.php`
- `system/core/Branch/BranchContext.php` (**`enforceBranchIdImmutableWhenScoped`**)
- `system/modules/inventory/repositories/ProductRepository.php` (**detach** helpers for taxonomy delete)
- Inventory edit views: branch read-only under branch context (**`products/edit.php`**, **`product-categories/edit.php`**, **`product-brands/edit.php`**)

**Backfill / read alignment add-ons:** `ProductTaxonomyLegacyBackfillService.php`, `scripts/backfill_product_taxonomy_from_legacy.php`, repository scope helpers, `ProductRepository::listNonDeletedForTaxonomyBackfill` and optional list filters.

**Orphan FK audit/repair:** `ProductTaxonomyOrphanFkAuditService.php`, `scripts/audit_product_taxonomy_orphan_fks.php`, `ProductRepository` orphan clear/count helpers, duplicate trimmed-name group listings on taxonomy repos.

**Duplicate-name hardening:** `ProductCategoryRepository` / `ProductBrandRepository` — `findCanonicalLiveByScopeAndTrimmedName`, `findOtherLiveByScopeAndTrimmedName`, `countLiveByScopeAndTrimmedName`, `listLiveIdsByScopeAndTrimmedName`; TRIM-based `findActiveInProductScope`; `ProductCategoryService` / `ProductBrandService` duplicate checks; backfill canonical counters in **`ProductTaxonomyLegacyBackfillService`**.

**Duplicate canonical relink:** `ProductTaxonomyDuplicateCanonicalRelinkService`, `scripts/relink_product_taxonomy_duplicate_canonical.php`, `ProductRepository` relink/count-by-id-set helpers; taxonomy repos `listDuplicateLive*Groups*`, `listNoncanonicalLive*IdsForDuplicateGroup`.

**Noncanonical retire (early):** `ProductTaxonomyDuplicateNoncanonicalRetireService`, `scripts/retire_product_taxonomy_duplicate_noncanonical.php`, `ProductRepository::countActiveProductsReferencingCategoryId` / `BrandId`, `ProductCategoryRepository::countLiveChildCategoriesWithParentId`.

**Noncanonical retire (post–parent/tree finalization):** `ProductTaxonomyDuplicateNoncanonicalPostTreeFinalizationService`, `scripts/finalize_product_taxonomy_duplicate_noncanonical_after_tree_cleanup.php` (same repository helpers; extended summary including duplicate groups remaining).

**Category duplicate parent relink:** `ProductCategoryDuplicateParentCanonicalRelinkService`, `scripts/relink_product_category_duplicate_parent_canonical.php`, `ProductCategoryRepository::listLiveChildrenByParentId`, `reassignParentIdForLiveCategoryIfMatches`, `ancestorChainContainsId` (cycle check).

**Category tree integrity audit/repair:** `ProductCategoryTreeIntegrityAuditService`, `scripts/audit_product_category_tree_integrity.php`, `ProductCategoryRepository::clearParentIdForLiveCategoryIfParentMatches`, `list(null)` (all live), `rowByIdIncludingDeleted`, `ancestorChainContainsId`.

**Category cycle-cluster audit (read-only):** `ProductCategoryTreeCycleClusterAuditService`, `scripts/audit_product_category_tree_cycle_clusters.php`, `discoverLiveMultiNodeCycleClusters()`, `ProductCategoryRepository::listLiveForParentGraphAudit`.

**Category cycle-cluster safe break:** `ProductCategoryTreeCycleClusterSafeBreakService`, `scripts/apply_product_category_tree_cycle_cluster_safe_break.php`, `clearParentIdForLiveCategoryIfParentMatches`.

**Post-repair consolidated recheck:** `ProductCategoryTreePostRepairConsolidatedRecheckService`, `scripts/audit_product_category_tree_post_repair_consolidated.php` (`--json` optional).
