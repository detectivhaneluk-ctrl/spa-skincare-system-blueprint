# FOUNDATION-98 — Control plane split (wave 2) — runtime home routing & role separation — OPS

**Date:** 2026-03-23  
**Scope label:** `FOUNDATION-98 — CONTROL-PLANE-SPLIT-WAVE-2-RUNTIME-HOME-ROUTING-AND-ROLE-SEPARATION`
**Status (post FOUNDATION-100):** `PARTIAL` — runtime resolver shipped, but closure was incomplete while legacy `owner` still retained platform permissions until FOUNDATION-100 RBAC repair.

## Real cause (what was broken)

1. **No shared “home” decision:** Authenticated **`GET /`** always redirected to **`/dashboard`**, while **post-login** always sent users to **`/appointments/calendar/day`**. Neither path consulted **platform vs tenant** intent, so every login effectively felt like the same “start here” experience regardless of operator type.
2. **Tenant shell carried a primary “Platform” nav item** for users with **`platform.organizations.view`**, which blurred the tenant vs founder identity of the page.
3. **Dev/smoke paths assigned `owner` everywhere** (`create_user.php`, **`seed_branch_smoke_data.php`**). **`owner`** receives **all** permissions from **`001_seed_roles_permissions.php`**, including **`platform.organizations.view`**, so multiple test accounts behaved like the same super-capable operator.

## What shipped (runtime paths changed)

| Path / behavior | Change |
|-----------------|--------|
| **`GET /`** (`RootController`) | After auth, redirect uses **`AuthenticatedHomePathResolver`** instead of a hard-coded **`/dashboard`**. |
| **`POST /login`** success (`LoginController::attempt`) | Redirect uses the same resolver instead of **`/appointments/calendar/day`**. |
| **Guest `/login`**, password reset, **`POST /logout`** | Unchanged. |

## How founder vs tenant home is decided

- **Single resolver:** `Core\Auth\AuthenticatedHomePathResolver::homePathForUserId(int $userId)`.
- **Rule:** If **`PermissionService::has($userId, 'platform.organizations.view')`** → **`/platform-admin`**; otherwise → **`/dashboard`**.
- **Same permission** as **`GET /platform-admin`** route guard (`PermissionMiddleware::for('platform.organizations.view')`). No new permission codes; **`owner`** still receives all permissions (including platform) when **`001`** runs as today — not weakened.

## Navigation isolation (tenant vs founder shell)

- **`layout/base.php` (tenant):** Removed **Platform** from the **primary** module nav. Users who may open the control plane still see a **secondary** header link **“Platform control”** → **`/platform-admin`** (aside, not main module strip).
- **`layout/platform_admin.php`:** Unchanged module nav (platform-only). **“Salon workspace”** remains the **secondary** cross-link to **`/dashboard`**.

## Dev / smoke user role assignment

| Mechanism | Change |
|-----------|--------|
| **`data/seeders/014_seed_control_plane_role_split_permissions.php`** | Adds **`platform_founder`** with **`platform.organizations.view`** + **`platform.organizations.manage`** only. Grants **`admin`** every permission **except** those two platform codes. Grants **`reception`** a small tenant-operator subset (appointments/clients/staff/services-resources/notifications/intake read+where applicable). **`owner`** unchanged. |
| **`scripts/seed.php`** | Includes **`014`** after **`013`**. |
| **`scripts/create_user.php`** | Default role is **`admin`** (tenant plane after **`014`**). Optional third argument: role code; use **`owner`** when a full legacy super-user is intentional. |
| **`scripts/dev-only/seed_branch_smoke_data.php`** | **`platform-smoke@example.com`** → **`platform_founder`**; **`branchA@example.com`** → **`admin`**; **`branchB@example.com`** → **`reception`**. Replaces all roles for those users (no longer **`owner`** for everyone). Bootstrap path fixed to **`system/bootstrap.php`** via **`dirname(__DIR__, 2)`**. |

## What did **not** change

- **No** schema migrations, **no** new public HTTP routes, **no** changes to tenant data boundaries, booking, pricing, VAT, checkout, or other public behavior.
- **`/platform-admin`** remains **auth + `platform.organizations.view`**.
- **No** impersonation, **no** subscription/revenue widgets.

## Primary files

- `system/core/auth/AuthenticatedHomePathResolver.php`
- `system/bootstrap.php` (DI registration)
- `system/core/router/RootController.php`
- `system/modules/auth/controllers/LoginController.php`
- `system/shared/layout/base.php`
- `system/public/assets/css/app.css`
- `system/data/seeders/014_seed_control_plane_role_split_permissions.php`
- `system/scripts/seed.php`
- `system/scripts/create_user.php`
- `system/scripts/dev-only/seed_branch_smoke_data.php`

## ZIP

`distribution/spa-skincare-system-blueprint-FOUNDATION-98-CONTROL-PLANE-SPLIT-WAVE-2-CHECKPOINT.zip` via `handoff/build-final-zip.ps1` with `-OutputZip` set to that path.
