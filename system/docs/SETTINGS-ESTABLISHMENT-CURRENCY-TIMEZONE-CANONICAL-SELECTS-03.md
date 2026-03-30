# SETTINGS-ESTABLISHMENT-CURRENCY-TIMEZONE-CANONICAL-SELECTS-03

Date: 2026-03-24  
Status: Completed (view-only change)

## Exact file changed

- `system/modules/settings/views/establishment/screens/edit-overview.php`
- `system/modules/settings/views/establishment/_styles.php`

## Backend contract confirmation

No backend contract was changed.

Unchanged:
- POST target remains `/settings`
- CSRF behavior remains unchanged
- Input names remain unchanged:
  - `settings[establishment.currency]`
  - `settings[establishment.timezone]`
- No controller logic changes
- No `SettingsService` changes
- No migration/schema changes

## Currency select behavior

The Currency field is now a `<select>` using a curated major-currency list:

- USD — US Dollar
- EUR — Euro
- GBP — British Pound
- CHF — Swiss Franc
- CAD — Canadian Dollar
- AUD — Australian Dollar
- NZD — New Zealand Dollar
- JPY — Japanese Yen
- CNY — Chinese Yuan
- HKD — Hong Kong Dollar
- SGD — Singapore Dollar
- KRW — South Korean Won
- INR — Indian Rupee
- AED — UAE Dirham
- SAR — Saudi Riyal
- TRY — Turkish Lira
- SEK — Swedish Krona
- NOK — Norwegian Krone
- DKK — Danish Krone
- PLN — Polish Zloty
- CZK — Czech Koruna
- HUF — Hungarian Forint
- RON — Romanian Leu
- BGN — Bulgarian Lev
- AMD — Armenian Dram
- GEL — Georgian Lari
- RUB — Russian Ruble
- UAH — Ukrainian Hryvnia
- ILS — Israeli New Shekel
- ZAR — South African Rand
- BRL — Brazilian Real
- MXN — Mexican Peso
- KZT — Kazakhstani Tenge

Selection behavior:
- Matching saved code is selected.
- Blank saved value keeps blank selection.
- Unknown saved code gets a selected fallback option:
  - `CURRENT (legacy): <value>`

## Time zone select behavior

The Time Zone field is now a `<select>` sourced from:
- `timezone_identifiers_list()`

Rendering:
- Grouped with `<optgroup>` by top-level region.
- Preferred region order:
  - Africa
  - America
  - Antarctica
  - Arctic
  - Asia
  - Atlantic
  - Australia
  - Europe
  - Indian
  - Pacific
- Any remaining regions are rendered after preferred groups.

Selection behavior:
- Valid saved IANA timezone is selected.
- Blank saved value keeps blank selection.
- Unknown saved value gets a selected fallback option:
  - `CURRENT (legacy): <value>`

## Legacy fallback behavior summary

Both Currency and Time Zone selectors preserve legacy saved values by injecting a selected fallback option when the current stored value is non-empty but not in the canonical option set. This prevents accidental data loss on edit/save.
