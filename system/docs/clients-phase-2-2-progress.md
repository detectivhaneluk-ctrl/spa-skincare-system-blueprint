# Clients Phase 2.2 Progress

## Changed Files

- `system/modules/clients/controllers/ClientController.php`
- `system/modules/clients/services/ClientService.php`
- `system/modules/clients/services/ClientRegistrationService.php`
- `system/modules/clients/services/ClientIssueFlagService.php`
- `system/modules/clients/repositories/ClientRepository.php`
- `system/modules/clients/repositories/ClientRegistrationRequestRepository.php`
- `system/modules/clients/repositories/ClientIssueFlagRepository.php`
- `system/modules/clients/views/index.php`
- `system/modules/clients/views/create.php`
- `system/modules/clients/views/edit.php`
- `system/modules/clients/views/show.php`
- `system/modules/clients/views/registrations-index.php`
- `system/modules/clients/views/registrations-create.php`
- `system/modules/clients/views/registrations-show.php`
- `system/routes/web.php`
- `system/modules/bootstrap.php`
- `system/data/migrations/041_clients_intake_registration_flags.sql`
- `system/data/full_project_schema.sql`
- `system/docs/clients-phase-2-2-progress.md`

## Routes Added / Touched

- Added:
  - `GET /clients/registrations`
  - `GET /clients/registrations/create`
  - `POST /clients/registrations`
  - `GET /clients/registrations/{id}`
  - `POST /clients/registrations/{id}/status`
  - `POST /clients/registrations/{id}/convert`
  - `POST /clients/{id}/flags`
  - `POST /clients/flags/{id}/resolve`
- Kept existing client CRUD, merge, and custom-field routes unchanged.

## Tables Added / Changed

- **Changed** `clients`:
  - added `preferred_contact_method`
  - added `marketing_opt_in`
- **Added** `client_registration_requests`
- **Added** `client_issue_flags`

## Web Registration Conversion Flow

- Registration requests are stored as `new` by default with source and branch context.
- Review can update status to `reviewed`, `rejected`, or keep `new` (with optional note append).
- Convert flow supports:
  - linking to an existing client (`existing_client_id`)
  - creating a new client from request data (name split, phone/email carried, notes tagged with registration source)
- On successful conversion:
  - registration status becomes `converted`
  - `linked_client_id` is set
  - conversion audit is written

## Issue Flag Types Supported

- `invalid_payment_card`
- `account_follow_up`
- `front_desk_warning`

Flag lifecycle in this phase:
- create flag as `open`
- resolve flag to `resolved` with `resolved_by` and `resolved_at`
- show active (`open`) flags directly on client profile with quick resolve action

## Audit Events Added

- `client_registration_created`
- `client_registration_reviewed`
- `client_registration_converted`
- `client_issue_flag_created`
- `client_issue_flag_resolved`

## What Is Postponed to Phase 2.3

- Public-facing unauthenticated registration endpoint and anti-spam controls.
- Bulk registration triage and assignment queues.
- Auto-duplicate suggestion/ranking during registration review.
- Advanced issue-flag workflow (severity levels, SLA, reminders, ownership).
- Rich intake policies and per-branch intake templates.
