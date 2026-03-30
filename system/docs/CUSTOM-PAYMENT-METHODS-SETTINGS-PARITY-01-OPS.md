# CUSTOM-PAYMENT-METHODS-SETTINGS-PARITY-01 — OPS memo

## Scope delivered

- Settings-owned `payment_methods` domain only.
- Added display-only `type_label` metadata for operator UI parity.
- Added explicit archive behavior (`is_active = 0`) instead of physical delete.
- Kept Payment Settings and payment recording semantics anchored to `code` + `is_active`.

## Data contract change

- Migration: `system/data/migrations/097_add_type_label_to_payment_methods.sql`
- Column: `payment_methods.type_label VARCHAR(50) NULL`
- Meaning: operator-facing label only (display metadata).
- Non-goal: this does **not** replace or modify derived family logic.

## Runtime behavior

- Family classification remains derived by `Modules\Sales\Support\PaymentMethodFamily` from `code` + `name`.
- Payment Settings bucketing remains `PaymentSettingsMethodBuckets` and unchanged in semantics.
- Payment recording validation continues to rely on active method `code` rules, not `type_label`.
- Method `code` remains immutable in settings CRUD.

## Archive semantics

- New route: `POST /settings/payment-methods/{id}/archive`
- Controller action archives by deactivating (`is_active = 0`) and logs `payment_method_archived`.
- No hard delete path for payment methods in this wave.

## Verification

Run:

`php system/scripts/verify_custom_payment_methods_settings_parity_01.php`
