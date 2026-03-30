# SALES-CASHIER-SURFACE-SIMPLIFICATION-AND-MAIN-PAGE-ALIGNMENT-01 OPS

## What changed

- `GET /sales` now resolves to the same cashier workspace surface used by invoice create/edit.
- The launcher-style Sales home view was removed and replaced with shared cashier rendering.
- The shared cashier workspace remains single-source (`views/invoices/_cashier_workspace.php`).

## Canonical surface decision

- Canonical surface: **render shared cashier workspace through `/sales`**.
- `/sales` and `/sales/invoices/create` both use the same partial and same visual structure.
- `/sales/invoices/{id}/edit` remains edit variant of that same shared surface.

## Semantics preserved

- Invoice create/update still posts into existing `InvoiceController` and `InvoiceService` logic.
- Financial, payment, and stock settlement semantics are unchanged.
- Product add still maps to canonical line payload (`item_type=product`, `source_id=product.id`).
- Service add still maps to canonical line payload (`item_type=service`, `source_id=service.id`).
- Tip add remains manual line semantics (`item_type=manual`, `description=TIP: ...`).

## Honesty and clutter cleanup

- Scanner/source remain visibly disabled because no audited backend integration is present.
- Deferred tab (`Gift Card / Card / Series / Client Account`) stays non-interactive and honest.
- Technical line fields (`source_id`, `item_type`, `discount_amount`, `tax_rate`) are preserved as hidden inputs.
- Branch/client technical rows were removed from main workspace grid to reduce operator clutter.

## Read-only proof script

- Script: `scripts/read-only/proof_sales_cashier_surface_simplification_and_main_page_alignment_01.php`
- Run from `system/`:

```bash
php scripts/read-only/proof_sales_cashier_surface_simplification_and_main_page_alignment_01.php
```
