# Database Canonicalization Plan

**Provisional / historical baseline:** Written when the migration chain was described as `001`–`038`; the live tree continues well beyond that. Authoritative incremental truth is `system/data/migrations/*.sql` plus `full_project_schema.sql`. Optional runtime tolerance for missing objects (older DBs) is documented in **`SCHEMA-COMPATIBILITY-SHIMS.md`**.

## Scope

Current canonical scope includes implemented modules only:

- core/foundation
- auth
- permissions
- settings
- audit
- clients
- staff
- services/resources
- appointments
- sales (invoices/payments foundation)
- inventory
- gift cards
- packages
- appointments-packages integration foundation

No deferred modules are included.

## Keep (Canonical)

- Migration chain `001` to `038` remains the historical source of incremental evolution.
- `scripts/migrate.php` remains default incremental runner (legacy-tolerant for reused DBs).
- `system/data/full_project_schema.sql` is now canonical snapshot (non-destructive, no seed data).
- `scripts/seed.php` and seeders now align with canonical settings/permissions expectations.

## Refactor

- `scripts/migrate.php`
  - Added `--strict` for fail-fast migration validation.
  - Added `--canonical` for clean snapshot bootstrap on empty DB + migration stamping.
  - Kept reused DB policy explicit and backward-compatible.
- `scripts/seed.php`
  - Normalized bootstrap flow.
  - Runs role/permission seeding and baseline settings seeding.
- Added `scripts/reset_dev.php`
  - Explicitly destructive local reset path only when confirmed (`--yes`).
  - Rebuilds from canonical schema, stamps migrations, and seeds baseline data.

## Obsolete / Legacy / Noisy Items Found

- Historical schema transition noise:
  - `011_alter_audit_logs_schema.sql`
  - `012_alter_settings_schema.sql`
  - `014_settings_schema_corrections.sql`
  These are still required for legacy incremental chains but are no longer needed in canonical snapshot builds.

- Placeholder/legacy scaffolding table:
  - `staff_schedules` from `018_create_staff_schedules_placeholder.sql`
  Kept in canonical schema for compatibility, but currently minimal-use/placeholder.

- Unused-in-runtime-but-retained compatibility tables:
  - `client_notes`
  Kept to avoid data/model regression and preserve module boundary evolution.

## Legacy Compatibility Notes

- Settings architecture intentionally uses `branch_id = 0` for global default scope (not FK-bound).
- Incremental migrations keep legacy tolerance by default to support reused development DBs.
- For strict CI/local verification, run migrations with `--strict`.

## Canonical Table List by Module

- **Core/Auth/Permissions/Settings/Audit**
  - `branches`, `roles`, `permissions`, `role_permissions`, `users`, `user_roles`, `settings`, `audit_logs`, `login_attempts`, `migrations`
- **Clients**
  - `clients`, `client_notes`
- **Staff**
  - `staff`, `staff_schedules`, `staff_groups`, `staff_group_members`, `staff_group_permissions` (admin JSON: **`GET`/`POST /staff/groups/{id}/permissions`** → **`StaffGroupPermissionService`**, canonical **`permissions`** catalog + pivot replace)
- **Services & Resources**
  - `service_categories`, `services`, `rooms`, `equipment`, `service_staff`, `service_staff_groups` (maintained via authenticated service create/update: **`staff_group_ids`** → **`ServiceStaffGroupRepository::replaceLinksForService`**), `service_rooms`, `service_equipment`
- **Appointments**
  - `appointments`, `appointment_waitlist` (slot-freed / expiry-chain auto-offer: per-context MySQL **`GET_LOCK`** `wl_slot_offer_` + SHA256 of **branch + `preferred_date` + `service_id` + `preferred_staff_id`**, matching **`WaitlistRepository::existsOpenOfferForSlot`**; audits **`waitlist_slot_offer_duplicate_prevented`** / **`waitlist_offer_created`**; expiry sweep lock **`spa_waitlist_expiry_sweep`** unchanged — **WAITLIST-SLOT-FREED-CONCURRENCY-HARDENING-01** / **WAITLIST-SLOT-FREED-OFFER-CONCURRENCY-HARDENING-01**)
- **Sales**
  - `invoices`, `invoice_items`, `payments`
- **Inventory**
  - `products`, `suppliers`, `stock_movements`, `inventory_counts`
- **Gift Cards**
  - `gift_cards`, `gift_card_transactions`
- **Packages**
  - `packages`, `client_packages`, `package_usages`
