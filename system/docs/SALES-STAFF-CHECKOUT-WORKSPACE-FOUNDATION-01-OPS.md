# SALES-STAFF-CHECKOUT-WORKSPACE-FOUNDATION-01 OPS

## Scope delivered

- Unified staff checkout workspace now uses the canonical invoice create/edit flow.
- Connected tabs are real: `Products`, `Services`, `Tips`, `Membership`.
- Deferred tab group is explicit and non-fake: `Gift Card / Series / Client Account`.

## Backend truth preserved

- Invoice create/update still routes through `InvoiceService` with existing stock/tax/financial semantics unchanged.
- `parseInput()` now safely accepts `product` lines in addition to `service` and `manual`.
- Product lines continue to rely on canonical `item_type=product` + `source_id=products.id` contract already validated by `InvoiceService`.
- Service lines continue to use canonical `item_type=service` + `source_id=services.id`, with tax overwrite handled by `InvoiceService`.
- Tip lines are intentionally manual (`item_type=manual`) and visibly labeled (`TIP: ...`) with no hidden accounting rules.
- Membership checkout still calls canonical `MembershipSaleService::createSaleAndInvoice` and is guarded as standalone in staff checkout.

## Honest deferrals

- `Gift Card / Series / Client Account` add-to-draft actions are intentionally disabled.
- Reason: no canonical, proven cashier-draft line contract was audited for those domains in this wave.
- Existing gift-card support remains invoice payment redemption and separate gift-card issue/redeem module routes.

## Operator notes

- Membership requires a selected client.
- Membership cannot be mixed with other draft lines in this wave; the form shows and enforces this.
- Service employee assignment is displayed as deferred because there is no proven line-level storage contract yet.

## Proof script

- Read-only proof script: `scripts/read-only/proof_staff_checkout_workspace_foundation_01.php`
- Run from `system/`:

```bash
php scripts/read-only/proof_staff_checkout_workspace_foundation_01.php
```

- The script validates implemented code contracts (controller/view/wiring) without mutating data.

