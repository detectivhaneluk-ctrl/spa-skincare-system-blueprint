# SALES-INDEX-BOOKER-STYLE-HOME-SURFACE-01 OPS

## Audit truth

- `GET /sales` is still served by `Modules\Sales\Controllers\SalesController::index`.
- The view for the landing page is `modules/sales/views/index.php`.
- Canonical cashier create/edit routes remain:
  - `/sales/invoices/create`
  - `/sales/invoices/{id}/edit`

## What changed

- Rebuilt `/sales` into a Booker-style operator home surface:
  - strong local sales subnav
  - left operator rail with find-order and quick actions
  - central banner + caisse workspace launch surface
  - dark-blue tab-like preview row
- Kept `/sales` as launcher/overview only (no fake embedded cashier form semantics).
- Primary operator wording is `Caisse` with legacy wording kept only as helper hint.

## Honesty constraints

- Find-order controls on `/sales` are explicitly disabled/read-only and marked non-connected.
- Preview tabs are visual only on landing; real interactive cashier behavior remains in canonical create/edit pages.

## Route/semantics preservation

- No route contracts changed.
- No invoice financial, payment, membership, or gift-card semantics changed.

## Proof script

- `scripts/read-only/proof_sales_index_booker_style_home_surface_01.php`
- Run from `system/`:

```bash
php scripts/read-only/proof_sales_index_booker_style_home_surface_01.php
```
