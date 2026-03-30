# VAT-TYPES-SETTINGS-FOUNDATION-PARITY-01 — OPS memo

## Scope delivered

- Strengthened settings-owned VAT Types catalog in `vat_rates`.
- Added stored truth fields for flexibility, price-tax inclusion flag, and applicability metadata.
- Added explicit archive action (soft deactivate) in settings.
- Kept invoice tax math, service VAT assignment semantics, and reporting behavior unchanged.

## Schema changes (additive only)

- Migration: `system/data/migrations/098_add_vat_rates_settings_foundation_fields.sql`
- Added columns:
  - `is_flexible TINYINT(1) NOT NULL DEFAULT 0`
  - `price_includes_tax TINYINT(1) NOT NULL DEFAULT 0`
  - `applies_to_json JSON NULL`

## Runtime and semantics

- `services.vat_rate_id` behavior remains unchanged.
- Invoice tax calculation behavior remains unchanged.
- Reports behavior remains unchanged.
- `applies_to_json` is descriptive settings truth only in this wave (no runtime enforcement).
- VAT type `code` remains auto-generated and immutable.

## Archive behavior

- Route: `POST /settings/vat-rates/{id}/archive`
- Archive means `is_active = 0` (no hard delete).
- Audit event: `vat_rate_archived`.

## Honest parity note

- Products are still not linked by `vat_rate_id`; products continue using raw `vat_rate` decimal in this wave.
- `applies_to_json` is stored truth but is not yet enforced by runtime rules.
- This is foundation parity, not full Booker parity.

## Verification

Run:

`php system/scripts/verify_vat_types_settings_foundation_parity_01.php`
