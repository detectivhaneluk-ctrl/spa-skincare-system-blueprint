# FOUNDATION-97 — Control plane split (wave 1) — tenant vs founder namespace — OPS

**Date:** 2026-03-23  
**Scope label:** `FOUNDATION-97 — CONTROL-PLANE-SPLIT-WAVE-1-FOUNDER-NAMESPACE-AND-TENANT-SEPARATION`
**Status (post FOUNDATION-100):** `REOPENED` — wave-1 shell split shipped, but role/permission truth was contaminated by legacy `owner` platform grants and therefore not fully closed before FOUNDATION-100 repair.

## Product decision

- **Tenant admin** = salon owner/operator workspace (`/dashboard`, tenant **`layout/base.php`** nav: Appointments, Clients, Staff, etc.).
- **Founder / platform operator** = separate control plane (`/platform-admin`, **`layout/platform_admin.php`**: global nav only — Control center, Organizations).

This wave **separates** those shells so the tenant home is **no longer labeled or positioned as a founder dashboard**.

## What shipped

### Tenant `/dashboard`

- **`TenantOperatorDashboardService`** (renamed from **`FounderDashboardService`**) — same read-only tenant semantics as FOUNDATION-96 (branch context, snapshot, waitlist, upcoming list).
- Page title and header: **Dashboard**; copy describes **salon workspace**, not platform scope.

### Platform `/platform-admin`

- **`GET /platform-admin`** — **`PlatformControlPlaneController::index`**.
- Middleware: **`AuthMiddleware`** + **`PermissionMiddleware::for('platform.organizations.view')`** (existing platform-operator permission; no new permission rows).
- **`PlatformControlPlaneOverviewService`** + **`PlatformControlPlaneReadRepository`**: global **`COUNT(*)`** on `organizations`, `branches`, `users`, `staff`, `appointments`, `clients` (non-deleted where applicable); optional **recent organizations** by `created_at`.
- **No** subscription, revenue, or billing widgets (not safely implemented as product truth in this wave).

### Platform layout

- **`shared/layout/platform_admin.php`**: brand **Platform** → `/platform-admin`; nav **Control center**, **Organizations**; aside **Salon workspace** → `/dashboard`, Logout.
- **`/platform/organizations`** registry views (index/show/create/edit) now **`require platform_admin.php`** instead of **`base.php`**; in-page links point to **Control center** instead of tenant **Settings**.

### Multi-org gate (F-25)

- **`StaffMultiOrgOrganizationResolutionGate`**: paths under **`/platform-admin`** are exempt from “organization context required” (same RBAC still enforced on the route).

### Tenant nav hint

- **`layout/base.php`**: users with **`platform.organizations.view`** see a **Platform** link to **`/platform-admin`** (only on the tenant shell).

## What did not change

- **No** schema/migrations, **no** public API routes, **no** booking/pricing/VAT/checkout behavior, **no** impersonation, **no** broad auth rewrite.
- **No** weakening of tenant data boundaries: platform aggregates are **global SQL counts** for operators who already hold **`platform.organizations.view`**; tenant **`/dashboard`** remains branch/org-context scoped as before.

## Primary files

- `system/modules/dashboard/services/TenantOperatorDashboardService.php`
- `system/modules/dashboard/controllers/DashboardController.php`, `system/modules/dashboard/views/index.php`
- `system/modules/bootstrap/register_dashboard.php`
- `system/shared/layout/platform_admin.php`, `system/shared/layout/base.php`
- `system/modules/organizations/controllers/PlatformControlPlaneController.php`
- `system/modules/organizations/services/PlatformControlPlaneOverviewService.php`
- `system/modules/organizations/repositories/PlatformControlPlaneReadRepository.php`
- `system/modules/organizations/views/platform_control_plane/index.php`
- `system/modules/organizations/views/platform-registry/*.php` (layout swap + links)
- `system/routes/web/register_platform_control_plane.php`, `system/routes/web.php`
- `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php`
- `system/modules/bootstrap/register_organizations.php`
- `system/public/assets/css/app.css`

## ZIP

`distribution/spa-skincare-system-blueprint-FOUNDATION-97-CONTROL-PLANE-SPLIT-WAVE-1-CHECKPOINT.zip` via `handoff/build-final-zip.ps1`.
