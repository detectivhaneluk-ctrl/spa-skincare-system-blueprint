# clients

Client management and CRM.

## Responsibility
- Client list, CRUD, merge duplicates
- Client profile: visits, finances, packages, gift cards
- Medical card, documents, gallery, loyalty, timeline

## Dependencies
- `/system/core`
- `/system/settings`
- `/system/shared`

## Boundaries
- Does not import from other business modules
- Core: audit, files, search, branches
- Shared: forms, tables, timelines, upload
