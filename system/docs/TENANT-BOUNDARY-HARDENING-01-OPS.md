# TENANT-BOUNDARY-HARDENING-01 — Fail-closed branch and organization context enforcement — OPS

Date: 2026-03-23  
Status: `CLOSED`

## Scope implemented

- Removed implicit branch context mutation from arbitrary `GET/POST branch_id` in middleware flow.
- Added explicit tenant-only branch context switch endpoint: `POST /account/branch-context`.
- Enforced tenant allowed-branch set from pinned user branch or active membership organization branches.
- Added fail-closed tenant runtime context guard: tenant routes deny when branch/org context is unresolved.
- Kept platform control-plane behavior intact from FOUNDATION-100.

## Environment used

- Host: local Windows dev environment
- App URL for runtime proof: `http://127.0.0.1:8899`
- PHP binary: `c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`

## Commands executed (repair + proof)

1. `php scripts/seed.php`
2. `php scripts/repair_control_plane_rbac_foundation_100.php`
3. `php scripts/dev-only/seed_branch_smoke_data.php`
4. `php -S 127.0.0.1:8899 -t system/public system/public/router.php`
5. `php scripts/smoke_tenant_boundary_hardening_01.php` with:
   - `SMOKE_BASE_URL=http://127.0.0.1:8899`
   - `SMOKE_ADMIN_EMAIL=branchA@example.com`
   - `SMOKE_RECEPTION_EMAIL=branchB@example.com`
   - `SMOKE_ORPHAN_EMAIL=tenant-orphan@example.com`
   - `SMOKE_FOREIGN_BRANCH_ID=12`
   - passwords: `StrongPass123!`

## Runtime proof results

- `PASS tenant_admin_dashboard_allowed`
- `PASS tenant_reception_dashboard_allowed`
- `PASS tenant_foreign_branch_switch_denied`
- `PASS tenant_missing_context_denied`
- `PASS tenant_unresolved_context_not_global_fallback`
- Summary: `8 passed, 0 failed`

## Closure decision

- Wave acceptance criteria passed for protected tenant runtime in this wave scope.
- TENANT-BOUNDARY-HARDENING-01 is marked `CLOSED`.

## Intentionally deferred

- Settings isolation redesign
- Full repository/data-plane refactor
- Lifecycle/suspension enforcement
- Catalog/storefront/mixed-sales/public feature expansion
