# FOUNDATION-96 — Founder dashboard control center (wave 1) — OPS

**Date:** 2026-03-23  
**Scope label:** `FOUNDATION-96 — FOUNDER-DASHBOARD-CONTROL-CENTER-WAVE-1`

## Summary

The authenticated admin **`/dashboard`** page was replaced with a **tenant operator read-only control center** (not a generic entity-count summary). The layout presents operational context (scope, timezone), snapshot cards, an attention block, a short “next appointments” table, and a quick-access grid to core modules.

**FOUNDATION-97:** The service was renamed **`TenantOperatorDashboardService`**; the **platform** founder home is **`/platform-admin`** (see **`FOUNDATION-97-CONTROL-PLANE-SPLIT-WAVE-1-OPS.md`**).

## Data sources (read-only, no new semantics)

- **Snapshot metrics:** `DashboardSnapshotService::buildSnapshot` — same time windows and branch rule `(branch_id = context OR branch_id IS NULL)` for appointments and waitlist as documented on that service. **No sales, payment, invoice balance, revenue, or inventory valuation** metrics are shown on this page.
- **Client count:** `DashboardShellSummaryService` / `ClientRepository::count` — aligned with the Clients list org scope.
- **Upcoming list & “starting soon”:** `DashboardReadRepository::listUpcomingAppointmentsForDashboard` and `countAppointmentsStartingSoon` — joins appointments with clients, services, staff, and branch name; branch filter matches existing appointment list behavior.

## What did not change

- **No schema or migrations.**
- **No public routes, booking APIs, pricing, VAT, checkout, or commerce behavior.**
- **No auth or permission model changes.**
- **No new branch selection UI** — the dashboard only **displays** current scope (including **All branches** when context has no branch) and resolves a **branch display name** when scoped.
- **No financial semantics** added or altered; dashboard remains **read-only**.

## Files touched (primary)

- `system/modules/dashboard/services/TenantOperatorDashboardService.php` (was `FounderDashboardService` until FOUNDATION-97)
- `system/modules/dashboard/repositories/DashboardReadRepository.php` (upcoming list + starting-soon count)
- `system/modules/dashboard/controllers/DashboardController.php`
- `system/modules/dashboard/views/index.php`
- `system/modules/bootstrap/register_dashboard.php`
- `system/public/assets/css/app.css` (founder dashboard block)
- `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md` (§8 changelog row)

## ZIP

Canonical handoff artifact: `distribution/spa-skincare-system-blueprint-FOUNDATION-96-FOUNDER-DASHBOARD-CONTROL-CENTER-WAVE-1-CHECKPOINT.zip` (built with `handoff/build-final-zip.ps1`, same exclusions as other checkpoints).
