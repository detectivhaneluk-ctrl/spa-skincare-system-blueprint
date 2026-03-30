# Price Modification Reasons Domain (OPS)

Task: **PRICE-MODIFICATION-REASONS-DOMAIN-FOUNDATION-07**

## What this domain owns

- Internal catalog of allowed reasons for manual price changes/overrides/corrections.
- CRUD for operators (list, create, edit, active/inactive via `is_active`).
- Stable `code` + operator-facing `name` (+ optional `description`, `sort_order`).

## What this domain does NOT include

- No PSP/gateway/payment method processing.
- No VAT/tax calculation logic.
- No invoice financial recompute changes.
- No approval workflow engine.

## Storage model

- Table: `price_modification_reasons`
- Scope: `organization_id` (catalog per tenant organization; no branch split in this wave)
- Key uniqueness: generated column `live_code` + unique index `(organization_id, live_code)` to allow soft delete without code collisions.
- Active state: `is_active` (toggle in edit form).
- Soft delete support: `deleted_at` in table; repository currently updates/deactivates, no delete route exposed in UI.

## Runtime/API shape for future consumption

- `PriceModificationReasonService::listActiveForPicker(): list<{id,code,name}>`
- Designed for future dropdowns in manual price-adjustment/override flows.
- Stable contract: use `code` for machine logging, `name` for operator display.

## Routes

- `GET /settings/price-modification-reasons`
- `GET /settings/price-modification-reasons/create`
- `POST /settings/price-modification-reasons`
- `GET /settings/price-modification-reasons/{id}/edit`
- `POST /settings/price-modification-reasons/{id}`

Permissions:
- `price_modification_reasons.view`
- `price_modification_reasons.manage`

## Payment Settings surfacing

- Removed from deferred list.
- Added as real linked action under **Related finance surfaces**:
  `Price Modification Reasons` → `/settings/price-modification-reasons`

