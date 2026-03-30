# FOUNDATION-95 — ADMIN-DASHBOARD-READ-ONLY-FIRST-VISIBLE-SHELL-WAVE-1 (OPS)

## Purpose

This is the **first visible admin dashboard wave**: a single authenticated **home surface** so an owner or operator can land after login, see a concise read-only summary, and jump into modules that already exist.

## Scope (what shipped)

- **`GET /dashboard`** (existing route) renders a **product-style** shell page: one header, one row of **four** summary cards, one **quick links** section.
- **Authenticated `/`** redirects to **`/dashboard`** (was calendar). App shell **brand** and primary **Dashboard** nav item point here.
- **Read-only only:** no forms that mutate data on this page; counts are `SELECT`-style reads only.

## Data sources (safe, existing semantics)

Counts are intentionally boring and aligned with **existing list/index behavior** where possible:

| Card        | Source |
|------------|--------|
| Clients    | `ClientRepository::count([])` — same org-scoped predicate as the clients index without search filters. |
| Appointments | `DashboardReadRepository::countAppointmentsTotal` — non-deleted rows; branch rule `(branch_id = context OR branch_id IS NULL)` when a branch context is set. |
| Staff      | `StaffRepository::count` with `active => true` and optional `branch_id` using the **same fallback** as `StaffController::index` when `BranchContext` is null (session user branch). |
| Services   | `ServiceRepository::count` — same branch filter as `ServiceRepository::list` (`OR branch_id IS NULL` for global rows). |

**No** payment totals, **no** invoice balances, **no** revenue math, **no** charts, **no** inventory valuation, **no** membership billing aggregates on this wave.

## Explicit non-goals

- **No** schema or migrations.
- **No** public route or public booking changes.
- **No** changes to payment, invoice, or membership **business logic**.
- **`DashboardSnapshotService`** remains registered for future operational KPI / drilldown work; the **home** dashboard does **not** call it in this wave (avoids heavy or financial-adjacent snapshot queries on every landing).

## Operator visibility and testability

The page exists so stakeholders can **verify auth, layout, and navigation** from one place and sanity-check **coarse volumes** without interpreting financial semantics.

## Files touched (reference)

- `system/core/router/RootController.php`
- `system/shared/layout/base.php`
- `system/modules/dashboard/controllers/DashboardController.php`
- `system/modules/dashboard/services/DashboardShellSummaryService.php` (new)
- `system/modules/dashboard/repositories/DashboardReadRepository.php` (`countAppointmentsTotal`)
- `system/modules/services-resources/repositories/ServiceRepository.php` (`count`)
- `system/modules/dashboard/views/index.php`
- `system/modules/bootstrap/register_dashboard.php`
- `system/public/assets/css/app.css` (scoped dashboard summary blocks only)

## ZIP checkpoint

Canonical archive: `distribution/spa-skincare-system-blueprint-FOUNDATION-95-ADMIN-DASHBOARD-READ-ONLY-SHELL-WAVE-1-CHECKPOINT.zip` (excludes env files, logs, backups, nested zips per project convention).
