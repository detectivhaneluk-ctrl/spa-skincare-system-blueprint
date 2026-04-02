# INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX

Status: **PARTIAL** (Tier A: `verify_inventory_taxonomy_tenant_scope_readonly_01.php`, `verify_inventory_tenant_scope_followon_wave_02_readonly_01.php`, `verify_inventory_tenant_scope_followon_wave_03_readonly_01.php`, `verify_inventory_tenant_scope_followon_wave_04_readonly_01.php`, `verify_inventory_tenant_scope_followon_wave_05_readonly_01.php`)

| Surface | Before | After |
|---|---|---|
| Product read/list/count in protected tenant runtime | Raw id/list + branch/global patterns; caller-branch discipline | `findInTenantScope` / `listInTenantScope` / `countInTenantScope` with branch pin + org EXISTS scope |
| Product locked load for stock writes | `findLocked(id)` unscoped | `findLockedInTenantScope(id, branch)` |
| Stock movement read/list/count | Branch filter only; product join not tenant-owned guarded | `find/list/countInTenantScope` with movement+product branch pin + org EXISTS |
| Inventory count read/list/count | Branch filter only | `find/list/countInTenantScope` with count+product branch pin + org EXISTS |
| Supplier read/list/count | Branch/global patterns + raw id | `find/list/countInTenantScope` with branch pin + org EXISTS |
| Product update/delete service path | Load-then-branch assert | tenant-branch-required + tenant-scoped row load then mutation |
| Stock movement write path | Product locked row by raw id | tenant-branch-required + scoped locked product load |
| Inventory count write path | Product by raw id | tenant-branch-required + scoped product load |
| Unresolved org behavior on protected inventory scoped methods | Could depend on caller discipline | fail-closed via `OrganizationRepositoryScope` exception path |
| **Product brand/category taxonomy (HTTP detail + service mutations + product taxonomy labels)** | **Id-only `find($id)` / unscoped label reads** | **`findInTenantScope(id, operationBranch)` on brand/category controllers, brand/category services (incl. parent row), `ProductController::withTaxonomyLabels`; session branch required for taxonomy mutations except parent lookup on create may use payload `branch_id` when session unpinned** |
| **Taxonomy HTTP index + parent label batch + parent graph validation (service path)** | **`list(branch)` / id-only parent map / `assertValidParentAssignment` + ancestor walk via `find()`** | **`listInTenantScope` + `mapByIdsForParentLabelLookupInTenantScope` + pinned branch on brand/category index; `assertValidParentAssignment(..., operationBranchId)` + `ancestorChainContainsIdInTenantScope`** |
| **Product taxonomy assignability (create/update)** | **Id-only category/brand `find` for FK checks** | **`findInTenantScope` with session `tenantBranchId`** |
| **Invoice stock settlement product line read** | **`ProductRepository::find(productId)` unscoped** | **`findReadableForStockMutationInResolvedOrg` when invoice has positive `branch_id`; `findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg` when invoice branch is null (HQ / org-global SKU only)** |
| **Duplicate parent relink + noncanonical retire/post-tree apply rechecks** | **Id-only `find` on category/brand rows** | **Group-scoped or `BranchContext` + `findInTenantScope`; cycle check uses `ancestorChainContainsIdInTenantScope` when operation branch known; else legacy id-only walk** |
| **CLI category tree integrity + multi-node cycle graph + safe-break** | **`list(null)` / global `listLiveForParentGraphAudit` + id-only parent/break `find`** | **`listAllLiveInResolvedTenantCatalogScope`**, **`findLiveInResolvedTenantCatalogScope`**, **`listLiveForParentGraphAuditInResolvedTenantCatalogScope`**; cycle walk **`ancestorChainContainsIdInTenantScope`** with anchor from child branch → session → org’s smallest live branch id; **requires branch-derived tenant bootstrap** (throws `DomainException` if anchor cannot resolve) |
| **HQ / null-branch invoice product settlement** | **`ProductRepository::find` unscoped** | **`findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg`** (`p.branch_id IS NULL` + org live-branch EXISTS) |
| **Product / category taxonomy `<select>` sources (forms)** | **`listSelectable*` SQL without org EXISTS** | **`listSelectableGlobalOrSameBranch` / `listSelectableForProductBranch`** now intersect scope filter with branch-owned OR org-global catalog predicate (`resolvedTenantOrganizationHasLiveBranchExistsClause`) |
| **Deprecated taxonomy helpers** | **Id-only `list` / `find` / `mapByIds`** | **Marked `@deprecated`; no remaining in-repo callers for `mapByIdsForParentLabelLookup` / inventory `list(null)` on HTTP paths — keep only for intentional migration/repair tooling if invoked without tenant context** |
| **Invoice draft product line / stock-branch contract validation** | **`ProductRepository::find(productId)` unscoped in `InvoiceService`** | **`findForInvoiceProductLineAssignmentContractInResolvedOrg` (branch invoice → `findReadableForStockMutationInResolvedOrg`; HQ → `findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg`)** |
| **Cashier + sellable catalog product slice** | **`listActiveForUnifiedCatalog` without org EXISTS** | **`listActiveForUnifiedCatalogInResolvedOrg` / `listActiveOrgGlobalCatalogInResolvedOrg`** |
| **Supplier mutation WHERE clauses** | **`update` / `softDelete` by raw id** | **`updateInTenantScope` / `softDeleteInTenantScope` from `SupplierService`** |
| **Stock movement / inventory count repo primitives** | **Unscoped `find` / `list` / `count` footguns** | **Marked `@deprecated`; protected HTTP paths use `*InTenantScope`** |
| **Taxonomy duplicate repair row load (no explicit group branch)** | **Id-only `find` on category/brand** | **`findLiveInResolvedTenantCatalogScope` (org-scoped catalog visibility); `ProductBrandRepository` gains matching helper** |
| **Product mutation WHERE** | **`ProductRepository::update` / `softDelete` by raw id** | **`updateInTenantScope` / `softDeleteInTenantScope` from `ProductService` (branch pin + org EXISTS)** |
| **Invoice settlement stock aggregates** | **`sumNetQuantityForInvoiceItem` / `aggregateInvoiceItemSettlement*` by `reference_id` only** | **Join `invoice_items` → `invoices` + `SalesTenantScope::invoiceClause` when scope SQL non-empty; legacy path when clause empty (global repair tooling)** |
| **Legacy taxonomy backfill + orphan FK audit/apply** | **Unscoped product scans / duplicate group listing** | **`listNonDeletedForTaxonomyBackfillInResolvedTenantCatalog`; orphan counts/examples/clear + duplicate trim groups use resolved-tenant catalog visibility on products and taxonomy** |
| **Duplicate retire / post-tree apply + parent cycle (no session branch)** | **Id-only category/brand `softDelete`; unscoped ancestor walk** | **`softDeleteLiveInResolvedTenantCatalogScope`; `ancestorChainContainsIdInResolvedTenantCatalogScope` for relink cycle check** |

