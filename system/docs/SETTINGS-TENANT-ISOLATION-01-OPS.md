# SETTINGS-TENANT-ISOLATION-01 — Organization-scoped settings foundation and fail-closed precedence — OPS

Date: 2026-03-23  
Status: `CLOSED`

## Implemented

- Settings storage now includes `organization_id` scope with unique scope key:
  - `uk_settings_key_org_branch (key, organization_id, branch_id)`
- Precedence in `SettingsService` is tenant-safe and explicit:
  - platform default `(organization_id=0, branch_id=0)`
  - organization default `(organization_id=O, branch_id=0)`
  - branch override `(organization_id=O, branch_id=B)`
- Branch override writes now enforce org ownership and reject cross-org writes.
- Branch/org scope backfill + verification repair path added for existing installs.

## Migration/repair commands executed

1. `php scripts/migrate.php`
2. `php scripts/repair_settings_tenant_isolation_01.php`
3. `php scripts/seed.php`
4. `php scripts/repair_control_plane_rbac_foundation_100.php`
5. `php scripts/dev-only/seed_branch_smoke_data.php`

Observed repair output:

- `SETTINGS-TENANT-ISOLATION-01 repair complete.`
- `verification OK: no cross-tenant branch override mismatches.`

## Proof commands executed

1. `php scripts/smoke_settings_tenant_isolation_01.php`
2. Start server:
   - `php -S 127.0.0.1:8899 -t system/public system/public/router.php`
3. Runtime fail-closed smoke:
   - `php scripts/smoke_tenant_boundary_hardening_01.php`
   - env:
     - `SMOKE_BASE_URL=http://127.0.0.1:8899`
     - `SMOKE_ADMIN_EMAIL=branchA@example.com`
     - `SMOKE_RECEPTION_EMAIL=branchB@example.com`
     - `SMOKE_ORPHAN_EMAIL=tenant-orphan@example.com`
     - `SMOKE_FOREIGN_BRANCH_ID=12`
     - passwords `StrongPass123!`

## Proof results

### Settings isolation smoke

- `PASS tenant_a_branch_override_applies`
- `PASS tenant_b_does_not_inherit_tenant_a_defaults`
- `PASS tenant_b_does_not_inherit_tenant_a_branch_override`
- `PASS cross_org_branch_write_denied`
- Summary: `4 passed, 0 failed`

### Protected tenant runtime fail-closed smoke

- `PASS tenant_admin_dashboard_allowed`
- `PASS tenant_reception_dashboard_allowed`
- `PASS tenant_foreign_branch_switch_denied`
- `PASS tenant_missing_context_denied`
- `PASS tenant_unresolved_context_not_global_fallback`
- Summary: `8 passed, 0 failed`

## Closure decision

- Acceptance criteria met for this wave scope.
- SETTINGS-TENANT-ISOLATION-01 is marked `CLOSED`.

## Intentionally deferred

- Settings UI redesign
- Full repository/data-plane hardening
- Lifecycle/suspension enforcement
- Public/catalog/storefront/mixed-sales feature lanes
