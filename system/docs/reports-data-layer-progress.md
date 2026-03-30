# Reports Data Layer — Progress

Backend-first, read-only reports data layer using stable modules only. Branch-aware and date-range-aware.

---

## Changed / added files

| File | Role |
|------|------|
| `system/modules/reports/repositories/ReportRepository.php` | Read-only report queries (existing; gift-card liability subquery fixed) |
| `system/modules/reports/services/ReportService.php` | Builds filters; branch-scoped users cannot override branch; date validation (Y-m-d, date_from ≤ date_to) |
| `system/modules/reports/controllers/ReportController.php` | GET endpoints returning JSON; 400 on invalid date input |
| `system/routes/web.php` | Seven report routes under `/reports/*`; Auth + `reports.view` permission |
| `system/modules/bootstrap.php` | ReportRepository, ReportService, ReportController bindings |
| `system/docs/reports-data-layer-progress.md` | This doc |

---

## Report endpoints and services

All endpoints: **GET**, **JSON** response. Query params: `date_from`, `date_to`, `branch_id` (all optional).

| Endpoint | Controller method | Service / repo |
|----------|-------------------|----------------|
| `/reports/revenue-summary` | `ReportController::revenueSummary` | `ReportService::getRevenueSummary` → `ReportRepository::getRevenueSummary` |
| `/reports/payments-by-method` | `ReportController::paymentsByMethod` | `ReportService::getPaymentsByMethod` → `ReportRepository::getPaymentsByMethod` |
| `/reports/refunds-summary` | `ReportController::refundsSummary` | `ReportService::getRefundsSummary` → `ReportRepository::getRefundsSummary` |
| `/reports/appointments-volume` | `ReportController::appointmentsVolume` | `ReportService::getAppointmentsVolumeSummary` → `ReportRepository::getAppointmentsVolumeSummary` |
| `/reports/new-clients` | `ReportController::newClients` | `ReportService::getNewClientsSummary` → `ReportRepository::getNewClientsSummary` |
| `/reports/gift-card-liability` | `ReportController::giftCardLiability` | `ReportService::getGiftCardLiabilitySummary` → `ReportRepository::getGiftCardLiabilitySummary` |
| `/reports/inventory-movements` | `ReportController::inventoryMovements` | `ReportService::getInventoryMovementSummary` → `ReportRepository::getInventoryMovementSummary` |

Middleware: **AuthMiddleware** + **PermissionMiddleware::for('reports.view')**. Ensure permission code `reports.view` exists and is assigned to roles that may access reports.

---

## Exact metrics supported

- **Revenue summary** (`/reports/revenue-summary`)  
  - `by_currency`: list of `{ currency, total_revenue, count_payments }` from **`payments.currency`** (GROUP BY).  
  - `mixed_currency` (bool): true when more than one currency bucket exists (empty `currency` is its own bucket).  
  - `total_revenue` (float **or null**): sum of amounts only when **`mixed_currency` is false** (single-currency or no rows → 0); **null** when mixed — use `by_currency` only.  
  - `count_payments` (int): always total count in range.  
  - Source: `payments` JOIN `invoices`; `entry_type = 'payment'`, `status = 'completed'`; date on `paid_at`.

- **Payments by method**  
  - List of: `payment_method`, `currency`, `total_amount`, `count_payments`.  
  - Same base as revenue; grouped by `payment_method` and `currency`.

- **Refunds summary** (`/reports/refunds-summary`)  
  - `by_currency`: list of `{ currency, total_refunded, count_refunds }` from **`payments.currency`**.  
  - `mixed_currency` (bool): same rule as revenue.  
  - `total_refunded` (float **or null**): single-currency-safe sum or **null** when mixed.  
  - `count_refunds` (int): always total count in range.  
  - Same join; `entry_type = 'refund'`, `status = 'completed'`; date on `paid_at`.

- **Appointments volume**  
  - `total` (int): total appointments in range.  
  - `by_status` (object): counts per `status` (e.g. `scheduled`, `completed`, `cancelled`).  
  - Source: `appointments`; date on `start_at`.

- **New clients**  
  - `count_new_clients` (int).  
  - Source: `clients`; date on `created_at`.

- **Gift-card liability / usage**  
  - `total_outstanding_balance` (float): sum of latest `balance_after` per active gift card (branch-scoped).  
  - `count_active` (int): count of those cards.  
  - Period activity (when date range set): `issued_in_period`, `redeemed_in_period` (amounts), `count_issued`, `count_redeemed` (counts).  
  - Source: `gift_cards` + `gift_card_transactions`; latest balance via `MAX(id)` per `gift_card_id`; period on `created_at`.

