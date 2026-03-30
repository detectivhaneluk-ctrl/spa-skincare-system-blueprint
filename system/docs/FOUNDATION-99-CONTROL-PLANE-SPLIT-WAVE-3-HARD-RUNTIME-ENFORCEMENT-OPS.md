# FOUNDATION-99 — Control plane split (wave 3) — hard runtime enforcement — OPS

**Date:** 2026-03-23  
**Scope label:** `FOUNDATION-99 — CONTROL-PLANE-SPLIT-WAVE-3-HARD-RUNTIME-ENFORCEMENT`
**Status (post FOUNDATION-100):** `PARTIAL` — runtime guards shipped, but effective separation remained contaminated until platform permissions were removed from tenant roles.

## Purpose

Make the founder vs tenant split **undeniable at runtime**: founder-capable users must not use the tenant **`/dashboard`** as their normal plane. This wave adds **controller-level enforcement** and removes **mixed-plane UI** from the tenant shell; it does **not** change permissions, schema, seeds, or public routes.

## Runtime proof (observable behavior)

1. **Founder-capable user** (`platform.organizations.view`): visiting **`GET /dashboard`** receives an **immediate `302` to `/platform-admin`** (`DashboardController::index`). Same permission gate as platform home; optional bookmark/deep-link cannot keep them on the tenant dashboard UI.
2. **Tenant user** (no `platform.organizations.view`): **`GET /platform-admin`** remains **forbidden** — **`PermissionMiddleware`** on the route first; **`PlatformControlPlaneController::index`** repeats the check and responds with **403** (JSON if `Accept: application/json`, else **`HttpErrorHandler`** HTML), matching existing project patterns.
3. **Centralized home** unchanged: **`AuthenticatedHomePathResolver`** still drives **`GET /`** and **post-login** redirect (**founder → `/platform-admin`**, **tenant → `/dashboard`**). Wave 3 **adds** hard enforcement so behavior does not depend on nav links or habit.

## Layout / UX

- **Tenant `layout/base.php`:** **No** “Platform control” cross-link — the tenant shell is **tenant-only** in the header.
- **Platform `layout/platform_admin.php`:** **One** secondary link **“Salon workspace”** → **`/dashboard`** (tooltip clarifies platform operators are redirected back to the control center if they lack tenant-only workflows).

## Primary files

- `system/modules/dashboard/controllers/DashboardController.php`
- `system/modules/organizations/controllers/PlatformControlPlaneController.php`
- `system/shared/layout/base.php`
- `system/shared/layout/platform_admin.php`
- `system/core/auth/AuthenticatedHomePathResolver.php` (doc alignment only)

## What did **not** change

- **No** schema/migrations, **no** new permissions, **no** seed/catalog changes, **no** public endpoints, **no** auth rewrite, **no** impersonation or analytics.
- **No** tenant boundary / booking / pricing / VAT / checkout behavior changes.

## ZIP

`distribution/spa-skincare-system-blueprint-FOUNDATION-99-CONTROL-PLANE-SPLIT-WAVE-3-CHECKPOINT.zip` via `handoff/build-final-zip.ps1` with `-OutputZip` set to that path.
