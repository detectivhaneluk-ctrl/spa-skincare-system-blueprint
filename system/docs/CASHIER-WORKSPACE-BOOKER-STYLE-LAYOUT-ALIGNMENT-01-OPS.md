# CASHIER-WORKSPACE-BOOKER-STYLE-LAYOUT-ALIGNMENT-01 OPS

## Scope

- Kept one shared cashier partial for both create/edit invoice forms.
- Refactored cashier layout to a two-column Booker-like shell:
  - left rail (search + quick add)
  - top client banner
  - ordered articles workspace with tabbed add-item panels
  - current line items panel
- Added local cashier/sales second-level nav:
  - Manage Sales
  - Gift Cards / Checks
  - Series
  - Caisse

## Backend truth preserved

- Invoice create/edit still posts to the same canonical routes and controller methods.
- `items[*]` payload schema and line-domain semantics remain unchanged (`manual`, `service`, `product`).
- Add product/service/tip actions still append draft lines in the same canonical invoice input array.
- Membership behavior remains canonical:
  - client required
  - standalone checkout guard enforced by controller
  - create-only membership launch controls retained

## Honest deferred/disabled parts

- Scanner and source controls are shown for workspace parity but disabled/read-only.
- Gift Card / Card / Series / Client Account tab is present but non-fake: explanatory deferred panel only.
- No fake success actions were added for deferred domains.

## Runtime usage note

- Order search rail uses invoice list route (`/sales/invoices`) and appends `invoice_number` query.
- If `invoice_number` filtering is not yet implemented in listing backend, behavior remains safe (no mutation).

## Proof script

- `scripts/read-only/proof_cashier_workspace_booker_style_layout_alignment_01.php` — asserts **current** `_cashier_workspace.php` strings (e.g. disabled scanner/source placeholders). Update the script when honest copy changes.
- Run from `system/`:

```bash
php scripts/read-only/proof_cashier_workspace_booker_style_layout_alignment_01.php
```