- **Inventory movements**  
  - `total_movements` (int), `by_type` (object: counts per `movement_type`), `quantity_in` (sum of positive quantity), `quantity_out` (absolute sum of negative quantity).  
  - Source: `stock_movements`; date on `created_at`.

---

## Branch and date filter behavior

- **Filter shape:** `branch_id` (int|null), `date_from` (string|null), `date_to` (string|null).  
- **Branch (hardened):**  
  - **Branch-scoped user** (BranchContext has a branch): request `branch_id` is **ignored**; filters always use the context branch. They cannot override to another branch.  
  - **Global/superadmin** (BranchContext branch null): request `branch_id` is used if provided; otherwise null (all branches).  
- **Dates:** Inclusive. When only a date part is given, normalized to start-of-day / end-of-day per column. **Validation:** if both `date_from` and `date_to` are provided they must be valid Y-m-d and `date_from` ≤ `date_to`; otherwise 400 JSON with message (e.g. "Invalid date_from; use Y-m-d.", "date_from must not be after date_to.").  
  - `paid_at` for payments/refunds.  
  - `start_at` for appointments.  
  - `created_at` for clients, gift_card_transactions, stock_movements.  
- **Gift-card outstanding:** Branch filter applies to `gift_cards.branch_id`; no date filter on outstanding (point-in-time). Period metrics use `date_from`/`date_to` on `gift_card_transactions.created_at`.

---

## Manual smoke test checklist

1. **Auth & permission**  
   - Call without auth → 401 or redirect.  
   - Call with auth but without `reports.view` → 403.  
   - Call with auth and `reports.view` → 200 and JSON.

2. **Revenue summary**  
   - `GET /reports/revenue-summary` → JSON has `total_revenue`, `count_payments`.  
   - Add `?date_from=2025-01-01&date_to=2025-12-31` → numbers only for that range.  
   - As global user, add `?branch_id=1` → numbers for that branch.

3. **Payments by method** (and other report endpoints)  
   - Same pattern: valid date range and, for global user, optional `branch_id`.

4. **Branch hardening**  
   - As **branch-scoped** user: call with `?branch_id=OTHER` (another branch id) → response must still be for **your** branch only (branch_id in effect = context branch).  
   - As **global** user: call with `?branch_id=1` → response for branch 1; without branch_id → all branches (if repo supports null).

5. **Date validation**  
   - `?date_from=invalid` → 400 JSON, message like "Invalid date_from; use Y-m-d.".  
   - `?date_from=2025-12-31&date_to=2025-01-01` → 400 JSON, "date_from must not be after date_to.".  
   - Valid `?date_from=2025-01-01&date_to=2025-12-31` → 200.

---

## Access hardening (minimal pass)

- **Branch override:** Branch-scoped users cannot set `branch_id` to another branch; `ReportService::buildFilters()` uses only `BranchContext::getCurrentBranchId()` when context is set. Global users may pass `branch_id` or omit for all-branches.  
- **Permission:** All report routes use `AuthMiddleware` + `PermissionMiddleware::for('reports.view')`. Permission code must exist in `permissions` and be assigned to roles.  
- **Date validation:** If both `date_from` and `date_to` are provided, they must be valid Y-m-d and `date_from` ≤ `date_to`; otherwise 400 JSON with a clear message. Invalid single date (e.g. `date_from=not-a-date`) also returns 400.  
- **Response shape:** Unchanged; read-only; no new fields.

---

## Final hardening pass

A later **final backend hardening pass** added controller-level branch guards and document permission middleware elsewhere; reports layer was unchanged (already branch-aware and permission-protected). See `system/docs/archive/system-root-summaries/HARDENING-SUMMARY.md` §5 and `system/docs/archive/system-root-summaries/BACKEND-STATUS-SUMMARY.md`.

---

## Postponed / not in scope

- **UI:** No report UI or dashboard widgets; layer is backend-only for future consumption.  
- **Analytics/forecasting:** No complex analytics or forecasting.  
- **Register sessions / cash movements:** Not yet used in these reports (can be added later if stable and required).  
- **Metrics skipped:** None; all listed metrics use current stable schemas. If in the future a source is deemed incomplete, skip that metric and document here.

---

## Dashboard consumption

Dashboard (or any client) can call these endpoints with optional `date_from`, `date_to`, `branch_id` and use the JSON. `ReportService` can also be injected where the app runs in PHP (e.g. server-rendered dashboard) and filters built in code instead of from `$_GET`.
