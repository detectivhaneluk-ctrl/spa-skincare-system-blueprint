# INVENTORY-TENANT-DATA-PLANE-HARDENING-01

Status: **CLOSED**

## Scope completed in this wave

Protected tenant runtime inventory paths were hardened to fail closed for:

- products (list/read/update/delete + stock-affecting service usage)
- stock movements (list/read/create manual and service writes)
- inventory counts (list/read/create)
- suppliers (list/read/update/delete)

## Runtime contract now enforced

1. Protected inventory runtime requires tenant branch context (`BranchContext` branch id > 0) in inventory services/controllers touched by this wave.
2. Repository tenant-scoped methods enforce:
   - branch pin (`...branch_id = :currentBranch`)
   - organization ownership via `OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause(...)`
3. Unresolved/non-branch-derived org context fails closed on protected scoped repository methods through the org-scope helper exception path.
4. Foreign-tenant ids are rejected for product read/update/delete and stock/count writes because product row loading now uses tenant-scoped locked/read methods.
5. Protected list paths no longer use branch-or-global permissive reads for this wave’s surfaces; tenant runtime lists are branch- and org-scoped.

## Files and behavior summary

- `ProductRepository`, `StockMovementRepository`, `InventoryCountRepository`, `SupplierRepository`
  - Added `*InTenantScope` read/list/count methods (and locked product read).
  - Existing legacy methods were left in place for non-wave callers.
- `ProductService`, `StockMovementService`, `InventoryCountService`, `SupplierService`
  - Added tenant-branch-required guard and switched core row loads to tenant-scoped repository methods.
- `ProductController`, `StockMovementController`, `InventoryCountController`, `SupplierController`
  - Protected tenant list/show/edit/update/delete/create views now consume tenant-scoped methods and tenant-branch-required guards for these surfaces.
- `register_inventory.php`
  - Wired `OrganizationRepositoryScope` into inventory repositories touched by this wave.

## Executed runtime proof

### New focused verifier

- Script: `system/scripts/smoke_inventory_tenant_data_plane_hardening_01.php`
- Runtime: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- Result: **19 passed, 0 failed**

Asserts covered:

- own tenant product/movement/count read allowed
- foreign product/movement id reads denied
- foreign product update/delete denied
- cross-tenant stock movement and count-adjustment-style movement denied
- cross-tenant inventory count denied
- valid in-tenant product update and stock movement still work
- tenant list reads are scoped
- unresolved tenant context fails closed on scoped repo and write paths
- relevant regression checks: tenant branch access invalid user remains empty; tenant entry invalid user remains none

### Relevant existing regression verifier re-run

- Script: `system/scripts/smoke_tenant_owned_data_plane_hardening_01.php`
- Runtime: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- Result: **14 passed, 0 failed**

## Explicitly deferred/out of scope in this wave

- Product-category and product-brand dedicated CRUD route hardening beyond existing behavior (their broader taxonomy lane remains separate to avoid wave drift).
- Inventory/reporting module expansion beyond protected runtime inventory surfaces above.
- Any UI/view redesign.
- Any schema redesign/migration.

