# Backend architecture truth map — CHARTER-01

**Scope:** Repository snapshot for foundation hardening (routing, auth, tenancy, settings, invoices, migrations, packaging, audits). UI omitted.

## Entry and bootstrap

| Concern | Owner files |
|--------|-------------|
| HTTP entry | **Production:** `system/public/index.php` only (`DocumentRoot` = `system/public`). Repo-root `index.php` is dev-only if vhost points at package root — see `DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01.md`. |
| Core bootstrap + base DI | `system/bootstrap.php` |
| Module DI graph | `system/modules/bootstrap.php` |
| App run / router build | `system/core/app/Application.php` (`run`, `buildRouter` → `system/routes/web.php`) |

## Routing and HTTP pipeline

| Concern | Owner files |
|--------|-------------|
| Central routes | `system/routes/web.php` (+ module `routes/web.php` included from there) |
| Dispatch + global middleware order | `system/core/router/Dispatcher.php` — `CsrfMiddleware`, `ErrorHandlerMiddleware`, `BranchContextMiddleware`, `OrganizationContextMiddleware`, then route middleware |
| Router match | `system/core/router/Router.php`, `Dispatcher::dispatch` |

## Auth and session

| Concern | Owner files |
|--------|-------------|
| Session + user row load | `system/core/auth/SessionAuth.php` |
| Auth facade (check / user / login) | `system/core/auth/AuthService.php` |
| Authenticated gate + security settings + inactivity | `system/core/middleware/AuthMiddleware.php` |
| Permissions | `system/core/middleware/PermissionMiddleware.php` (per route) |
| Login throttle | `system/core/auth/LoginThrottleService.php` |

## Tenant / organization / branch

| Concern | Owner files |
|--------|-------------|
| Branch resolution | `system/core/middleware/BranchContextMiddleware.php`, `BranchContext` (registered in bootstrap) |
| Organization context | `system/core/Organization/OrganizationContext.php`, `OrganizationContextMiddleware.php`, `OrganizationContextResolver.php` |
| Service-level org asserts (examples) | `OrganizationScopedBranchAssert`, `SalesTenantScope`, docs F-07–F-11 |
| Repository org scope helper (pattern) | `system/core/Organization/OrganizationRepositoryScope.php` |

## Settings

| Concern | Owner files |
|--------|-------------|
| Read/write merge (branch / org / platform) | `system/core/app/SettingsService.php` |
| Timezone / content-language hooks | `system/core/app/ApplicationTimezone.php`, branch middleware sync (see `Dispatcher` docblock) |
| Read-scope inventory | `system/docs/SETTINGS-READ-SCOPE.md` |

## Invoices and sequence hotspot

| Concern | Owner files |
|--------|-------------|
| Invoice persistence + **global** `invoice_number_sequences` row (`SEQUENCE_KEY_INVOICE_GLOBAL`, `FOR UPDATE`) | `system/modules/sales/repositories/InvoiceRepository.php` |
| Invoice domain service (allocates number on create) | `system/modules/sales/services/InvoiceService.php` |
| Tenant sales scope helper | `system/modules/sales/services/SalesTenantScope.php` |
| Hotspot contract + options (per-org target) | `system/docs/INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01.md` |
| Static verifier | `system/scripts/read-only/verify_invoice_number_sequence_hotspot_readonly_01.php` |

**Scale note:** Single sequence row remains the cross-tenant lock until a scoped-sequence phase (see plan doc).

## Migrations and schema health

| Concern | Owner files |
|--------|-------------|
| Incremental / canonical migrate | `system/scripts/migrate.php` (deploy-safe: add `--verify-baseline`; see FND-MIG-02) |
| Deploy baseline gate (wrapper) | `system/scripts/run_migration_baseline_deploy_gate_01.php` (FND-MIG-02) |
| Non-strict stamp proof helper | `system/scripts/migrate_end_state_verify.php` |
| Canonical snapshot | `system/data/full_project_schema.sql` |
| Migration SQL | `system/data/migrations/*.sql` |
| Migration baseline (disk ↔ `migrations` table) | `system/core/app/MigrationBaseline.php`; `system/scripts/read-only/verify_migration_baseline_readonly.php` (`--strict`, `--json`); `system/scripts/read-only/smoke_migration_baseline_enforcement_foundation_01.php`; optional HTTP gate `config('migration_baseline_enforce')` |
| ZIP / wave truth checkpoint | `system/docs/ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` |
| Core schema compat proof | `system/scripts/verify_core_schema_compat_readonly.php` (referenced in ops docs) |

## Packaging and handoff

| Concern | Owner files |
|--------|-------------|
| Build distributable ZIP + mandatory post-build verify | `handoff/build-final-zip.ps1` (PLT-REL-01 Tier A → pack per rules → `Get-HandoffZipForbiddenEntries` → `verify_handoff_zip_rules_readonly.php`) |
| Shared exclusion rules | `handoff/HandoffZipRules.ps1` (keep aligned with PHP verifier) |
| Verify any ZIP artifact (acceptance / re-upload) | `handoff/verify-handoff-zip.ps1` or `verify_handoff_zip_rules_readonly.php` on exact bytes shipped |
| Local tree awareness (informational) | `handoff/verify-repo-worktree-hygiene.ps1` |
| PHP ZIP verifier (mandatory twin in build) | `system/scripts/read-only/verify_handoff_zip_rules_readonly.php` |
| Ignore rules | root `.gitignore`, `system/.gitignore` |
| Hygiene narrative | `system/docs/REPO-CLEANUP-NOTES.md` |

## Read-only / audit / smoke scripts (sample)

| Area | Examples under `system/scripts/` |
|------|----------------------------------|
| Read-only verifiers | `read-only/*`, `audit_*_readonly.php`, `verify_*_readonly.php` |
| Tenant footgun scans | `verify_tenant_repository_footguns.php`, `verify_null_branch_catalog_patterns.php` |
| Smoke | `smoke_foundation_hardening_wave_01.php`, `smoke_sales_tenant_data_plane_hardening_01.php`, `smoke_foundation_minimal_regression_wave_01.php`, `smoke_*` |

## Automated tests

No PHPUnit project config in tree; regression is primarily **read-only PHP CLIs** and smoke scripts. Minimum auth/permissions smoke referenced in `BOOKER-PARITY-MASTER-ROADMAP.md` §6.3. **Platform backlog order:** `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` §B.
