# SETTINGS-ESTABLISHMENT-MECHANISM-CORRECTION-IMPLEMENTATION-02

Date: 2026-03-24  
Status: Completed (mechanism correction only)

## Screen state added

The establishment section now uses explicit screen state on the existing route:
- `/settings?section=establishment&screen=overview`
- `/settings?section=establishment&screen=edit-overview`
- `/settings?section=establishment&screen=edit-primary-contact`
- `/settings?section=establishment&screen=edit-secondary-contact`
- `/settings?section=establishment&screen=opening-hours`
- `/settings?section=establishment&screen=closure-dates`

Controller behavior:
- `screen` is whitelisted and normalized.
- Invalid/missing screen falls back to `overview`.
- Establishment form posts preserve `screen` so redirect returns to current focused screen.

## Live working forms

Working forms in this wave:
1. `edit-overview`
   - Writes only current truth-backed fields:
     - `establishment.name`
     - `establishment.phone`
     - `establishment.email`
     - `establishment.address`
     - `establishment.currency`
     - `establishment.timezone`
     - `establishment.language`
   - Uses existing `/settings` POST contract, CSRF, and settings keys.
2. `edit-primary-contact`
   - Writes only:
     - `establishment.phone`
     - `establishment.email`
   - No fake first/last-name fields were introduced.

## Focused but intentionally non-editable screens

Non-editable by design (truth-safe):
1. `edit-secondary-contact`
   - Dedicated focused screen.
   - No save button because secondary contact backend was not validated.
2. `opening-hours`
   - Dedicated focused screen.
   - No fake editor or save path; audit did not validate establishment-level settings editor.
3. `closure-dates`
   - Dedicated focused screen.
   - No fake add/edit/delete controls; audit did not validate establishment-level settings editor.

## Mechanism correction confirmation

1. Merged long-page behavior was removed for establishment.
2. Establishment now renders exactly one focused screen at a time.
3. Overview screen is summary + entry actions only (no large inline edit form).
4. `/branches` launch misuse was removed from establishment mechanism flow.
5. English-only copy is used for the establishment mechanism screens in this wave.

## Scope guardrails kept

- No settings key renames.
- No SettingsService key changes.
- No backend expansion for missing fields.
- No fake CRUD added for unsupported domains.
- No unrelated section refactors were made.
