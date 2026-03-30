# Bootstrap registration topology — truth audit (read-only)

**Wave:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-01` — **BOOTSTRAP-REGISTRATION-TOPOLOGY-TRUTH-AUDIT**  
**Mode:** Proof-only. No runtime, schema, or binding changes.  
**Date:** 2026-03-22 (workspace audit)

---

## 1. Purpose

Record **authoritative fact** about how services are registered today so a future **modular bootstrap** extraction can preserve behavior. This doc is the **single canonical audit** for this wave.

---

## 2. Authoritative files (registration layer)

| Role | Path |
|------|------|
| **Core bootstrap** (container creation + core singletons) | `system/bootstrap.php` |
| **Module / app singleton registrations** (bulk) | `system/modules/bootstrap.php` (**orchestrator**) + `system/modules/bootstrap/register_*.php` (**16** fragments; **222** `singleton`s, same order as audit baseline) |
| **Container implementation** | `system/core/app/Container.php` |
| **Global container holder** | `system/core/app/Application.php` (`setContainer` / `container()`) |
| **HTTP resolution of controllers** | `system/core/router/Dispatcher.php` |
| **HTTP entry** | `system/public/index.php` — `require bootstrap.php` then `modules/bootstrap.php` |

**Not** registration files (but load order matters):

- `system/core/app/helpers.php` (loaded from `bootstrap.php` before `Env::load`)
- Routes: `system/routes/web.php` (loaded by `Application::buildRouter()`); `system/modules/intake/routes/web.php` is **`require`’d** from `web.php` line ~275 (partial route modularization only).

---

## 3. Container mechanics (truth)

- **API:** `singleton(string $id, callable $factory)` and `get(string $id)` only. **`bind()`** exists in `Container` but **is not used** anywhere in `system/` (verified grep).
- **Semantics:** Every registration uses **`singleton`** → first `get()` runs the closure and caches the instance.
- **IDs:** Registration keys are **concrete class names** (PHP `::class` strings), including for `Core\Contracts\*` interfaces.
- **No** tagged collections, **no** auto-wiring, **no** conditional registration in PHP — all entries are unconditional.

---

## 4. Registration counts

### 4.1 Core bootstrap (`system/bootstrap.php`)

| # | Binding ID | Implementation | Style |
|---|------------|----------------|--------|
| 1 | `Core\App\Config` | `new Config(SYSTEM_PATH . '/config')` | Factory, no deps in closure except path |
| 2 | `Core\App\Database` | `new Database(Config)` | Factory |
| 3 | `Core\Auth\SessionAuth` | `new SessionAuth(Database)` | Factory |
| 4 | `Core\Auth\LoginThrottleService` | `new LoginThrottleService(Database)` | Factory |
| 5 | `Core\Auth\UserPasswordResetTokenRepository` | Repository + Database | Factory |
| 6 | `Core\Auth\PasswordResetRequestLogRepository` | Repository + Database | Factory |
| 7 | `Core\Auth\AuthService` | AuthService(SessionAuth, LoginThrottleService) | Factory |
| 8 | `Core\Branch\BranchContext` | `new BranchContext()` | Factory, **no** `$c` usage |
| 9 | `Core\Branch\BranchDirectory` | BranchDirectory(Database) | Factory |
| 10 | `Core\Permissions\StaffGroupPermissionRepository` | Repository + Database | Factory |
| 11 | `Core\Permissions\PermissionService` | PermissionService(Database, BranchContext, StaffGroupPermissionRepository) | Factory |
| 12 | `Core\Audit\AuditService` | AuditService(Database, BranchContext) | Factory |
| 13 | `Core\App\SettingsService` | SettingsService(Database) | Factory |
| 14 | `Core\Errors\HttpErrorHandler` | `new HttpErrorHandler()` | Factory, empty |

**Total: 14** core singletons.

### 4.2 Module bootstrap (`system/modules/bootstrap.php`)

- **`$container->singleton(...)` calls:** **222** (entire file is sequential registrations; **~372 lines** of code).
- **By `Modules\…` namespace prefix** (binding *key* counts):

| Module / prefix | Registrations |
|-----------------|---------------|
| Inventory | 49 |
| Sales | 23 |
| Memberships | 15 |
| ServicesResources | 15 |
| Staff | 12 |
| Notifications | 12 |
| Clients | 10 |
| Appointments | 10 |
| Intake | 8 |
| Documents | 7 |
| Packages | 6 |
| Marketing | 6 |
| Payroll | 6 |
| OnlineBooking | 5 |
| PublicCommerce | 5 |
| GiftCards (module classes only) | 4 |
| Dashboard | 3 |
| Reports | 3 |
| Settings | 2 |
| Branches | 1 |
| Auth | 1 (`PasswordResetService` only) |

- **Additional keys** in the same file whose binding IDs live under **`Core\Contracts\*`:** **19** (interface → module implementation bridges).

**Cross-check:** 203 (`Modules\*`) + 19 (`Core\Contracts\*`) = **222** ✓

---

## 5. Extraction buckets (classification)

Bindings are classified by **primary owning domain** for a *future* file split. **`Core\Contracts\*`** entries are listed under **contracts / integration** (they are **implemented** by modules but **consumed** everywhere).

### 5.1 Core / platform (stay near core)

- All of **`system/bootstrap.php`**.
- Intended to remain **infrastructure**: Config, Database, SessionAuth, AuthService, BranchContext, BranchDirectory, PermissionService (+ StaffGroupPermissionRepository), AuditService, SettingsService, HttpErrorHandler, password-reset **repositories**.

### 5.2 Auth / security (mixed registration today)

- **In module bootstrap:** `Modules\Auth\Services\PasswordResetService` (depends on outbound notifications repo).
- **Not** in bootstrap: `LoginController`, `PasswordResetController`, `AccountPasswordController` — resolved via **`new Class()`** in `Dispatcher` when not registered (see §7).
- **Pattern:** Auth **HTTP** uses `Application::container()->get(...)` inside methods (service locator), not constructor DI.

### 5.3 Settings (thin in DI)

- **Controllers:** `PaymentMethodsController`, `VatRatesController` (Sales services injected).
- **Not registered:** `SettingsController` — **no** `singleton` entry; uses **`Application::container()->get()`** inside actions.

### 5.4 Clients

- Repositories, services, `ClientController`, `Core\Contracts\ClientListProvider` → `ClientListProviderImpl`.

### 5.5 Staff

- Staff repos/services/controllers, `StaffAvailabilityExceptionRepository` (registered **between** Appointments repos and `AvailabilityService` — **ordering** couples Staff to Appointments block).

### 5.6 Services / catalog (ServicesResources)

- Service/room/equipment/category repos & services, controllers, `ServiceListProvider`, `RoomListProvider`.
- **Cross-module deps in factories:** `VatRateService` (Sales), `StaffGroupRepository` (Staff).

### 5.7 Appointments + waitlist + blocked slots

- Appointment repos, `AvailabilityService`, `AppointmentService`, `AppointmentSeriesService`, `WaitlistService`, `BlockedSlotService`, `AppointmentController`.
- **Heavy cross-module deps:** Staff schedules/breaks/exceptions, ServiceStaffGroupEligibility, Documents Consent, Notifications, Memberships, Intake.

### 5.8 Documents

- Consent + document repos/services + `DocumentController`.

### 5.9 Notifications / outbound

- Repos, template renderer, **three** mail transport singletons, outbound transactional + marketing enqueue + dispatch, `NotificationService`, `NotificationController`.

### 5.10 Online booking (public)

- Abuse guard repos/service, `PublicBookingService`, `PublicBookingController`.

### 5.11 Gift cards

- Module repos/services/controller.
- **Contracts:** `GiftCardAvailabilityProvider`, `InvoiceGiftCardRedemptionProvider`, `ClientGiftCardProfileProvider` — **two** interfaces → **same** `GiftCardSalesProviderImpl` instance type (separate singleton entries).

### 5.12 Packages

- Package repos/service/controllers + `PackageAvailabilityProvider`, `AppointmentPackageConsumptionProvider`, `ClientPackageProfileProvider`.

### 5.13 Inventory / stock / product taxonomy

- Largest block: taxonomy audits, stock quality stack, settlement, product CRUD, many controllers.
- **Sales coupling:** `InvoiceStockSettlementService` factories pull `InvoiceRepository` / `InvoiceItemRepository` from Sales.
- **`Core\Contracts\InvoiceStockSettlementProvider`** → `InvoiceStockSettlementProviderImpl` with **lazy** `fn () => $c->get(InvoiceStockSettlementService::class)` (see §7).

### 5.14 Sales / invoices / payments / register

- Invoice/payment/VAT/register repos, truth-audit services, `InvoiceService`, `PaymentService`, controllers, `ClientSalesProfileProvider`, **`CatalogSellableReadModelProvider`** (pulls ServiceRepository + ProductRepository).

### 5.15 Public commerce

- Purchase repo, `PublicCommerceFulfillmentReconciler`, repair service, `PublicCommerceService`, staff + public controllers.
- **`Core\Contracts\PublicCommerceFulfillmentSync`** → **same instance** as `PublicCommerceService` (`fn ($c) => $c->get(PublicCommerceService::class)`).

### 5.16 Memberships

- All membership repos/services/controllers; **`Core\Contracts\MembershipInvoiceSettlementProvider`** with **nested lazy** resolvers for billing + sale services.

### 5.17 Branches / reports / dashboard / intake / marketing / payroll

- Small, mostly self-contained groups; Intake pulls Clients, Appointments, SessionAuth, etc.

### 5.18 Unresolved / mixed ownership (explicit)

- **`StaffAvailabilityExceptionRepository`** registered in **module** bootstrap but is **`Modules\Staff\...`** while placed **adjacent to** Appointments setup — bucket = **Staff**, but **file order** ties it to Appointments extraction order.
- **Sales vs Settings:** `VatRateService` / `PaymentMethodService` live under Sales module but power Settings controllers.
- **Auth vs Notifications:** `PasswordResetService` is Auth but registered next to outbound notification stack.

---

## 6. Modularization hazards

1. **File order = effective registration order**  
   PHP executes `modules/bootstrap.php` top to bottom. **Moving** a binding before its dependencies’ classes are available is fine (closures defer resolution), but **moving** entries changes **readability only** — *however*, any future **side-effect** registration would make order critical; today closures are pure.

2. **Lazy / deferred `get()` inside factories (cycle-breaking)**  
   - `MembershipInvoiceSettlementProvider`: `fn () => $c->get(MembershipBillingService::class)` and same for `MembershipSaleService`.  
   - `InvoiceStockSettlementProvider`: `fn () => $c->get(InvoiceStockSettlementService::class)`.  
   - `InvoiceService` constructor receives `fn () => $c->get(PublicCommerceFulfillmentReconciler::class)` as last argument (lazy).  
   - `PaymentService` and `MembershipSaleService` similarly receive lazy reconciler closure.  
   **Risk:** Splitting these across files without preserving **lazy** semantics can reintroduce **circular instantiation** errors.

3. **Duplicate interface → same implementation class**  
   `GiftCardSalesProviderImpl` is registered **twice** under two different **`Core\Contracts\*`** IDs. Not wrong, but **two singleton instances** unless PHP returns same class — they are **separate** container keys → **two instances** of the same impl class. Consumers must not assume identity across interfaces unless documented.

4. **`PublicCommerceFulfillmentSync` alias**  
   Separate key pointing to same object as `PublicCommerceService` — extraction must keep **both** keys or update all consumers.

5. **Dispatcher fallback: unregistered controllers**  
   `Dispatcher::invokeHandler`: `$this->container->has($class) ? $this->container->get($class) : new $class();`  
   **Unregistered** controllers get **no constructor injection**; they use **`Application::container()`** internally. Splitting bootstrap **without** registering these will **not** break them today, but **registering** them later changes **lifecycle** (singleton vs new instance per request). Today **`new $class()`** creates a **new** controller per request for those classes.

6. **Middleware resolution**  
   `Dispatcher` uses `container->has($m) ? get : new $m()` for string middleware. Middleware classes **without** bindings are constructed with **`new`**.

7. **No `bind()` (non-singleton)**  
   Everything is singleton. No transient/request-scoped pattern.

8. **Cross-module graph density**  
   **AppointmentService**, **InvoiceService**, **PublicCommerceService**, **MembershipSaleService** factories pull from **many** modules. Extracting “appointments bootstrap” in isolation requires **either** keeping shared contracts registered centrally **or** duplicating careful ordering.

9. **Scripts and HTTP share one module bootstrap**  
   CLIs that `require modules/bootstrap.php` instantiate **full** module graph (222 bindings) even if they need one service — modularization may allow **subset** loading in future (out of scope for this audit).

10. **Core `SettingsService` is not a module**  
   Lives in `Core\App` but is the **dominant** dependency across modules — **must stay** in core bootstrap or an early “core services” registrar.

---

## 7. Safest target extraction architecture (proposal — not implemented)

### 7.1 Keep a thin central orchestrator

- **`system/bootstrap.php`** remains the **only** place that constructs `Container` and registers **core** services.
- Add a **single** orchestrator entry after core, e.g. `require SYSTEM_PATH . '/modules/bootstrap_register.php';` which **only** `require`s ordered module registrar fragments — **no** new logic in that file long-term.

### 7.2 Recommended registrar file shape (future)

Under `system/modules/bootstrap/` (or `system/modules/_di/`):

| File (suggested) | Contents |
|------------------|----------|
| `register_clients.php` | Clients + `ClientListProvider` |
| `register_staff.php` | Staff + `StaffListProvider` + `StaffAvailabilityExceptionRepository` |
| `register_services_resources.php` | ServicesResources + Service/Room list providers |
| `register_documents.php` | Documents |
| `register_notifications.php` | Notifications + transports + `PasswordResetService` (or split Auth) |
| `register_appointments.php` | Appointments, waitlist, blocked slots, availability, appointment controllers, appointment-related contracts |
| `register_online_booking.php` | Public booking |
| `register_gift_cards.php` | Gift cards + gift contracts |
| `register_packages.php` | Packages + package contracts |
| `register_inventory.php` | Full inventory block + `InvoiceStockSettlement*` |
| `register_sales.php` | Sales/payment/register + truth audits + `InvoiceService`/`PaymentService` + sales-related contracts |
| `register_public_commerce.php` | Public commerce + fulfillment sync alias |
| `register_memberships.php` | Memberships + settlement provider |
| `register_branches_reports_dashboard.php` | Small modules |
| `register_intake.php` | Intake |
| `register_marketing_payroll.php` | Marketing + Payroll |
| `register_settings_sales_controllers.php` | PaymentMethods + VAT controllers (or fold into sales/settings) |

**Central file order** must respect **dependency layers**, e.g.:

1. Clients + Staff + ServicesResources (foundational list providers)  
2. Documents, Notifications (shared infrastructure)  
3. Appointments (+ Availability)  
4. Online booking  
5. Gift cards, Packages  
6. Inventory (before or with Sales — **InvoiceStockSettlement** needs Sales repos)  
7. Sales (InvoiceService after inventory settlement service registration)  
8. Public commerce, Memberships (heavy cycles — keep lazy factories together)  
9. Remaining modules  

Exact order should be **mechanical copy** of current `modules/bootstrap.php` sequence in the first implementation PR.

### 7.3 What should stay central for the first iterations

- All **`system/bootstrap.php`** core services.
- **`Core\Contracts\*`** registrations could stay in a **`register_contracts.php`** **or** co-locate with the implementing module — **recommendation:** co-locate with implementer **except** where two modules share one contract resolution (then a small **integration** file).

### 7.4 Preconditions before extraction PR

- Frozen **snapshot** of this doc + diff tool: “no behavior change” = same binding IDs and same factory closures (moved only).
- Optional: script that **parses** registrar files and asserts **222 + 14** bindings (post-extraction guard).
- Decide policy for **unregistered controllers**: either **register** them (behavior change: singleton controllers) **or** leave as `new` and document — **recommendation:** separate task; do not mix with first extraction.

---

## 8. Recommended future implementation order (rollout)

1. **Mechanical split:** Extract **pure leaf** modules first (Branches, Reports, Dashboard, Marketing, Payroll, Intake) — few cross edges.  
2. **Clients + Staff + ServicesResources** — establishes list providers used downstream.  
3. **Documents + Notifications** (+ keep `PasswordResetService` adjacent to notifications or move to `register_auth.php`).  
4. **Gift cards + Packages** (contracts).  
5. **Appointments + Online booking** (high coupling).  
6. **Inventory** (large; depends Sales repos for settlement).  
7. **Sales** (InvoiceService / PaymentService + lazy reconciler).  
8. **Public commerce + Memberships** (cycle-heavy; verify lazy closures after each step).  
9. **Settings controllers** + optional **Auth controllers** registration policy (separate decision).

---

## 9. Appendix: registrations outside module registrar fragments

| Location | What |
|----------|------|
| `system/bootstrap.php` | 14 core `singleton` registrations (see §4.1) |
| `system/modules/bootstrap/register_*.php` | 222 module/app `singleton` registrations (same factories/order as former single file) |
| Elsewhere under `system/` | **No** `->singleton(` / `->bind(` found outside `system/bootstrap.php` and `system/modules/bootstrap/*.php` |

**Service location from non-bootstrap code:** Many classes call `Application::container()->get(...)` — that is **consumption**, not registration.

---

## 10. References

- `system/core/app/Container.php`  
- `system/core/router/Dispatcher.php`  
- `BOOKER-PARITY-MASTER-ROADMAP.md` §6 (platform Phase 1)  

**Implementation (FOUNDATION-04):** The monolithic module registrar body was mechanically split into `system/modules/bootstrap/register_*.php` and loaded in **fixed order** by `system/modules/bootstrap.php` — **no** binding ID or factory changes. Mixed-ownership groups from §5.18 remain **co-located** with their original neighbors (not further split in that wave).

**Stop:** Further bootstrap refactors (subset loading, extra registrars) await explicit review — do not widen scope ad hoc.
