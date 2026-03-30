# settings

System configuration.

## Responsibility
- Business data
- Branches, currency, timezone
- Service settings, room settings, equipment settings
- Payment methods, VAT rates
- Booking rules, cancellation rules, no-show rules
- Message templates, numbering rules
- Languages, integrations
- Roles and permissions UI

## Dependencies
- `/system/core` only

## Boundaries
- Does not import from other business modules
- Core: permissions (for RBAC UI), audit
- Settings storage in core; this module is the admin UI
