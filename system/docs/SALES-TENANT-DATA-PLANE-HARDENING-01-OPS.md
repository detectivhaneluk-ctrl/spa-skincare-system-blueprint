# SALES-TENANT-DATA-PLANE-HARDENING-01 OPS

Status: DONE
Date: 2026-03-23

## Objective reached

- Hardened protected tenant sales runtime read/write paths so invoice/payment/register access resolves through tenant org-owned branch scope.
- Removed permissive `branch-or-null` behavior from protected invoice list/count filters.
- Added explicit protected-runtime tenant-context gate in sales controllers (`/sales*`) to fail closed when tenant organization context is unresolved.

## Code-truth hardening applied

- Central sales scope contract added: `Modules\Sales\Services\SalesTenantScope`.
- Repository scoping hardened for protected runtime resolution:
  - `InvoiceRepository`: scoped `find`, `findForUpdate`, `list`, `count`, `update`, `softDelete`.
  - `PaymentRepository`: scoped `find`, `findForUpdate`, invoice-linked reads and financial rollup queries through scoped invoice existence checks.
  - `RegisterSessionRepository`: scoped session read/update/list/count paths.
  - `CashMovementRepository`: scoped read/aggregate paths.
  - `InvoiceItemRepository`: scoped read/update/delete-by-id and delete-by-invoice through scoped invoice existence checks.
- Protected runtime context fail-closed gate enforced in:
  - `SalesController`
  - `InvoiceController`
  - `PaymentController`
  - `RegisterController`

## Runtime proof executed

- Verifier added: `system/scripts/smoke_sales_tenant_data_plane_hardening_01.php`.
- Executed with local PHP runtime.
- Result: `14 passed, 0 failed`.
- Proven scenarios:
  - own-tenant invoice/payment/register reads succeed
  - foreign invoice/payment/register loads by id are denied
  - foreign invoice/payment/register mutations are denied
  - cross-tenant payment application is denied
  - valid in-tenant sales write paths still work
  - unresolved tenant context triggers protected sales runtime fail-closed guard
  - relevant tenant-boundary regression checks remain green (own/foreign client visibility)

## Deferred/out of scope (explicit)

- No broad commerce/storefront expansion.
- No broad inventory, memberships, gift-cards, or packages hardening expansion beyond sales-coupled paths.
- Legacy unresolved-org fallback behavior outside protected tenant sales runtime remains intentionally unchanged in this wave.
