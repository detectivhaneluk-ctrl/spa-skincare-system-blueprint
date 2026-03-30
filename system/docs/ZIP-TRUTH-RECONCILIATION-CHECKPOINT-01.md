# ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01

**Scope:** Repo-local canonical truth for waves that a **fresh full-project ZIP audit** may have reported as missing. This checkpoint was produced by **comparing claims to files on disk in this worktree** (2026-03-28), not by trusting an external report alone.

**Canonical upload artifact:** `distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip`  
**Build:** `handoff/build-final-zip.ps1` (rules: `handoff/HandoffZipRules.ps1`)  
**Post-build verify (mandatory, automatic):** same script runs `Get-HandoffZipForbiddenEntries` (PowerShell) **and** `php system/scripts/read-only/verify_handoff_zip_rules_readonly.php` on the produced file — non-zero **deletes** the output ZIP.  
**Standalone acceptance (any artifact bytes):** `php system/scripts/read-only/verify_handoff_zip_rules_readonly.php <path-to.zip>` **or** `handoff/verify-handoff-zip.ps1 -ZipPath <path>` (same rules).  
**Platform execution order (tenant + packaging gates):** `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` §B.

---

## PLT-PKG-08 + FND-PKG-01 — Mandatory packaging rules & release/checkpoint checklist (2026-03-28)

### Mandatory rule set (distributable handoff ZIP)

**Forbidden entries** (normalized ZIP path):

| Pattern | Rationale |
|---------|-----------|
| `system/.env`, `system/.env.local` | Runtime secrets |
| Any `**/*.zip` | Nested / generated archives — not canonical source tree |
| Any `**/*.log` | Runtime logs |
| `system/storage/logs/**` | Local log/debug storage |
| `system/storage/backups/**` | Local backup dumps |
| `workers/image-pipeline/node_modules/**` | npm install output |
| `system/docs/*-result.txt` | Pasted proof transcripts (suffix match is case-insensitive in verifier) |

**Explicitly allowed:** `system/.env.example` (non-secret template). All other paths follow normal repo packaging unless they match **Forbidden** above.

**Source of truth in code:** `handoff/HandoffZipRules.ps1` (`Test-HandoffPackagedPathForbidden` + `Get-HandoffZipForbiddenEntries`) and `system/scripts/read-only/verify_handoff_zip_rules_readonly.php` — **must stay aligned** when extending rules.

### Where enforcement runs (fail-closed)

| Stage | Failure-closed behavior |
|-------|-------------------------|
| **Build produced** | `handoff/build-final-zip.ps1` runs PLT-REL-01 Tier A → packs only paths that pass `HandoffZipRules` → writes ZIP → `Get-HandoffZipForbiddenEntries` (PowerShell) → `verify_handoff_zip_rules_readonly.php` on the ZIP. **Any step non-zero:** script throws; **output ZIP removed** if it was already created. |
| **Release / acceptance** | On the **exact artifact bytes** you will upload or hand off (including re-zipped or downloaded copies): `php system/scripts/read-only/verify_handoff_zip_rules_readonly.php "<path>"` **or** `handoff/verify-handoff-zip.ps1 -ZipPath "<path>"`. **Non-zero:** do not treat as handoff-clean — a prior successful build elsewhere does **not** certify this file. |

### Operator checklist (checkpoint / upload)

1. From repo root with `php` on PATH: CLI PHP must load **`extension=zip`** (`php -r "var_dump(extension_loaded('zip'));"` → `bool(true)`). Run `handoff/build-final-zip.ps1` (default output `distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip` or your `-OutputZip`).
2. Confirm the script prints the success lines and **no** exception — that implies PLT-REL-01 + dual ZIP verification passed.
3. **Before upload:** if the file left your machine (re-zipped, cloud drive, email), re-run `php system/scripts/read-only/verify_handoff_zip_rules_readonly.php "<that file>"` or `handoff/verify-handoff-zip.ps1 -ZipPath "<that file>"` on the **bytes you will ship**.
4. Optional cross-check: `handoff/verify-repo-worktree-hygiene.ps1` remains **informational only** (exit 0) — it does **not** replace steps 1–3.

