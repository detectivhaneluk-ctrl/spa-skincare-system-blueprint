# CASHIER-LINE-CONTRACT-AND-DEFERRED-SALES-TYPES-FOUNDATION-01

## A. Truth sheet (before coding snapshot)

### Current facts (pre-change baseline)

- `invoice_items.item_type` was effectively `service` | `product` | `manual` in writer paths; POST parsing whitelisted only those three.
- Deferred cashier tab was static copy only (no POST semantics).
- Tips were stored as `manual` lines with a `TIP:` description prefix.
- Membership checkout used top-level `membership_definition_id` + standalone `MembershipSaleService` path, not a first-class line type.

### Problem

Cashier UI implied multiple sale semantics, but the backend had no strict contract, no validation, and no persistence sidecar for typed lines—so the surface was partly a shell.

### Target

- Canonical line types: `product`, `service`, `manual`, `gift_voucher`, `gift_card`, `series`, `client_account`, `membership`, `tip`.
- Parser + validator + `line_meta` JSON persistence.
- Real domain effects on **invoice create** for `gift_card` (issue) and `series` (package assign), tenant/branch safe.
- Minimal POST fields in `_cashier_workspace.php` to exercise the contract.

### Out of scope (this task)

- Scanner, source, live totals, register integration.
- Re-running domain effects on **invoice update** (adding lines on edit does not issue another card / assign another package in this foundation).
- Full AR / client-account posting subsystem (lines persist; ledger is future work).
- Marketing template binding for `gift_voucher` beyond optional catalog `source_id` → product.
- Booker / full POS parity.

## B. Files changed

- `system/data/migrations/111_invoice_items_cashier_line_meta.sql` — `line_meta` JSON, widen `item_type`.
- `system/data/full_project_schema.sql` — schema mirror.
- `system/modules/sales/services/CashierInvoiceLineType.php` — constants + contract metadata.
- `system/modules/sales/services/CashierLineItemParser.php` — POST → normalized lines.
- `system/modules/sales/services/CashierLineItemValidator.php` — branch/client/package/membership/tip rules.
- `system/modules/sales/services/CashierLineDomainEffectsApplier.php` — create-only side effects.
- `system/modules/sales/repositories/InvoiceItemRepository.php` — `line_meta` normalize + `mergeLineMeta`.
- `system/modules/sales/services/InvoiceService.php` — persist IDs, invoke applier after line insert + recompute.
- `system/modules/sales/controllers/InvoiceController.php` — parse/validate/membership line intent.
- `system/modules/sales/services/CashierWorkspaceViewDataBuilder.php` — `packages` for series UI.
- `system/modules/sales/controllers/SalesController.php` — pass `packages` into view.
- `system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php` — DI registrations.
- `system/modules/sales/views/invoices/_cashier_workspace.php` — deferred controls + `line_meta_json` + tip type.
- `system/modules/sales/services/SalesLineDomainBoundaryTruthAuditService.php` — recognize extended types in audits.

## C. Implementation summary

- **Parser:** `CashierLineItemParser` reads `items[]`, supports `line_meta_json` round-trip, drops blank default manual rows, normalizes quantities per contract.
- **Validator:** `CashierLineItemValidator` enforces branch/client rules, package + membership tenant lookups, tip tax/qty, gift card tax/amount.
- **Persistence:** `line_meta` stored on `invoice_items`; domain outcomes merged via `mergeLineMeta` (e.g. `issued_gift_card_id`, `client_package_id`).
- **Effects:** `CashierLineDomainEffectsApplier::applyForNewInvoice` runs inside the same DB transaction as `InvoiceService::create` after lines are written.

## D. Line-type contract (summary)

| Type | Required | Optional | Client | Employee | Qty | Persists as | Domain effect |
|------|----------|----------|--------|----------|-----|-------------|---------------|
| product | source_id, money line | line_meta | no | no | yes | invoice line | none |
| service | money line | source_id, line_meta | no | no | yes | invoice line | none |
| manual | money line | line_meta | no | no | yes | invoice line | none |
| gift_voucher | amount | source_id→product, line_meta | no | no | yes | invoice line | none |
| gift_card | face value | line_meta | no | no | yes | invoice line | **issue gift card** |
| series | source_id→package, sessions | line_meta | **yes** | no | yes (sessions) | invoice line | **assign package** |
| client_account | amount | description, line_meta | **yes** | no | yes | invoice line | none (AR future) |
| membership | source_id→definition | starts in line_meta | **yes** | no | no (forced 1) | routed to membership sale | **membership sale** (existing service) |
| tip | amount | description, employee in meta | no | optional | no (forced 1) | invoice line | none |

Validation errors are returned keyed by `items[n]` / `items` / `client_id` from `CashierLineItemValidator` and merged in `InvoiceController::validate`.

## E. What changed vs before

### Removed fake behavior

- Deferred tab “read-only until audited line-storage” placeholder (replaced with working POST-backed controls).

### Added real behavior

- Typed parsing/validation for deferred sale categories.
- `line_meta` JSON column + merge API.
- Gift card issuance + package assignment hooked from successful **invoice create**.

### Still not implemented

- Domain effects on **update**.
- Client-account balance ledger.
- Deep gift-voucher / marketing-template integration.
- Employee attribution enforcement (field exists in meta only).
