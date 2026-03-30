## OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01

Date: 2026-03-23  
Type: AUDIT-ONLY (code-truth triage; no hardening changes)

### Remaining module scope matrix

| Module | Tenant-exposed runtime | Public-exposed | Protected read paths | Protected write paths | Key entry points | Current scoping style | Lifecycle/suspension governance | Risk severity | Recommended action |
|---|---|---|---|---|---|---|---|---|---|
| Sales (invoices/payments/register) | yes | staff-only public-commerce ops + core public-commerce dependency | `/sales`, `/sales/invoices*`, `/sales/register*` | invoice CRUD, payments/refunds, register mutations | `InvoiceController`, `PaymentController`, `InvoiceService`, `PaymentService`, `InvoiceRepository`, `PaymentRepository` | mixed: branch-or-null list in repos + unscoped id lookup in repos + service caller discipline (`BranchContext` + `OrganizationScopedBranchAssert`) | partial (global auth/runtime gate only; no module-local lifecycle gate) | **critical** (financial + cross-module settlement blast radius) | **harden next** |
| Inventory (products/stock/categories/brands/suppliers/counts) | yes | no | `/inventory/*` lists/show | product/category/brand/supplier/count/movement creates/updates/deletes | inventory controllers, `ProductService`, `StockMovementService`, `ProductRepository`, `StockMovementRepository` | mixed: branch-scoped and branch-or-null/global list patterns + unscoped id repos + service caller discipline | partial (global auth/runtime gate only) | **high** (operational data leakage + stock corruption risk) | harden next (wave 2) |
| Memberships | yes | indirect via public commerce | `/memberships*` | definitions, assign/lifecycle, sales/refund-review | membership controllers/services + repositories | branch-scoped plus branch-or-null/global definition patterns + repo id lookups; strong service branch enforcement | partial (global runtime gate; no explicit module lifecycle gate) | high | wave 3 bundle |
| Gift cards | yes | indirect via public commerce + invoice gift-card flows | `/gift-cards*` | issue/redeem/adjust/cancel | `GiftCardController`, `GiftCardService`, `GiftCardRepository` | branch-scoped list filters + repo id lookup + service branch assertion | partial | high | wave 3 bundle |
| Packages | yes | indirect via public commerce + appointments | `/packages*` | definitions + assign/use/adjust/reverse/cancel | package controllers/services + repositories | branch-scoped list filters + repo id lookup + service branch assertion | partial | high | wave 3 bundle |
| Reports | yes | no | `/reports/*` | read-only | `ReportController`, `ReportService`, `ReportRepository` | branch filter + branch-or-null/global in several aggregates; relies on service/caller filter construction | partial | medium-high (cross-tenant visibility if filter discipline regresses) | harden with sales/inventory wave follow-up |
| Documents | yes | no public route in module; internal only | `/documents/*` reads + download | definitions/consents/upload/relink/archive/delete | `DocumentController`, `DocumentService`, repositories | repo id lookup; service-level owner resolution + branch assertion (caller discipline heavy but explicit) | partial | medium | defer after financial/data-plane waves |
| Notifications | yes | no | `/notifications` list/read | mark read/read-all | `NotificationController`, `NotificationService`, `NotificationRepository` | branch-or-null/global + user-or-null visibility patterns in repo; controller visibility checks | partial | medium | public-surface follow-up not needed; tenant hardening later |
| Marketing | yes | no | `/marketing/campaigns*` | campaign CRUD, freeze/dispatch/cancel runs | `MarketingCampaignController`, `MarketingCampaignService`, marketing repositories | org-scope fragments present but **legacy unresolved-org fallback** (id/campaign unscoped in run repo; branch-or-null behavior) | partial | high | wave 2/3 depending capacity |
| Payroll | yes | no | `/payroll/runs*`, `/payroll/rules*` | rule CRUD, run calculate/lock/settle | payroll controllers/service/repositories | org-scope fragments present but **legacy unresolved-org/global fallback** in repos; create unscoped repo insert | partial | high | wave 2/3 depending capacity |
| Intake (additional tenant/public reachable) | yes | yes (`/public/intake*`) | `/intake/*` admin read | template/assignment writes + public submit | intake admin/public controllers + intake services/repos | not evaluated as closed by prior waves in this matrix; includes anonymous entry path | partial | medium-high | public-surface follow-up after core financial hardening |

### Evidence anchors (representative)

- `system/routes/web/register_sales_public_commerce_staff.php`
- `system/routes/web/register_inventory.php`
- `system/routes/web/register_reports.php`
- `system/routes/web/register_documents.php`
- `system/routes/web/register_notifications.php`
- `system/routes/web/register_marketing.php`
- `system/routes/web/register_payroll.php`
- `system/modules/intake/routes/web.php`
- `system/modules/sales/repositories/InvoiceRepository.php`
- `system/modules/sales/repositories/PaymentRepository.php`
- `system/modules/sales/services/InvoiceService.php`
- `system/modules/inventory/repositories/ProductRepository.php`
- `system/modules/reports/repositories/ReportRepository.php`
- `system/modules/marketing/repositories/MarketingCampaignRunRepository.php`
- `system/modules/payroll/repositories/PayrollRunRepository.php`
- `system/modules/documents/services/DocumentService.php`

### Ranked next implementation waves (evidence-based)

1. `SALES-TENANT-DATA-PLANE-HARDENING-01` (`NEXT-UP`)
2. `INVENTORY-TENANT-DATA-PLANE-HARDENING-01` (`PARTIAL` — see `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md`)
3. `MEMBERSHIPS-GIFTCARDS-PACKAGES-TENANT-DATA-PLANE-HARDENING-01` (`OPEN`)