---

## FND-MIG-02 — Migration baseline alignment & deploy gate (2026-03-28)

### What “aligned” means (mandatory checks)

`Core\App\MigrationBaseline::collect` defines **baseline_aligned** when **all** hold:

| Condition | Failure symptom |
|-----------|-----------------|
| `migrations` table exists | `migrations_table_missing` |
| Every `system/data/migrations/*.sql` has a row in `migrations` | **Pending** migrations (on disk, not stamped) |
| Every row in `migrations` matches a file on disk | **Orphan stamps** (DB row, file missing) |

This is **disk ↔ stamp** alignment only — not a semantic guarantee that live DDL matches `full_project_schema.sql`.

### Migration executed vs deploy-safe

| State | Meaning |
|-------|---------|
| **Migration executed** | `migrate.php` ran DDL and stamped (or tolerated legacy conflicts with end-state proof). |
| **Baseline verified / deploy-safe** | After apply, **or** read-only, strict check reports `baseline_aligned: yes` — **no** pending files, **no** orphan stamps, **no** missing `migrations` table. |

`migrate.php` **without** `--verify-baseline` may exit **0** with **orphan stamps** (stderr **WARNING** only). That is **not** deploy-safe.

### Commands (repo root; DB env required)

| Goal | Command | Exit non-zero when |
|------|---------|---------------------|
| **Preflight / post-deploy verify** (no DDL) | `php system/scripts/run_migration_baseline_deploy_gate_01.php` | Same as `verify_migration_baseline_readonly.php --strict` — any misalignment |
| **Apply + gate** (recommended deploy) | `php system/scripts/run_migration_baseline_deploy_gate_01.php --apply --strict` | DDL error (`--strict`), pending after loop, or baseline not aligned (`--verify-baseline` injected) |
| **Apply + gate** (legacy-tolerant DDL) | `php system/scripts/run_migration_baseline_deploy_gate_01.php --apply` | Pending after loop or baseline not aligned |
| **Direct** (equivalent) | `php system/scripts/migrate.php --strict --verify-baseline` | Same failure modes as apply row with `--strict` |
| **Report only** | `php system/scripts/read-only/verify_migration_baseline_readonly.php` / `--json` | `--strict` adds exit 1 when not aligned |

### Operator sequence (deploy / release)

1. Configure `system/.env` / `.env.local` so CLI bootstrap reaches the target database.
2. **Preferred:** `php system/scripts/run_migration_baseline_deploy_gate_01.php --apply --strict` (or `--apply` without `--strict` if you intentionally accept legacy-tolerant DDL).
3. If you only need to **confirm** alignment (no new migrations): `php system/scripts/run_migration_baseline_deploy_gate_01.php` — must exit **0** before serving production traffic (or rely on HTTP `migration_baseline_enforce` if enabled).
4. **Stop deploy** on any non-zero exit from steps 2–3; fix pending migrations, orphan stamps, or missing table before proceeding.

**Related:** optional HTTP 503 when misaligned — `MIGRATION_BASELINE_ENFORCE=true` / `config('migration_baseline_enforce')` (see `MigrationBaseline::respond503IfNotAligned`).

---

## PLT-REL-01 — Mandatory tenant-isolation proof gate (2026-03-28)

**Canonical runner (fail-closed, deterministic order):** `system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`

| Tier | When it runs | What fails the gate |
|------|----------------|---------------------|
| **A — Static** | Always (CI, local checkout, **before** `handoff/build-final-zip.ps1` creates the ZIP) | Any Tier A script in `run_mandatory_tenant_isolation_proof_release_gate_01.php` (incl. sales/payroll/PC static guards, footguns, merge-job + lifecycle + PC invoice correlation + **verify_invoice_client_read_envelope_fnd_tnt_06.php** + **verify_inventory_taxonomy_tenant_scope_readonly_01.php** + **verify_inventory_tenant_scope_followon_wave_02_readonly_01.php** + **verify_inventory_tenant_scope_followon_wave_03_readonly_01.php** + **verify_inventory_tenant_scope_followon_wave_04_readonly_01.php** + **verify_inventory_tenant_scope_followon_wave_05_readonly_01.php**, null-branch scan) exits non-zero |
| **B — Integration** | When explicitly requested: `--with-integration` or `TENANT_ISOLATION_GATE_INTEGRATION=1` after DB seed (`system/scripts/dev-only/seed_branch_smoke_data.php`) | `smoke_sales_tenant_data_plane_hardening_01.php` or `smoke_foundation_minimal_regression_wave_01.php` exits non-zero |

