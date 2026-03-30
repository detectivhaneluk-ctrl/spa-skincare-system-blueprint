# VAT-DISTRIBUTION-MATRIX-IN-PLACE-SETTINGS-FOUNDATION-01 — OPS memo

## What changed

- Converted existing settings subsection URL in place:
  - `GET /settings/vat-distribution-guide`
  - `POST /settings/vat-distribution-guide`
- The page is now an editable matrix backed by `vat_rates.applies_to_json`.
- Rows are active global VAT types.
- Columns for this wave:
  - products
  - services
  - memberships

## Write contract

- POST shape:
  - `matrix[vat_rate_id][] = products|services|memberships`
- Allowed tokens are sanitized against this subsection allowlist.
- Writes target active global rows only (`branch_id IS NULL`, `is_active = 1`).
- Tokens are normalized, deduplicated, and sorted consistently before persistence.

## Audit

- Bulk save emits:
  - `vat_distribution_matrix_updated`
- Metadata includes domains, updated count, and before/after applicability by VAT rate id.

## What did not change

- `/reports/vat-distribution` behavior is unchanged.
- Invoice tax calculation behavior is unchanged.
- No runtime enforcement changes were added beyond saving `applies_to_json`.

## Honest note (Booker gap)

- This is a settings matrix storage surface only; runtime tax policy enforcement from the matrix is still not implemented.
- Products are still not migrated to `vat_rate_id` in this wave.

## Verification

Run:

`php system/scripts/verify_vat_distribution_matrix_in_place_settings_foundation_01.php`
