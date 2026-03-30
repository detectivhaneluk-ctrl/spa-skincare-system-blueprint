# CLIENTS-TENANT-PII-BOUNDARY-REPAIR (WAVE-03A)

Date: 2026-03-23  
Status: DONE (tenant-protected clients PII boundary repair)

## Tenant-scoped operations now enforced

- `ClientRepository::findDuplicates()` and `::searchDuplicates()` now append tenant org-owned-branch EXISTS scope via `OrganizationRepositoryScope`.
- `ClientRepository::listNotes()`, `::findNoteForClient()`, `::softDeleteNoteForClient()`, and `::listAuditHistory()` now join through `clients` and apply tenant scope predicates.
- Merge helper paths `::countLinkedRecords()`, `::remapClientReferences()`, and `::markMerged()` now assert in-scope client ids before reading/mutating linked data.
- `ClientService::findDuplicates()` and `::searchDuplicates()` now require resolved tenant scope before repository access.

## Legacy id-only/global behaviors removed

- Duplicate discovery no longer runs unscoped `clients` queries.
- Note lookup/delete no longer run by raw note id only.
- Audit history no longer reads by `audit_logs.target_id` without tenant client scope.
- Merge linked-record/read-write helpers no longer run without a scoped client guard.

## Fail-closed behavior

- All protected duplicate/note/history/merge helper paths now require canonical tenant context through `OrganizationRepositoryScope` and/or explicit scoped-client assertions.
- If tenant context is unresolved or not branch-derived, protected client repository methods fail closed by `DomainException` (via core protected scope contract), not by global fallback queries.
