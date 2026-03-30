# TENANT-ENTRY-FLOW-01 — SAFE BRANCH/ORGANIZATION RESOLUTION UX COMPLETION (OPS)

Date: 2026-03-23  
Status: CLOSED (runtime-proof accepted)

## Scope implemented

- Added authenticated tenant entry resolver path `GET /tenant-entry` that centralizes three safe outcomes:
  - one allowed branch -> auto-select session branch and continue to `/dashboard`
  - multiple allowed branches -> render chooser page with explicit POST selection
  - zero allowed branches -> render blocked/help screen with no fallback broadening
- Added tenant entry UI views:
  - `modules/auth/views/tenant-entry-chooser.php`
  - `modules/auth/views/tenant-entry-blocked.php`
- Updated tenant home routing for non-platform users to `PATH_TENANT = /tenant-entry`.
- Preserved fail-closed behavior with explicit path exemptions and redirects:
  - `TenantRuntimeContextEnforcer` now exempts `GET /tenant-entry` and redirects HTML unresolved-context requests to `/tenant-entry`.
  - `StaffMultiOrgOrganizationResolutionGate` now exempts `GET /tenant-entry` and redirects HTML unresolved-organization requests to `/tenant-entry`.
- Added deterministic proof fixture support for multi-branch tenant chooser path in `scripts/dev-only/seed_branch_smoke_data.php` (`tenant-multi@example.com` + memberships).

## Environment

- OS: Windows 11
- PHP: `c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- App base URL for smoke: `http://127.0.0.1:8899`

## Commands executed

1) Seed deterministic smoke users/branches:

```powershell
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts\dev-only\seed_branch_smoke_data.php"
```

2) Run local runtime server:

```powershell
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" -S 127.0.0.1:8899 -t "c:\laragon\www\spa-skincare-system-blueprint\system\public" "c:\laragon\www\spa-skincare-system-blueprint\system\public\router.php"
```

3) Execute tenant entry flow proof:

```powershell
$env:SMOKE_BASE_URL='http://127.0.0.1:8899'
$env:SMOKE_FOUNDER_EMAIL='platform-smoke@example.com'
$env:SMOKE_FOUNDER_PASSWORD='StrongPass123!'
$env:SMOKE_ADMIN_EMAIL='branchA@example.com'
$env:SMOKE_ADMIN_PASSWORD='StrongPass123!'
$env:SMOKE_MULTI_EMAIL='tenant-multi@example.com'
$env:SMOKE_MULTI_PASSWORD='StrongPass123!'
$env:SMOKE_ORPHAN_EMAIL='tenant-orphan@example.com'
$env:SMOKE_ORPHAN_PASSWORD='StrongPass123!'
$env:SMOKE_FOREIGN_BRANCH_ID='999999'
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts\smoke_tenant_entry_flow_01.php"
```

4) Regression check for prior tenant-boundary hardening invariants:

```powershell
$env:SMOKE_BASE_URL='http://127.0.0.1:8899'
$env:SMOKE_FOUNDER_EMAIL='platform-smoke@example.com'
$env:SMOKE_FOUNDER_PASSWORD='StrongPass123!'
$env:SMOKE_ADMIN_EMAIL='branchA@example.com'
$env:SMOKE_ADMIN_PASSWORD='StrongPass123!'
$env:SMOKE_RECEPTION_EMAIL='branchB@example.com'
$env:SMOKE_RECEPTION_PASSWORD='StrongPass123!'
$env:SMOKE_ORPHAN_EMAIL='tenant-orphan@example.com'
$env:SMOKE_ORPHAN_PASSWORD='StrongPass123!'
$env:SMOKE_ADMIN_VALID_BRANCH_ID='11'
$env:SMOKE_FOREIGN_BRANCH_ID='999999'
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts\smoke_tenant_boundary_hardening_01.php"
```

## Runtime proof results

- `smoke_tenant_entry_flow_01.php`: **10 passed, 0 failed**
  - founder login succeeds
  - tenant single-branch user auto-resolves to `/dashboard`
  - tenant multi-branch user lands on chooser page
  - chooser allowed POST reaches `/dashboard`
  - chooser foreign POST denied (`403 FORBIDDEN`)
  - zero-context tenant gets blocked/help screen, not raw plain-text denial
  - founder `/tenant-entry` behavior remains unchanged (redirect to `/platform-admin`)
- `smoke_tenant_boundary_hardening_01.php`: **8 passed, 0 failed** (regression guard remained intact)

## Closure decision

- TENANT-ENTRY-FLOW-01 is **CLOSED**.
- Fail-closed tenant security behavior remains intact; entry UX dead-end is replaced by explicit resolver/chooser/blocked flow without any GET-based branch mutation.
