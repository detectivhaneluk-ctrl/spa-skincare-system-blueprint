# FOUNDATION-100 — Control plane RBAC and runtime separation repair — OPS

**Date:** 2026-03-23  
**Scope label:** `FOUNDATION-100 — CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR`  
**Status:** `CLOSED`

> Superseded as active execution truth by `FOUNDATION-100-CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-CHARTER.md`.

## Why this repair existed

FOUNDATION-97/98/99 shipped parts of the control-plane split, but the split was not closed:

- `owner` still inherited platform permissions through baseline seeding.
- `014` added `platform_founder` but did not revoke platform grants from tenant roles.
- Runtime decisions depended on platform permission checks, so contaminated tenant roles still behaved as platform users.

Result: tenant vs founder runtime separation could not be trusted on legacy databases.

Current wave closure: explicit principal-plane runtime guards are active, RBAC repair/seeding strips `platform.*` from non-`platform_founder` roles, and runtime smoke proof has been executed with passing scenarios recorded below.

## What changed (repair-only, no feature expansion)

1. **RBAC source-of-truth repair (fresh seed path)**
   - `001_seed_roles_permissions.php`: `owner` no longer receives `platform.*` permissions.
   - `014_seed_control_plane_role_split_permissions.php`: explicitly removes `platform.organizations.*` from tenant roles (`owner`, `admin`, `reception`) while ensuring `platform_founder` owns platform permissions.

2. **RBAC repair for existing databases**
   - Added `scripts/repair_control_plane_rbac_foundation_100.php` (idempotent):
     - ensures `platform_founder` role exists
     - grants platform permissions to `platform_founder`
     - removes those permissions from every non-`platform_founder` role

3. **Developer/runtime provisioning alignment**
   - `scripts/create_user.php` guidance now points platform access to `platform_founder`.
   - Legacy guidance claiming `owner` platform access removed.
   - Existing smoke/demo role mapping (`platform_founder`/`admin`/`reception`) remains aligned.

4. **Focused runtime proof**
   - Added `scripts/smoke_control_plane_separation_foundation_100.php` to verify:
     - founder user home redirects to `/platform-admin`
     - admin/reception user home redirects to `/dashboard`
     - admin/reception on `/platform-admin` returns `403 FORBIDDEN`
     - founder on `/dashboard` redirects to `/platform-admin`

## Runtime truth after FOUNDATION-100

- Platform access belongs to explicit platform role (`platform_founder`) only.
- Tenant roles stay tenant-only (`owner`, `admin`, `reception` are stripped of `platform.*`).
- `/platform-admin` remains auth + permission guarded (route middleware + defensive controller check).
- `/dashboard` redirects platform-capable users to `/platform-admin`.

## Out of scope (intentionally unchanged)

- No catalog/storefront/mixed-sales work.
- No subscription/package wave changes.
- No UI redesign.
- No broad auth rewrite or architecture refactor.
- No packaging/handoff updates.

## Canonical runtime proof evidence (closure gate)

### Environment

- Host: local Windows dev environment
- App URL used for proof: `http://127.0.0.1:8899`
- PHP binary: `c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`

### Repair/prep commands executed

1. `php scripts/seed.php`
2. `php scripts/repair_control_plane_rbac_foundation_100.php`
3. `php scripts/dev-only/seed_branch_smoke_data.php`

Observed RBAC verification output from repair script:

- `verification OK: no platform permission leaks on non-platform roles.`

### Runtime proof commands executed

1. Start local proof server:
   - `php -S 127.0.0.1:8899 -t system/public system/public/router.php`
2. Run smoke verifier with deterministic principals:
   - `php scripts/smoke_control_plane_separation_foundation_100.php`
   - env used:
     - `SMOKE_BASE_URL=http://127.0.0.1:8899`
     - `SMOKE_FOUNDER_EMAIL=platform-smoke@example.com`
     - `SMOKE_ADMIN_EMAIL=branchA@example.com`
     - `SMOKE_RECEPTION_EMAIL=branchB@example.com`
     - all passwords `StrongPass123!`

### Runtime proof results

- `PASS founder_home_redirects_platform_admin`
- `PASS admin_home_redirects_dashboard`
- `PASS reception_home_redirects_dashboard`
- `PASS admin_forbidden_platform_admin`
- `PASS reception_forbidden_platform_admin`
- `PASS founder_dashboard_redirects_platform_admin`
- Summary: `9 passed, 0 failed`

### Closure decision

- FOUNDATION-100 closure gate is accepted.
- Status set to `CLOSED` based on executed runtime evidence (not docs-only assertion).