- **Memberships**
  - `membership_definitions` (billing columns on create/update: staff **`MembershipDefinitionController`** → **`MembershipService`**, same fields as schema; no extra HTTP-only keys), `client_memberships` (migrations `067` billing columns on both tables + `070_membership_definitions_billing_columns_align.sql` idempotent repair: one `ADD COLUMN` per billing field so partially migrated DBs gain only missing columns; duplicate-column errors tolerated by default `scripts/migrate.php`; `069_client_memberships_lifecycle.sql` for `paused` status, `cancel_at_period_end`, `cancelled_at`, `paused_at`, `lifecycle_reason`; lifecycle transitions via `MembershipLifecycleService`, also reachable via `POST /memberships/client-memberships/{id}/pause|resume|schedule-cancel-at-period-end|revoke-scheduled-cancel`), `membership_benefit_usages`, `membership_billing_cycles` (billing-cycle money state is derived from canonical `invoices` / payment rows via `MembershipBillingService::syncBillingCycleForInvoice`), `membership_sales` (migration `068_membership_sales_initial_sale.sql`; initial-sale activation via `MembershipSaleService::syncMembershipSaleForInvoice` after canonical invoice/payment mutations; initial row + invoice creation also reachable via `POST /memberships/sales` → `MembershipSaleService::createSaleAndInvoice`)
  - **Initial membership sale checkout (canonical):** **`MembershipSaleService::createSaleAndInvoice`** is the single primitive for staff-facing initial sale: invoked from **`POST /memberships/sales`** and from **`InvoiceController::store`** when **`membership_definition_id` > 0** (optional form **`branch_id`**, **`membership_starts_at`** → payload **`starts_at`**, invoice **`notes`**). Creates **`membership_sales`** (starts **`draft`**, then **`invoiced`** once linked) + canonical invoice **`open`** with tagged **`notes`**; staff invoice path appends **`[checkout:staff_invoice]`** and extra audits. **Activation** only after **full pay** via **`syncMembershipSaleForInvoice`** (lazy resolver from **`PaymentService`** / **`InvoiceService`**); **`settleSingleSaleLocked`** guards **`activation_applied_at`** / **`client_membership_id`** so membership is applied **once**.
  - **Membership invoice issuance — duplicate protection (authoritative):** *Initial sale* — one DB transaction; `clients` row `SELECT … FOR UPDATE` serializes concurrent `createSaleAndInvoice` for the same client; `findBlockingOpenInitialSale` rejects another sale for the same client + definition + branch while `membership_sales.status` is `draft`, `invoiced`, `paid`, or `refund_review` (covers paid-invoice → activation latency and double POST). **Client membership row creation** — only via `MembershipService::assignToClientAuthoritative`: `clients` `FOR UPDATE`, branch-scope checks, overlap / in-flight guard (`ClientMembershipRepository::findBlockingIssuanceRow`), audits `client_membership_issuance_denied_branch_mismatch` / `client_membership_issuance_denied_duplicate_overlap`; migration **`071_client_memberships_issuance_guard_index.sql`** adds `idx_client_memberships_client_def_branch` for that lookup. *Renewal* — per membership, `processDueRenewalSingle` runs in a transaction with `client_memberships` + definition `FOR UPDATE`; `membership_billing_cycles` unique `(client_membership_id, billing_period_start, billing_period_end)` plus insert `1062` handling prevents a second cycle/invoice for the same period; `memberships_cron.php` uses non-distributed `flock` so overlapping cron instances exit without duplicating work (standalone `memberships_process_billing.php` has no flock but still relies on the same row locks + unique key).

### Production: `membership_definitions.billing_enabled` missing (1054)

Canonical columns are defined in `067_membership_subscription_billing_foundation.sql` (full stack) and repaired per-column in `070_membership_definitions_billing_columns_align.sql`. **Deterministic fix:** from the `system/` directory, run `php scripts/migrate.php` (default mode, not `--strict`). That applies any pending numbered migrations; `070` adds only columns that are absent. If `070` is already listed in table `migrations` but the live table still lacks columns (manual `migrations` drift), remove that `070` row after backup and re-run migrate, or execute the `070` SQL manually once.

### Memberships schema drift check (read-only)

Before deploy or when diagnosing membership HTTP/SQL errors, run **`php scripts/verify_memberships_schema.php`** from the `system/` directory. It compares the live database to the tables/columns/uniques the membership module expects (`membership_definitions` billing fields, `client_memberships` billing + lifecycle fields, `membership_billing_cycles`, `membership_sales`, and critical unique indexes). **Exit code 0 = PASS**, **1 = FAIL** with a list of gaps and the same repair path as `migrate.php` above. It does not modify the database.

## Recommended Reset Workflow (Local/Dev)

1. Confirm `.env` points to local dev database.
2. Run:
   - `php scripts/reset_dev.php --yes`
3. Create initial login user if needed:
   - `php scripts/create_user.php email@example.com strong_password`

Alternative clean setup without destructive reset:

1. Create an empty database manually.
2. Run:
   - `php scripts/migrate.php --canonical`
   - `php scripts/seed.php`

## Data-Loss Warning

- `scripts/reset_dev.php --yes` drops all tables in the configured database.
- Do not run against production/staging data.
- Production safety remains explicit: reset script refuses production env unless `--force` is given.
