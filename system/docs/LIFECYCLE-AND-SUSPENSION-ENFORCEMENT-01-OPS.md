## LIFECYCLE-AND-SUSPENSION-ENFORCEMENT-01 OPS

Status: CLOSED

### Scope implemented

- Added central lifecycle truth gate: `Core\Organization\OrganizationLifecycleGate`.
- Tenant runtime/authenticated enforcement now blocks suspended organizations in `Core\Tenant\TenantRuntimeContextEnforcer`.
- Login continuation now denies tenant principals bound to suspended organizations in `Modules\Auth\Controllers\LoginController`.
- Tenant entry flow now renders explicit suspended block screen for suspended tenant users in `Modules\Auth\Controllers\TenantEntryController`.
- Added tenant blocked HTML screen: `modules/auth/views/tenant-suspended.php`.
- Public booking now fail-closes suspended organization branches via `Modules\OnlineBooking\Services\PublicBookingService` and explicit 403 JSON in `Modules\OnlineBooking\Controllers\PublicBookingController`.
- Public commerce now fail-closes suspended organization branches via `Modules\PublicCommerce\Services\PublicCommerceService` and explicit 403 JSON in `Modules\PublicCommerce\Controllers\PublicCommerceController`.
- Platform principal control-plane flow remains unchanged.

### Runtime truth after repair

- Tenant runtime is fail-closed when organization lifecycle is suspended.
- Tenant login cannot continue operationally when lifecycle gate detects suspended org binding.
- Public booking requests for suspended org branches return explicit forbidden JSON (`ORGANIZATION_SUSPENDED`).
- Public commerce requests for suspended org branches return explicit forbidden JSON (`ORGANIZATION_SUSPENDED`).
- Platform principal control-plane access path is preserved and not suspension-blocked by tenant gate logic.

### Proof path added

- Added smoke verifier script: `system/scripts/smoke_lifecycle_and_suspension_enforcement_01.php`.
- Verifier includes checks for:
  - active tenant admin runtime access,
  - suspended tenant admin denial,
  - suspended tenant non-continuation after session resolution,
  - public booking suspended-branch denial,
  - public commerce suspended-branch denial,
  - founder platform control-plane access while tenant suspension exists.

### Proof execution in this environment

- Runtime used:
  - `c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`
- Fixture prep commands executed:
  - `& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts/seed.php"`
  - `& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts/dev-only/seed_branch_smoke_data.php"`
  - `& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts/dev-only/prepare_lifecycle_suspension_smoke_data.php"`
- Runtime server command executed:
  - `& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" -S 127.0.0.1:8901 -t "c:\laragon\www\spa-skincare-system-blueprint\system\public" "c:\laragon\www\spa-skincare-system-blueprint\system\public\router.php"`
- Smoke command executed:
  - `& "c:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" "scripts/smoke_lifecycle_and_suspension_enforcement_01.php"`
- Smoke result:
  - `PASS  active_admin_login_allowed`
  - `PASS  suspended_admin_login_denied`
  - `PASS  active_tenant_runtime_allowed`
  - `PASS  suspended_tenant_session_cannot_continue`
  - `PASS  public_booking_denied_on_suspended_org_branch`
  - `PASS  public_commerce_denied_on_suspended_org_branch`
  - `PASS  platform_founder_control_plane_access_unchanged`
  - `PASS  active_branch_public_surface_not_globally_blocked`
  - `Summary: 8 passed, 0 failed.`
- Runtime server stop:
  - proof server process on `127.0.0.1:8901` stopped after execution.

### Out of scope preserved

- No billing/subscription engine work.
- No storefront/catalog/mixed-sales expansion beyond suspension deny gates.
- No auth architecture redesign.
- No broad repository refactor.
- No UI redesign beyond minimal blocked response surface.
