# SALES-INDEX-TO-CASHIER-VISIBILITY-FIX-01 OPS

## Audit truth (current)

- `GET /sales` is served by `Modules\Sales\Controllers\SalesController::index`.
- Route wiring is in `routes/web/register_sales_public_commerce_staff.php`.
- **`/sales` renders the staff checkout shell:** `views/index.php` is a thin wrapper that `require`s `views/invoices/_cashier_workspace.php` (same partial as create/edit). There is no separate “sales home” view with its own copy of subnav strings.
- Cashier workspace partial remains canonical for create/edit at `/sales/invoices/create` and `/sales/invoices/{id}/edit`, and is **also** the `/sales` landing surface.

## Visibility / UX notes

- Local sales subnav (`Manage Sales`, `Gift Cards / Checks`, `Series`, `Caisse`) lives **inside** `_cashier_workspace.php`.
- Canonical POS label: **`Caisse`**, linking to `/sales/invoices/create`.
- Invoice list (`views/invoices/index.php`) keeps a **`Caisse`** shortcut to the same route.
- Register sessions remain available at **`GET /sales/register`** (route-level; not necessarily linked from every cashier partial).

## Semantics preserved

- No controller business logic changes.
- No invoice financial/payment/membership/gift-card runtime semantics changed.
- No route contracts changed.

## Proof script

- `scripts/read-only/proof_sales_index_to_cashier_visibility_fix_01.php`
- Run from `system/`:

```bash
php scripts/read-only/proof_sales_index_to_cashier_visibility_fix_01.php
```
