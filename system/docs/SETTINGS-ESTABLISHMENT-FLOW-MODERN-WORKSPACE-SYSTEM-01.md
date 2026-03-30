# SETTINGS-ESTABLISHMENT-FLOW-MODERN-WORKSPACE-SYSTEM-01

Date: 2026-03-24  
Scope: Modern workspace redesign for establishment-related flow in Settings, with backend-safe parity.

## Exact pages modernized

- `GET /settings?section=establishment` workspace in:
  - `system/modules/settings/views/index.php`
- Existing settings shell language/IA remains in:
  - `system/modules/settings/views/partials/shell.php`

## Legacy functions and flows preserved

- Same form target: `/settings`
- Same hidden `section=establishment`
- Same CSRF hidden field behavior
- Same posted keys:
  - `settings[establishment.name]`
  - `settings[establishment.phone]`
  - `settings[establishment.email]`
  - `settings[establishment.address]`
  - `settings[establishment.currency]`
  - `settings[establishment.timezone]`
  - `settings[establishment.language]`
- Same controller/service write path (`SettingsController::store` + `SettingsService` patch handlers)
- Opening Hours and Closure Dates remain managed through existing branch operations route (`/branches`)

## English-only normalization

- Visible UI copy in this flow is English only.
- No French labels were introduced in rendered establishment/settings workspace content.

## Old fields kept for parity

- Backed editable fields kept:
  - Name, Phone, Email, Address, Currency, Time Zone, Language
- Parity blocks shown as explicit pending (not silently removed):
  - Display Name
  - Description
  - Address line 2
  - City
  - Postal code
  - Country
  - Website
  - Tax/VAT identification
  - SIRET/Company identifier
  - Bank details
  - Secondary Contact

## Restyled instead of removed

- Establishment flow is now a modern workspace system with:
  - hero context header
  - overview + quick actions
  - grouped edit workspace
  - clear action bar (Save/Cancel)
  - related operational read-only card
  - de-emphasized pending block for secondary contact
- Existing functional flow was preserved; unsupported legacy-style data points are visibly marked `Backend pending` rather than removed.
