# TENANT-OWNED-DATA-PLANE-HARDENING-01 Scope Matrix

Date: 2026-03-23

Status vocabulary: `DONE` / `PARTIAL` / `OPEN` / `RUNTIME-PROOF-MISSING` / `DEFERRED`.

## In-scope module methods (protected tenant runtime)

- Clients
  - `ClientRepository::find/list/count/findForUpdate` -> `DONE` (org-scoped read)
  - `ClientRepository::update/softDelete/restore` -> `DONE` (scoped-by-id writes)
  - `ClientService::{create,update,delete,notes,merge,custom fields}` -> `DONE` (resolved tenant scope required)
- Staff
  - `StaffRepository::findByUserId/find/list/count` -> `DONE` (org-scoped read)
  - `StaffRepository::update/softDelete` -> `DONE` (scoped-by-id writes)
  - `StaffService::{create,update,delete}` -> `DONE` (resolved tenant scope required)
- Services
  - `ServiceRepository::find/list/count` -> `DONE` (org-scoped read)
  - `ServiceRepository::update/softDelete` -> `DONE` (scoped-by-id writes)
  - `ServiceService::{create,update,delete}` -> `DONE` (resolved tenant scope required)
- Appointments
  - `AppointmentRepository::find/list/count` -> `DONE` (org-scoped read)
  - `AppointmentRepository::update/softDelete` -> `DONE` (scoped-by-id writes)
  - `AppointmentService` protected write paths (`create/update/cancel/reschedule/updateStatus/delete/createFromSlot`) -> `DONE` (resolved tenant scope + branch ownership checks)
  - Cross-entity link assertions (`client/service/staff/room`) on appointment write paths -> `DONE`

## Intentionally deferred / out of scope in this wave

- Inventory, memberships, reports, mixed-sales, public commerce/storefront/public booking lanes -> `DEFERRED`
- Full repository architecture rewrite across all modules -> `DEFERRED`
- Lifecycle/suspension enforcement wave work -> `DEFERRED`
- Schema-wide invariant redesign -> `DEFERRED`

## Residual risk after -01

- Non-protected or out-of-scope modules may still include caller-discipline scoping and require future wave expansion.