**Commands (repo root):**

- Static (minimum for artifact build / CI without DB): `php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`
- Full release proof (static + integration): `php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php --with-integration`

**Enforcement point:** `handoff/build-final-zip.ps1` invokes Tier A before ZIP creation; packaging aborts if the runner exits non-zero. ZIP content rules run **after** the archive is written (see **PLT-PKG-08 + FND-PKG-01** above).

---

## Reconciliation matrix

| # | Claimed wave | Expected evidence (indicative) | Actual files found (this repo) | Repo truth | Status | Next action |
|---|--------------|----------------------------------|--------------------------------|------------|--------|-------------|
| 1 | Sales tenant **read** guard hardening | `SalesTenantScope` + invoice/payment repos/controllers scoped reads | `system/modules/sales/services/SalesTenantScope.php`; `system/modules/sales/repositories/InvoiceRepository.php`; `system/modules/sales/repositories/PaymentRepository.php`; `InvoiceController` / `PaymentController` `ensureProtectedTenantScope` + branch gates | Tenant SQL on `find`/`list`/`getByInvoiceId`; controllers require protected org context and branch match for show/pay | **PRESENT** | Run `php system/scripts/smoke_sales_tenant_data_plane_hardening_01.php` after DB seed |
| 2 | Sales tenant **mutation** guard hardening | Service-layer branch/org asserts; cross-tenant mutation smoke | `system/modules/sales/services/PaymentService.php` (`assertBranchMatchOrGlobalEntity`, `findForUpdate`); `system/modules/sales/services/InvoiceService.php` (update/delete/cancel guards); `system/scripts/smoke_sales_tenant_data_plane_hardening_01.php` | Mutations fail closed across org; same-org wrong-branch denied via `BranchContext` + org assert | **PRESENT** | Same smoke script |
| 3 | Sales / Public-Commerce **boundary** hardening | Staff queue/sync not on public JSON controller; fail-closed helpers | `system/modules/public-commerce/services/PublicCommerceService.php` (`listStaffAwaitingVerificationQueue`, `staffTrustedFulfillmentSync`); `system/modules/public-commerce/repositories/PublicCommercePurchaseRepository.php` (`listAwaitingVerificationWithInvoices` unscoped → `[]`); `system/scripts/read-only/verify_public_commerce_json_controller_staff_boundary_wave_01.php` | Public controller surface statically verified; staff paths require session / org+branch scope in code | **PRESENT** | Run read-only verifier in CI; optional HTTP smokes for auth boundaries |
| 4 | Payroll invoice-plane / tenant guard | Invoice eligibility uses sales tenant clause; payroll repos org-scoped | `system/modules/payroll/services/PayrollService.php` (`SalesTenantScope::invoiceClause` in `fetchEligibleServiceLineEvents`); `system/modules/payroll/repositories/PayrollRunRepository.php` (and related repos per docblocks) with `OrganizationRepositoryScope` | Invoice-linked commission eligibility respects same data-plane scoping as Sales; run rows tenant-gated | **PRESENT** | Run `php system/scripts/smoke_foundation_minimal_regression_wave_01.php` (payroll section) |
| 5 | Foundation **minimal regression** test wave | Smoke script for wrong-branch + PC + payroll + read-only hooks | `system/scripts/smoke_foundation_minimal_regression_wave_01.php` | Single integration smoke complements `smoke_sales_tenant_data_plane_hardening_01.php` (cross-org) | **PRESENT** | Keep both scripts in release checklist; no PHPUnit harness in tree |
| 6 | Foundation **migration baseline enforcement** | Shared baseline report; strict verify; optional HTTP 503; migrate hooks | `system/core/app/MigrationBaseline.php`; `system/core/app/Application.php` (`migration_baseline_enforce`); `system/scripts/read-only/verify_migration_baseline_readonly.php`; `system/scripts/migrate.php` (`--verify-baseline`, post-run checks); `system/scripts/run_migration_baseline_deploy_gate_01.php` (FND-MIG-02); `system/scripts/read-only/smoke_migration_baseline_enforcement_foundation_01.php` | Disk ↔ `migrations` table alignment enforceable in CI and optionally at HTTP edge | **PRESENT** | Deploy gate: **`ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § FND-MIG-02** |
| 7 | Foundation **truth-map / backlog charter** | Backend truth map + active foundation backlog | `system/docs/BACKEND-ARCHITECTURE-TRUTH-MAP-CHARTER-01.md`; `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` | Charter files exist and are the normalized backlog entry | **PRESENT** | Maintain backlog table; avoid duplicate “foundation complete” claims outside this file |

---

## Contradiction resolution (ZIP vs this tree)

If a **handoff ZIP** lacked the rows above, the contradiction is **artifact / packaging / wrong revision**, not “waves absent from product code,” **for this repository state**. Evidence is path-addressable in the table.

**NOT PRESENT** applies only when the listed paths are missing in **your** checkout—e.g. another branch/worktree without these merges.

---

## Partial coverage (explicit)

| Area | Why PARTIAL / not “full proof” |
|------|--------------------------------|
| Sales / payroll | No PHPUnit; proof is **CLI smoke + static read-only verifiers + code structure** |
| Public commerce | Static verifier + service behavior; not every HTTP route exercised in automation |
| Migration baseline | Aligns **migration stamps vs files**, not full semantic diff vs `full_project_schema.sql` |

---

## Related prior seal doc

`ZIP-TRUTH-RECONCILIATION-SEAL-01.md` — historical seal for other claims (timezone, modules count, VAT, etc.). This checkpoint is the **wave-specific** reconciliation for tenant/migration/regression items.

---

## CHECKPOINT-SEAL-REPAIR-MEMBERSHIPS-AND-VERIFIER-01 (2026-03-28)

- **`verify_sales_invoice_payment_tenant_mutation_guard_readonly_01.php`** updated for **typed** `InvoiceController` / `PaymentController` / `PublicCommerceService` signatures and explicit **`ensureProtectedTenantScope`** ordering.
- **`MembershipSaleRepository::listDistinctInvoiceIdsForReconcile`** uses **`invoicePlaneExistsClauseForMembershipReconcileQueries`**: branch-derived **invoice-plane EXISTS** when tenant context is valid; **`AccessDeniedException`** → **OrUnscoped** fallback for repair CLIs without org (same empty-SQL behavior as before when org unset).

---

## CANONICAL-HANDOFF-REBUILD-AND-FINAL-SEAL-01 (2026-03-28)

**Final verifier run (all exit 0):** **`run_mandatory_tenant_isolation_proof_release_gate_01.php`** (Tier A) runs **at the start of** **`handoff/build-final-zip.ps1`**; after the ZIP is written, the build runs **PowerShell** `Get-HandoffZipForbiddenEntries` **and** **`verify_handoff_zip_rules_readonly.php`** on the artifact (PHP **`ZipArchive`**: if CLI reports “zip extension not loaded”, run with `-d extension=<php_dir>/ext/php_zip.dll` on Windows, or enable `extension=zip` in `php.ini`). **Acceptance** on a copy you did not just build: **`handoff/verify-handoff-zip.ps1 -ZipPath …`** or the same PHP command — see **PLT-PKG-08 + FND-PKG-01** checklist.

**Weak-note classification (not checkpoint blockers):** `MembershipBillingCycleRepository` invoice-keyed OrUnscoped lists; `MembershipSaleRepository::listByInvoiceId` OrUnscoped — documented residuals; reconcile id discovery path hardened separately.
