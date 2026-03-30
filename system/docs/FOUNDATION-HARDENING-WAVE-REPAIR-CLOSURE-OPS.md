# FOUNDATION-HARDENING-WAVE-REPAIR closure truth

**CHARTER-01 (2026-03-28):** Active normalized foundation queue + backend truth map: `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`, `BACKEND-ARCHITECTURE-TRUTH-MAP-CHARTER-01.md`, `TENANT-SAFETY-INVENTORY-CHARTER-01.md`.

## Canonical migration order

- `088_platform_organization_profile_permissions_catalog.sql`
- `089_services_add_description.sql`
- `090_sell_time_entitlement_snapshots.sql`
- `091_settings_tenant_isolation_foundation.sql`

No duplicate migration numbers remain. Incremental migrate order is deterministic by filename sort (`scripts/migrate.php`).

## Snapshot rollout truth

- Sell-time immutable snapshots are canonical in runtime:
  - `membership_sales.definition_snapshot_json`
  - `client_memberships.entitlement_snapshot_json`
  - `client_packages.package_snapshot_json`
  - `public_commerce_purchases.package_snapshot_json`
- `full_project_schema.sql` includes the same columns and the `public_commerce_purchases` table definition aligned with migrations through `091`.

## Legacy snapshot-gap handling truth

- Script: `system/scripts/snapshot_gap_preflight_repair.php`
- Classification per finding:
  - `SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE`
  - `MANUAL_REVIEW_REQUIRED`
  - `ALREADY_TERMINAL_IGNORE`
- Auto-apply mode (`--apply-safe`) performs only deterministic same-entity reference backfills. It does **not** derive entitlement truth from mutable current definitions/packages.

## Guardrail coverage truth

- Raw repository footgun verifier: `system/scripts/verify_tenant_repository_footguns.php`
  - Covers active runtime/provider files for memberships, packages, gift cards, public-commerce fulfillment, and client profile providers.
  - Raw `->find` / `->findForUpdate` in these files requires explicit allowlist justification.
- NULL-branch verifier: `system/scripts/verify_null_branch_catalog_patterns.php`
  - Expanded scan to touched sellable/runtime surfaces (memberships/packages/public-commerce/sales/gift-cards/providers/controllers/services).
  - Explicit allowlist remains only for VAT/payment method settings repositories.

## Proof closure truth

The required proof set is green in migrated environment:

- `verify_tenant_repository_footguns.php` — pass
- `verify_null_branch_catalog_patterns.php` — pass
- `smoke_foundation_hardening_wave_01.php` — pass
- `smoke_memberships_giftcards_packages_hardening_01.php` — pass
- `smoke_tenant_owned_data_plane_hardening_01.php` — pass
