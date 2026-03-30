# Clients Phase 2.1 Progress

## Changed Files

- `system/modules/clients/controllers/ClientController.php`
- `system/modules/clients/services/ClientService.php`
- `system/modules/clients/repositories/ClientRepository.php`
- `system/modules/clients/repositories/ClientFieldDefinitionRepository.php`
- `system/modules/clients/repositories/ClientFieldValueRepository.php`
- `system/modules/clients/views/index.php`
- `system/modules/clients/views/show.php`
- `system/modules/clients/views/create.php`
- `system/modules/clients/views/edit.php`
- `system/modules/clients/views/merge-preview.php`
- `system/modules/clients/views/custom-fields-index.php`
- `system/modules/clients/views/custom-fields-create.php`
- `system/routes/web.php`
- `system/modules/bootstrap.php`
- `system/data/migrations/040_clients_merge_and_custom_fields.sql`
- `system/data/full_project_schema.sql`
- `system/docs/clients-phase-2-1-progress.md`

## Routes Touched / Added

- Added:
  - `GET /clients/merge`
  - `POST /clients/merge`
  - `GET /clients/custom-fields`
  - `GET /clients/custom-fields/create`
  - `POST /clients/custom-fields`
  - `POST /clients/custom-fields/{id}`
- Existing client CRUD and detail routes are unchanged and still active.

## Tables Added / Changed

- **Changed** `clients`:
  - added `merged_into_client_id`, `merged_at`
  - added `fk_clients_merged_into` self-reference
- **Added** `client_field_definitions`
- **Added** `client_field_values`

## Duplicate Detection Foundation

- Added backend duplicate search by:
  - full name
  - phone
  - email
- Supports:
  - exact matching
  - optional safe partial matching
- Wired simple UI on clients index for duplicate checks.

## Merge Rules Used

- Primary and secondary clients must be different existing active records.
- Secondary already-merged client is rejected.
- Merge is transactional.
- Secondary is soft-closed after merge:
  - `deleted_at = NOW()`
  - `merged_into_client_id = primary_id`
  - `merged_at = NOW()`
- Primary is preserved as authoritative record; selected empty fields can be backfilled from secondary (`phone`, `email`, `birth_date`, `gender`).
- Notes are combined safely when secondary note is not already present.
- Audit logs:
  - preview: `client_merge_previewed`
  - merge: `client_merged`

## Linked Records Re-mapped During Merge

- Re-mapped from secondary `client_id` to primary `client_id`:
  - `appointments`
  - `invoices`
  - `gift_cards`
  - `client_packages`
  - `appointment_waitlist`
  - `client_notes`
- `client_field_values` merged safely with dedupe logic:
  - keep primary value if already present
  - fill missing primary value from secondary
  - remove secondary values after merge

## Custom Fields Backend Foundation

- Added definition repository/service flow:
  - create/list/update custom field definitions
- Added values repository/service flow:
  - upsert per client/field
- Integrated custom field values into client create/edit/show with low-risk form/table rendering.
- Added minimal admin/manage pages:
  - list fields
  - create field
  - toggle/update field basics

## What Is Postponed to Phase 2.2

- Advanced merge conflict resolution UI (field-by-field chooser).
- Bulk merge operations and dedupe scoring/ranking.
- Type-specific custom field UI widgets (select/boolean richer controls).
- Branch-scoped custom field admin UX refinements.
- Validation policies for custom field options JSON schema.
