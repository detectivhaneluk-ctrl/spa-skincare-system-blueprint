# Repository cleanup notes (non-runtime archive)

**Purpose:** Record what was moved into archives so future work does not treat historical paths as live truth.

## What was archived

### Root blueprint / architecture narratives

Moved from repository root → `archive/blueprint-reference/`:

- `00-INDEX.md` … `13-SKELETON-NOTE.md` (full numbered set)
- `ARCHITECTURE-SUMMARY.md`

### Top-level module specification markdown (blueprint era)

Moved from `modules/*.md` → `archive/blueprint-reference/top-level-modules-specs/`:

- `01-auth.md` through `15-system-core.md` (all former root `modules/` specs)

The empty top-level `modules/` directory was removed after the move (it only held these files).

### Cursor / IDE context snapshots

Moved from `cursor-context/*` → `archive/cursor-context/`:

- `domain_map.json`, `module_dependencies.json`, `project-rules.md`, `system_manifest.json`  
- The `cursor-context/` directory was removed (contents preserved under `archive/cursor-context/`).

### System root historical summaries

Moved from `system/` → `system/docs/archive/system-root-summaries/`:

- `HARDENING-SUMMARY.md`
- `IMPLEMENTATION-SUMMARY.md`
- `PHASE1-README.md`
- `SKELETON-SUMMARY.md`
- `BACKEND-STATUS-SUMMARY.md`

### Dev-only / smoke PHP scripts

Moved from `system/scripts/` → `system/scripts/dev-only/`:

- `seed_branch_smoke_data.php`
- `seed_smoke_branch_services.php`
- `verify_permissions_403.php`
- `check_tables.php`
- `reset_dev.php`

**Run from repo root examples:**

- `php system/scripts/dev-only/reset_dev.php --yes`
- `php system/scripts/dev-only/verify_permissions_403.php`

`system/scripts/migrate.php` still prints `Use scripts/reset_dev.php` in one help line (relative to `system/` when run from there); adjust mentally to `scripts/dev-only/reset_dev.php` or fix that string in a future tiny maintenance pass.

### Legacy placeholder module (no PHP)

Moved from `system/modules/giftcards-packages/` → `archive/legacy-modules/giftcards-packages/`  
(only contained `README.md`). **Runtime gift cards** live under `system/modules/gift-cards/` (`Modules\GiftCards\`), not this archived folder.

---

## What was intentionally kept in place

- **All active PHP modules** under `system/modules/*` except the removed placeholder folder above (including `marketing/`, `dashboard/`, `gift-cards/`, `packages/`, `memberships/`, etc.).
- **`system/docs/`** operational and Booker roadmaps (e.g. `BOOKER-PARITY-MASTER-ROADMAP.md`, `booker-modernization-*.md`, phase progress docs, `BACKEND-READINESS-QA.md`, `CONVENTIONS.md`, …).
- **`system/routes/web.php`**, schema, services, repositories, controllers, views — unchanged.
- **`system/scripts/migrate.php`**, `system/scripts/seed.php`, `system/scripts/create_user.php` — remain in `system/scripts/`.
- **Runtime directories** under `system/public/`, `system/storage/`, `system/shared/`, `system/core/` — not reorganized.
- **Root `README.md`** — kept as the primary entry README.

---

## What must never be treated as dead code

- **`system/modules/gift-cards/`** — real gift card module (repositories, services, controller).
- **`system/modules/packages/`** — real packages module.
- **`system/modules/memberships/`** — real memberships module.
- **Any module with `.php` under `controllers/`, `services/`, `repositories/`** — active runtime surface unless explicitly deprecated in a dedicated task.

The autoloader map in `system/core/app/autoload.php` still includes `GiftcardsPackages` → `giftcards-packages` for historical namespace mapping; there are **no** application references to `Modules\GiftcardsPackages\*` in `.php` files today. If that namespace is ever revived, restore code under `system/modules/` or drop the map entry in a focused task.

---

## Documentation link maintenance

Cross-references under `system/docs/*.md` (and the archived `SKELETON-SUMMARY.md` file list) should use `system/docs/archive/system-root-summaries/` for the former `system/*-SUMMARY.md` and related root summaries. A doc-only pass updated these paths after the archive move.

**Platform / foundation backlog order** (single strict queue, separate from `BOOKER-PARITY-MASTER-ROADMAP.md` §5.C): `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md`.

**Migration baseline / deploy gate (FND-MIG-02):** canonical commands and “migrated vs deploy-safe” distinction live in `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § FND-MIG-02; entry script `system/scripts/run_migration_baseline_deploy_gate_01.php`.

---

## Environment and secrets

- **Runtime env loading order:** `system/core/app/Env.php` checks **`system/.env.local` first**, then **`system/.env`**.
- **Runtime env files are local-only secrets:** `system/.env.local` and `system/.env` contain credentials and must never be committed, pasted into chat/tickets, or distributed.
- **Final handoff ZIPs must not include runtime env files:** exclude both `system/.env.local` and `system/.env`.
- **Template file:** `system/.env.example` remains the non-secret template for local setup (included in the handoff ZIP).
- **Packaging scripts:** `handoff/build-final-zip.ps1` builds the archive; **before** creating the ZIP it runs **PLT-REL-01** Tier A: `php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php` (fail-closed). Rules live in `handoff/HandoffZipRules.ps1` (excludes `system/.env`, `system/.env.local`, any repo `**/*.zip`, `system/storage/logs/**`, `system/storage/backups/**`, `system/storage/sessions/**`, and any `**/*.log`; keeps `system/.env.example`). **After** the ZIP is written, the script runs **both** a PowerShell scan (`Get-HandoffZipForbiddenEntries`) **and** `php system/scripts/read-only/verify_handoff_zip_rules_readonly.php <zip>` (**PLT-PKG-08 + FND-PKG-01**); **removes the output file** if either fails. **Operator checklist** (build vs acceptance): `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § PLT-PKG-08. **Local tree vs ZIP (separate concerns):** (1) **`handoff/verify-repo-worktree-hygiene.ps1`** — informational only (exit 0): lists files on disk that match ZIP exclusion rules; **safe to keep** `system/.env.local` locally for runtime; never require deleting it for packaging. (2) **`handoff/verify-handoff-zip.ps1 -ZipPath …`** — **strict acceptance** on the **exact bytes** you ship (same rules as build); on failure exit code `1` and details in `handoff/verify-handoff-artifacts.failures.txt` (PowerShell 7+: use `pwsh` instead of `powershell`). (3) **PHP verifier** — same forbidden-entry rules as `HandoffZipRules.ps1`; mandatory inside `build-final-zip.ps1`, standalone for Linux or re-verify after re-packaging.

---

## Archive layout summary

| Location | Role |
|----------|------|
| `archive/blueprint-reference/` | Original top-level blueprint markdown |
| `archive/blueprint-reference/top-level-modules-specs/` | Per-module blueprint specs from old `modules/` |
| `archive/cursor-context/` | Historical Cursor context JSON / rules |
| `archive/legacy-modules/giftcards-packages/` | Placeholder module (README only) |
| `system/docs/archive/system-root-summaries/` | Old `system/*.md` summaries |
| `system/scripts/dev-only/` | Smoke / reset / verification scripts |
