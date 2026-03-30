# SETTINGS-SECONDARY-CONTACT-BACKEND-CONNECTION-13

## Why settings keys were used instead of a separate table

Secondary Contact is a fixed, single contact block per branch, not a multi-row domain entity. The existing settings infrastructure already provides branch-scoped key/value storage, validation, and safe patch semantics, so this wave uses branch-scoped settings keys rather than introducing a dedicated relational table.

## Exact keys added

Added to establishment settings model:

- `establishment.secondary_contact_first_name`
- `establishment.secondary_contact_last_name`
- `establishment.secondary_contact_phone`
- `establishment.secondary_contact_email`

## Branch-scoped behavior

- Read path resolves active branch context for the secondary-contact screen and overview card.
- Save path uses `patchEstablishmentSettings(..., $branchId)` with the resolved active branch id.
- Only these four secondary-contact fields are submitted/saved from the dedicated screen branch.
- Unrelated establishment keys are not overwritten by this flow.

## Validation rules

Validation is handled through settings service patch validation:

- `secondary_contact_first_name`: optional, max 100
- `secondary_contact_last_name`: optional, max 100
- `secondary_contact_phone`: optional, max 50
- `secondary_contact_email`: optional, max 255, must be valid email if present
- All four fields may be blank (meaning no configured secondary contact)

On validation failure:

- user stays on the focused screen via redirect back to the same state
- error flash is shown
- submitted secondary-contact values are preserved in flash and re-rendered

## Screen behavior

`/settings?section=establishment&screen=edit-secondary-contact` now renders a real editor with:

- First Name
- Last Name
- Phone
- Email
- Save Changes action
- Back to Overview action

If no active branch context is available, a safe non-editable state is shown.

## Overview behavior

Establishment overview Secondary Contact card now reflects real backend data:

- clean empty state when no secondary contact is configured
- summary values when at least one field is configured
- branch-context warning when context is unavailable
- CTA remains `Edit Secondary Contact`
