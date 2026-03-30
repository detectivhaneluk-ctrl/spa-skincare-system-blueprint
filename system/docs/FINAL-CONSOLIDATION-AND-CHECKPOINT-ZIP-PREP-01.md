# FINAL-CONSOLIDATION-AND-CHECKPOINT-ZIP-PREP-01

Repo-local consolidation for the current wave set (seal / checkpoint). No new product features.

## Tasks 1–3 (consolidated scope)

1. **Invoice sequence (phase 2 / hotspot documentation)** — Global `invoice_number_sequences` contract documented (migration 112), `SEQUENCE_KEY_INVOICE_GLOBAL` + bound `sequence_key` in `InvoiceRepository`; verifier: `system/scripts/read-only/verify_invoice_number_sequence_hotspot_readonly_01.php`. Scoped per-tenant numbering is still roadmap (plan doc), not implemented here.
2. **Search scalability hardening (wave 01)** — Client directory + invoice list: behavior-preserving fast paths (email / digit phone / canonical `INV-[digits]`); `PublicContactNormalizer::sqlExprNormalizedPhoneDigits`; verifier: `system/scripts/read-only/verify_search_scalability_hardening_wave_01_readonly.php`.
3. **Cross-module weak-note cleanup** — `MembershipSaleRepository::listByInvoiceId` and all `MembershipBillingCycleRepository` invoice-joined DISTINCT lists use the same **invoice-plane try/catch** as reconcile id discovery; verifier inventory updated: `system/scripts/read-only/verify_cross_module_invoice_payment_read_guard_readonly_01.php`.

## Related waves verified in the same run (still current)

- Hot-table indexes (migration 114): `verify_scalability_hot_table_indexes_readonly_01.php`
- Invoice list date sargability (migration 115): `verify_invoice_list_date_filter_sargability_readonly_01.php`
- Appointment buffered conflict sargability: `verify_appointment_conflict_buffer_sargability_readonly_01.php`
- Sales invoice/payment read + mutation guards: `verify_sales_invoice_payment_tenant_*_readonly_01.php`
- Payroll invoice guard: `verify_payroll_invoice_payment_tenant_guard_readonly_01.php`
- Public commerce boundary: `verify_sales_public_commerce_boundary_readonly_01.php`
- Foundation: `verify_organization_context_resolution_readonly.php`, `verify_organization_scoped_choke_points_foundation_11_readonly.php`
- Client list/count org scope (F-18): `verify_client_repository_org_scope_foundation_18_readonly.php` — expectations updated so `list`/`count` needles match delegation to `applyClientListFilters` after search hardening.

## Verifiers executed (PHP, this checkpoint)

All of the above **exit 0** when run from repo root with Laragon PHP 8.3.x, except as noted below.

- **Handoff ZIP rules:** `verify_handoff_zip_rules_readonly.php` run against `distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip` with `-d extension=…/php_zip.dll` when the default `php.ini` disables `zip`.

## Migration baseline (local DB truth)

`verify_migration_baseline_readonly.php` (without `--strict`) **reports** alignment for the **connected database** only. On the consolidation machine this run showed **pending** SQL files (e.g. 112, 114, 115) and an **orphan stamp** — **environment / DB state**, not a static repo failure. Use `php system/scripts/migrate.php` (and optional `--verify-baseline`, `--strict`) on each deployment before enabling `MIGRATION_BASELINE_ENFORCE`.

## Residual notes (non-blocking unless stated)

- Cross-module verifier **weak** list: payroll pointer, unscoped membership PK reads by design, repair CLI unset-org global visibility — **documented, non-blocking** when callers/scripts are correct.
- **Migration baseline drift** on a dev DB: **blocking for production** only if strict baseline enforcement is on without applying pending migrations.

## Canonical artifact

Built only via `handoff/build-final-zip.ps1` → **`distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip`** (excludes `.env`, logs, backups, nested zips, etc., per `HandoffZipRules.ps1`).
