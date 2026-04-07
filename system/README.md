# SPA & Skincare Premium System

Production-ready skeleton. Single root under `/system`.

**Maintainer truth index:** `system/docs/MAINTAINER-RUNTIME-TRUTH.md` (canonical map of live docs vs `archive/`). Repository root `README.md` is introductory; archived blueprint lives under `archive/blueprint-reference/`.

## Structure

| Path | Purpose |
|------|---------|
| `/core` | Independent system core — engines, auth, permissions, audit; zero imports from modules |
| `/modules` | 21 business modules under `system/modules/` (see `modules/README.md`); may depend on core, shared, and approved service contracts |
| `/shared` | Reusable UI primitives; no business logic |
| `/data` | Migrations, seeders, schemas |
| `/public` | Static assets + **production web root** (`DocumentRoot` → `system/public`; see `docs/DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01.md`) |
| `/storage` | Runtime files, documents, logs |

**Inventory — stock quality (from `system/`):** **primary** read-only health entry point — `php scripts/audit_product_stock_quality_consolidated_readonly.php` (`--json`, optional `--fail-on-critical` / `--fail-on-warn` for automation). Ops + stable contract + **`status_fingerprint`** / rollups + **canonical `active_issue_codes` / `issue_inventory`**: `docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md`. **Snapshot compare (two JSON files, no DB):** `php scripts/compare_product_stock_quality_snapshots_readonly.php --left=<before.json> --right=<after.json>` — `docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`. **Preflight advisory** (`proceed` / `review` / `hold`, policy only): `php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=<file.json> [--baseline=<checkpoint.json>]` — `docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`. **Contract coherence proof:** `php scripts/audit_product_stock_health_contract_coherence.php` — `docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md`. **Deeper** movement audits (after consolidated): `php scripts/audit_product_stock_movement_reference_integrity_readonly.php`, `php scripts/audit_product_stock_movement_classification_drift_readonly.php` (`--json`). Ops: `docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md`, `docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md`; module overview: `modules/inventory/README.md`.

## Boundaries

- **Core** — fully independent; does not import from `/modules`
- **Shared** — no business logic; pure presentation
- **Modules** — depend only on core, shared, and approved contracts; no direct cross-module repository coupling
