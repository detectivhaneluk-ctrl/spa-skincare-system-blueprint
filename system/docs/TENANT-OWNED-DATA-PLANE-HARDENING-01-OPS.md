# TENANT-OWNED-DATA-PLANE-HARDENING-01 — FAIL-CLOSED REPOSITORY AND WRITE-PATH SCOPE GUARANTEE (OPS)

Date: 2026-03-23  
Status: CLOSED (runtime-proof accepted for wave-01 in-scope modules)

## Implemented scope

- Added central guard: `Core\Tenant\TenantOwnedDataScopeGuard` (resolved tenant scope required, scoped entity ownership checks).
- Hardened in-scope repositories with org-scoped reads and scoped-by-id writes:
  - `ClientRepository`, `StaffRepository`, `ServiceRepository`, `AppointmentRepository`
- Hardened in-scope write services to fail closed when tenant scope is unresolved:
  - `ClientService`, `StaffService`, `ServiceService`, `AppointmentService`
- Added appointment cross-entity tenant checks for linked ids (`client/service/staff/room`) and branch consistency on protected write paths.
- Updated module registrars to pass new dependencies.

## Commands executed

1) Seed deterministic fixtures:

```powershell
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts\dev-only\seed_branch_smoke_data.php"
```

2) Execute wave proof:

```powershell
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts\smoke_tenant_owned_data_plane_hardening_01.php"
```

3) Regression checks to ensure previously closed boundary/entry behavior remains intact:

```powershell
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" -S 127.0.0.1:8899 -t "c:\laragon\www\spa-skincare-system-blueprint\system\public" "c:\laragon\www\spa-skincare-system-blueprint\system\public\router.php"
```

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

```powershell
$env:SMOKE_BASE_URL='http://127.0.0.1:8899'
$env:SMOKE_ADMIN_EMAIL='branchA@example.com'
$env:SMOKE_ADMIN_PASSWORD='StrongPass123!'
$env:SMOKE_RECEPTION_EMAIL='branchB@example.com'
$env:SMOKE_RECEPTION_PASSWORD='StrongPass123!'
$env:SMOKE_ORPHAN_EMAIL='tenant-orphan@example.com'
$env:SMOKE_ORPHAN_PASSWORD='StrongPass123!'
$env:SMOKE_FOREIGN_BRANCH_ID='999999'
& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts\smoke_tenant_boundary_hardening_01.php"
```

## Runtime results

- `smoke_tenant_owned_data_plane_hardening_01.php`: **14 passed, 0 failed**
  - own tenant reads allowed (clients/staff/services/appointments)
  - foreign-tenant by-id reads denied
  - foreign-tenant by-id updates denied (clients/staff/services)
  - cross-tenant appointment link denied
  - valid in-tenant create/update paths remain working
  - unresolved tenant context fails closed
- `smoke_tenant_entry_flow_01.php`: **10 passed, 0 failed**
- `smoke_tenant_boundary_hardening_01.php`: **8 passed, 0 failed**

## Closure decision

- `TENANT-OWNED-DATA-PLANE-HARDENING-01` is **CLOSED** for the defined in-scope protected tenant modules.
- Remaining full-wave expansion to other modules remains intentionally deferred and tracked in the scope matrix.
