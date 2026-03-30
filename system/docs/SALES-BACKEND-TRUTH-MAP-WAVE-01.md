# SALES-BACKEND-TRUTH-MAP-WAVE-01

Repo-truth map of staff Sales runtime (no UI polish). Scope: routes in `register_sales_public_commerce_staff.php`, `Modules\Sales` controllers/services/repos, and payment/register touchpoints used by those paths.

## Route registry (staff)

| HTTP | Path | Controller | Permission notes |
|------|------|------------|------------------|
| GET | `/sales` | `SalesController::index` → `InvoiceController::staffCheckoutFromSalesRoute` | `sales.view` + tenant |
| GET | `/sales/register` | `RegisterController::index` | `sales.view` |
| POST | `/sales/register/open`, `.../close`, `.../movements` | `RegisterController` | `sales.pay` |
| GET | `/sales/invoices` | `InvoiceController::index` | `sales.view` |
| GET/POST | `/sales/invoices/create`, `POST /sales/invoices` | `InvoiceController` | create |
| GET/POST | `/sales/invoices/{id}`, edit, cancel, delete, redeem-gift-card | `InvoiceController` | view/edit/delete/pay+redeem |
| GET/POST | `/sales/invoices/{id}/payments/*` | `PaymentController` | `sales.pay` |
| POST | `/sales/payments/{id}/refund` | `PaymentController::refund` | `sales.pay` |
| GET/POST | `/sales/public-commerce/*` | `PublicCommerceStaffController` | sales.view / pay |

## Surface wiring

### a) GET `/sales`

- **Wiring:** Fully backend-wired for **new-sale cashier prep**. Delegates to `InvoiceController::staffCheckoutFromSalesRoute()` → `renderNewSaleCashierWorkspace()` (same as create, different title). Tenant scope via `ensureProtectedTenantScope()`, branch from `BranchContext` / query / appointment prefill, client prefill via `ClientRepository::find` + `assertBranchMatchOrGlobalEntity`.
- **Risk:** None specific; list navigation is not this route — staff land on **create** workspace, not invoice list.

### b) Cashier / new sale workspace (`/sales`, `/sales/invoices/create`)

- **Wiring:** Fully wired for **draft composition**: `CashierWorkspaceViewDataBuilder` loads clients/services/products/memberships/packages via contracts + repos; `POST /sales/invoices` → `InvoiceController::store` → `InvoiceService::create` (tax from `VatRateService` for service lines, stock contract for products, currency from settings). Standalone membership path via `storeMembershipViaStaffCheckout`.
- **Partial / coupling:** Appointment prefill depends on `AppointmentCheckoutProvider`; gift cards on detail flows more than create.

### c) Manage sales list (`GET /sales/invoices`)

- **Wiring:** Fully backend-wired list: `InvoiceRepository::list` / `count` with `SalesTenantScope::invoiceClause`, filters, pagination, permission flags for edit/delete/create.

### d) Invoice / sale detail (`GET /sales/invoices/{id}`)

- **Wiring:** Fully wired: `find` + branch gate, items, payments, refundable math, gift card lists/redemptions, establishment settings, receipt presentation builder.
- **Coupling:** Reads client row for receipt; uses `SettingsService` (org/branch effective settings).

### e) Client link from sales

- **Wiring:** Partial by design: invoice stores `client_id`; show loads `clientRepository->find` for display/receipt only. Deep client profile is **not** this module’s job — no lifecycle edits here.

### f) Payment / finalize (`GET|POST .../payments`, `PaymentService`)

- **Wiring:** Strong service-layer truth: `findForUpdate`, branch + org asserts, method allowlist, policy (partial/overpay), register session for cash when hardware flag on, financial recompute, audits, optional receipt dispatch.
- **Gap repaired (wave):** Controller previously passed **POST `invoice_id`** into `PaymentService::create` after only validating the **URL** invoice — fixed so **route `invoice_id` is canonical** (see `PaymentController::parseInput`).

### g) Stock / tax / totals / register

- **Tax/totals:** `InvoiceService` documents line rollup; `recomputeInvoiceFinancials` on payment; VAT from catalog for service lines on create/update.
- **Stock:** `InvoiceStockSettlementProvider` on recompute (paid vs not), documented in `InvoiceService`.
- **Register:** `RegisterSessionService` + repos for open/close/movements; **cash** payments require open session when `use_cash_register` (org hardware settings).

## Classification legend

- **Fully backend-wired:** HTTP → controller gates → repo/service with tenant/branch invariants.
- **Partially wired:** Depends on external provider or read-only cross-module data without mutating that domain here.
- **UI-only / misleading:** Not claimed for core invoice CRUD; any stray copy should be verified per-view (out of scope for this wave’s code changes).

## Highest-risk gap addressed this wave

**Payment POST body `invoice_id` could diverge from URL** while branch check used URL invoice only — same-tenant wrong-invoice credit risk. **Fixed:** bind `invoice_id` in `parseInput` to the route parameter.