**Post wave-05 audit (2026-04-02, FINAL-BACKEND-CLOSURE-BEFORE-UI-AND-DESIGN-GATE-01):**

Stale claims corrected after code inspection:
- **`detachActiveProductsFromCategory` / `detachActiveProductsFromBrand`** — Prior claim "unchanged this wave" was incorrect. Code inspection confirms both methods already use `resolvedTenantCatalogProductVisibilityClause('p')` in their UPDATE WHERE clause. Scoped. No further work needed.
- **`ServiceRepository::list`** — Prior claim "unchanged" was incorrect. Code inspection confirms it already uses `branchColumnOwnedByResolvedOrganizationExistsClause('s')` appended to every SQL result set. Scoped. No further work needed.

**Remaining intentional deferrals (not blockers for new page/design work):**
- **Deprecated `ProductRepository::find` / `list` / `listActiveForUnifiedCatalog`** and **deprecated `SupplierRepository` / taxonomy id-only helpers** — `@deprecated`; protected HTTP paths do not call them; they are locked with `LogicException` throws where appropriate. Future: remove when all repair tooling is updated.
- **`StockMovementRepository::existsSaleDeductionForInvoiceItem`** — reference_id-only, zero in-repo HTTP callers; documented as verifier-only structural check (ROOT-01 documented, verifier: `verify_critical_integrity_fail_closed_boundary_01.php`). Deferred: add org-scoped variant only when a HTTP caller is introduced.
- **`sumNetQuantityForInvoiceItem` / settlement aggregates using unscoped `stock_movements` when `SalesTenantScope::invoiceClause` SQL is empty** — intentional repair/global-mode path (ROOT-04 documented). The empty-clause condition only occurs in global admin tooling paths, not tenant HTTP runtime. Deferred: future platform-scale work.
- **`ProductCategoryRepository::listLiveForParentGraphAudit()` / `ancestorChainContainsId`** — `@deprecated`; zero protected HTTP callers. Deferred: remove when CLI tooling is updated.

**Matrix final status: PARTIAL (wave 1–5 closed; remaining items are documented intentional deferrals, not current HTTP safety risks)**

