# SETTINGS-ESTABLISHMENT-WORKSPACE-MODERNIZATION-01

Date: 2026-03-24  
Scope: Establishment workspace UI modernization only (`section=establishment`).

## Visual structure changes

- Replaced the former single raw form block with a modern structured workspace:
  - Top hero row: title + concise lead + compact status/help chip.
  - Card A: `Profil établissement` (name, phone, email, address).
  - Card B: `Paramètres régionaux` (currency, timezone, language).
  - Card C: `Actions` (primary save CTA + concise impact helper text).
- Introduced scoped, lightweight styling for improved hierarchy, spacing, and responsive behavior:
  - cleaner card containers
  - compact labels above inputs
  - responsive grid with desktop two-column feel and mobile single-column fallback
- No redesign was applied to other settings sections.

## Backend contracts untouched

- POST target remains `/settings`.
- CSRF hidden field remains unchanged.
- Hidden `section=establishment` remains unchanged.
- Save behavior remains controller/service-driven exactly as before.
- No change to SettingsController write flow.
- No change to SettingsService keys/contracts.

## Preserved posted fields (exact names)

- `settings[establishment.name]`
- `settings[establishment.phone]`
- `settings[establishment.email]`
- `settings[establishment.address]`
- `settings[establishment.currency]`
- `settings[establishment.timezone]`
- `settings[establishment.language]`
