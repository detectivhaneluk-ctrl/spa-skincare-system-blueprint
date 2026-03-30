# documents

Documents and consents.

## Responsibility
- Consent templates
- Service-linked consents
- E-signature
- Signature vault
- Version history
- Legal consent management

## Dependencies
- `/system/core` (files, audit)
- `/system/shared`
- Approved contracts: clients, services-resources

## Boundaries
- Does not access clients/services repositories directly
- Uses core file engine for storage
- Document linkage via entity IDs passed from callers
