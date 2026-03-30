# DB truth map — high-risk domains (FOUNDATION-DB-TRUTH-OBSERVABILITY-AND-SCALE-PROOF-03)

This document is an **operational audit snapshot**: what the schema already enforces, what remains application-driven, and what is **deferred** until a data cleanup plan exists.

## Summary

| Domain | FK / uniqueness already strong | Gaps / nullable risk | Wave-03 action |
|--------|-------------------------------|----------------------|----------------|
| Auth (users, resets) | `user_password_reset_tokens.user_id` FK | `login_attempts` unscoped (by design for abuse log) | None (no blind FK) |
| Appointments | `client_id`, `service_id`, `staff_id`, `branch_id` FKs; `uq_appointments_series_start` | `client_membership_id` **no FK** — possible orphans if rows deleted out of band | **Deferred**: add `fk_appointments_client_membership` only after `SELECT COUNT(*)` orphan check = 0 |
| Clients | `branch_id` FK; merge self-FK | `branch_id` nullable — legacy/global rows | App guards; **no NOT NULL** without backfill |
| Payments / sales | `payments.invoice_id` CASCADE; invoice→client/appointment FKs | Hot invoice number sequence (documented elsewhere) | No schema change this wave |
| Documents / media | `document_links` unique active owner; `documents.storage_path` unique | Polymorphic `owner_type`/`owner_id` not FK-enforced | **Application + proofs** only |
| Intake | Template/assignment tables tenant-scoped in app | Mixed FK coverage per table | See per-table in future wave |
| Audit | `actor_user_id`, `branch_id` FKs | Missing tenant + request correlation | **Migration 125** |

## Appointments — `client_membership_id`

- **Risk:** Orphan pointer breaks membership reconciliation queries.
- **Pre-migration check (run manually):**
  ```sql
  SELECT COUNT(*) FROM appointments a
  LEFT JOIN client_memberships m ON m.id = a.client_membership_id
  WHERE a.client_membership_id IS NOT NULL AND m.id IS NULL AND a.deleted_at IS NULL;
  ```
- If count > 0: fix or null out bad rows before adding:
  `FOREIGN KEY (client_membership_id) REFERENCES client_memberships(id) ON DELETE SET NULL`.

## Clients — `branch_id` NULL

- **Risk:** Tenant listing queries must filter `branch_id` + `deleted_at`; NULL rows are “global” legacy.
- **Enforcement:** `TenantOwnedDataScopeGuard` / repositories — not safe to `NOT NULL` without org-wide backfill.

## Document links — polymorphic owner

- **Risk:** Wrong `owner_id` cannot be FK’d without typed tables or CHECK constraints MySQL lacks.
- **Mitigation:** Unique constraint on `(document_id, owner_type, owner_id, status)` already reduces duplicate links; integrity stays **application-enforced**.

## Audit logs — tenant + request (migration 125)

- Adds `organization_id` (FK `organizations`), `request_id`, `outcome`, `action_category` and indexes for tenant-scoped SIEM-style queries.
- Backfill: `organization_id` from `branches.organization_id` where `branch_id` is set.

## Runtime async jobs — lag index (migration 126)

- Composite `(queue, status, updated_at, id)` for ops dashboards and “stuck consumer” detection.

## Session / Redis

- Session durability is runtime config (`SESSION_DRIVER`), not a SQL table — see `RUNTIME-DISTRIBUTED-PLANES-02.md`.
