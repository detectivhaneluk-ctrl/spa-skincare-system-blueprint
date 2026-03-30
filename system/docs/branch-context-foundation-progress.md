# Branch Context Foundation — Progress

## Objective

Implement a minimal, real branch-context foundation: resolve current branch from user/session/request, expose it via one backend access point, and enforce branch scoping on write paths (and key reads where safe) while preserving compatibility for null/global `branch_id` and existing payment/refund/gift-card behavior.

---

## Changed Files

### Core

- `system/core/Branch/BranchContext.php` (new)
- `system/core/app/autoload.php` (Core\Branch namespace)
- `system/core/middleware/BranchContextMiddleware.php` (implemented)
- `system/core/Router/Dispatcher.php` (BranchContextMiddleware added to global middleware)
- `system/core/Audit/AuditService.php` (inject BranchContext; use current branch when branch_id not provided)
- `system/bootstrap.php` (register BranchContext; AuditService takes BranchContext)

### Modules — Sales

- `system/modules/sales/services/InvoiceService.php` (BranchContext; enforce on create, assert on update/cancel/delete/redeemGiftCard)
- `system/modules/sales/services/PaymentService.php` (BranchContext; assert on create/refund)
- `system/modules/sales/services/RegisterSessionService.php` (BranchContext; assert on open/close/addCashMovement)

### Modules — Clients

- `system/modules/clients/services/ClientService.php` (BranchContext; enforce on create, assert on update/delete/merge)
- `system/modules/clients/services/ClientRegistrationService.php` (BranchContext; enforce on create, assert on updateStatus/convert)
- `system/modules/clients/services/ClientIssueFlagService.php` (BranchContext; enforce on create, assert on resolve)

### Modules — Appointments

- `system/modules/appointments/services/AppointmentService.php` (BranchContext; enforce on create, assert on update/cancel/delete)
- `system/modules/appointments/services/WaitlistService.php` (BranchContext; enforce on create, assert on updateStatus/linkToAppointment/convertToAppointment)
- `system/modules/appointments/services/BlockedSlotService.php` (BranchContext; enforce on create, assert on delete)

### Modules — Staff

- `system/modules/staff/services/StaffService.php` (BranchContext; enforce on create, assert on update/delete)

### Modules — Services-Resources

- `system/modules/services-resources/services/ServiceCategoryService.php` (BranchContext; enforce on create, assert on update/delete)
- `system/modules/services-resources/services/ServiceService.php` (BranchContext; enforce on create, assert on update/delete)
- `system/modules/services-resources/services/RoomService.php` (BranchContext; enforce on create, assert on update/delete)
- `system/modules/services-resources/services/EquipmentService.php` (BranchContext; enforce on create, assert on update/delete)

### Modules — Inventory

- `system/modules/inventory/services/ProductService.php` (BranchContext; enforce on create, assert on update/delete)
- `system/modules/inventory/services/SupplierService.php` (BranchContext; enforce on create, assert on update/delete)

### Modules — Gift Cards

- `system/modules/gift-cards/services/GiftCardService.php` (BranchContext; enforce on issue; use getCurrentBranchId() when context not passed in redeem/adjust/cancel/expire)

### Modules — Packages

- `system/modules/packages/services/PackageService.php` (BranchContext; enforce on createPackage/assignPackageToClient, assert on updatePackage; use getCurrentBranchId() in use/adjust/reverse/cancel when context not passed)

### Wiring

- `system/modules/bootstrap.php` (BranchContext injected into all updated services)

### Docs

- `system/docs/branch-context-foundation-progress.md` (this file)

---

## Branch Rules Added

### 1. Central resolution (BranchContextMiddleware)

- **When:** Every request (global middleware after Csrf + ErrorHandler).
- **Source:** Authenticated user from session (`SessionAuth::user()`). If no user, current branch is `null`.
- **Order:** Request override (GET/POST `branch_id`) if allowed → session `branch_id` if allowed → user’s `users.branch_id`.
- **Allowed branches:** If user has `branch_id` set, only that branch is allowed. If user’s `branch_id` is null (superadmin), any branch or null is allowed.
- **Persistence:** Resolved branch is stored in `$_SESSION['branch_id']` for the next request when resolved branch is not null.

### 2. Single access point (BranchContext)

- **`BranchContext::getCurrentBranchId(): ?int`** — Current branch for the request; `null` means global/superadmin.
- **`BranchContext::assertBranchMatch(?int $entityBranchId): void`** — Throws `DomainException` when context is set and entity has a non-null branch different from context. Null entity branch is allowed (global records).
- **`BranchContext::enforceBranchOnCreate(array $data, string $key = 'branch_id'): array`** — When context is set: if payload has a different branch_id, throws; otherwise sets `data[$key]` to context. Returns updated `$data`.

### 3. Write-path enforcement

- **Create:** Services that create branch-scoped entities call `enforceBranchOnCreate($data)` so new records get current branch when user is scoped; mismatch is rejected.
- **Update / Delete / Cancel / Refund / Redeem / etc.:** After loading the entity, services call `assertBranchMatch($entity['branch_id'])` so branch-scoped users cannot modify another branch’s records. Global (null) records remain editable when context is set.

### 4. Branch mismatch rejection

- Explicit `DomainException`: “This record belongs to another branch and cannot be modified.” or “Branch does not match your assigned branch.” (wording may vary by service).

### 5. Audit metadata

- **AuditService::log(..., $branchId, ...):** When `$branchId` is null, the service uses `BranchContext::getCurrentBranchId()` so audit rows get the current branch when the caller does not pass one. Existing callers that pass entity branch are unchanged.

### 6. Compatibility preserved

- **Null/global branch_id:** Records with `branch_id` null are not rejected by `assertBranchMatch`; they remain globally visible/editable. Create can still set branch from context when context is set.
- **Payment/refund/register/gift-card:** No change to payment, refund, register-session, or gift-card logic beyond branch checks; refund and gift-card reversal flows unchanged. Sales Phase 3.5 behavior (e.g. invoice finalization, issued_at) is unchanged.
- **Superadmin:** User with `users.branch_id` null has context null; no branch restriction.

---

## Modules Covered

| Module            | Enforced create        | Assert update/delete/other writes      |
|-------------------|-------------------------|----------------------------------------|
| Sales (invoices)  | Yes                     | update, cancel, delete, redeemGiftCard |
| Sales (payments)  | N/A (invoice-scoped)    | create, refund                         |
| Sales (register)  | openSession (assert)    | closeSession, addCashMovement          |
| Clients           | create                  | update, delete, merge                  |
| Client registrations | create               | updateStatus, convert                  |
| Client issue flags   | create               | resolve                                |
| Appointments      | create                  | update, cancel, delete                 |
| Waitlist          | create                  | updateStatus, linkToAppointment, convertToAppointment |
| Blocked slots     | create                  | delete                                 |
| Staff             | create                  | update, delete                         |
| Services-resources| ServiceCategory, Service, Room, Equipment — create | update, delete |
| Inventory         | Product, Supplier — create | update, delete                      |
| Gift cards        | issueGiftCard           | redeem, adjust, cancel, expire (branch from context when not passed) |
| Packages          | createPackage, assignPackageToClient | updatePackage; use/adjust/reverse/cancel (branch from context when not passed) |

---

## Manual Smoke Test Checklist

- [ ] **Context resolution (branch user):** Log in as user with `users.branch_id` set (e.g. 1). Open any list (e.g. invoices, clients). Confirm data is scoped (e.g. only branch 1 or global). Create new invoice/client/appointment; confirm branch is set to 1 and save succeeds.
- [ ] **Context resolution (superadmin):** Log in as user with `users.branch_id` null. Confirm no branch restriction; can create/edit records with any branch or null.
- [ ] **Request override:** As branch user 1, open a page with `?branch_id=1`; confirm context is 1. With `?branch_id=2` (if user is branch 1 only), confirm context remains 1 or request is rejected where enforced.
- [ ] **Write rejection (wrong branch):** As branch 1 user, obtain an entity ID that belongs to branch 2 (e.g. invoice/client from another branch). Try to update or delete it (e.g. POST to update form or delete). Expect “This record belongs to another branch and cannot be modified.” (or similar).
- [ ] **Create with context:** As branch user, create client/invoice/appointment without sending branch_id in form; confirm branch is set to user’s branch and save succeeds.
- [ ] **Register:** Open register for branch 1 (user branch 1); open session and close; add cash movement. Repeat as branch 2 user for branch 2; confirm branch 2 user cannot open session for branch 1.
- [ ] **Payment / refund / gift card:** Record payment on branch-scoped invoice; refund; redeem gift card. Confirm no regressions; audit log has branch when applicable.
- [ ] **Audit:** Trigger an action that logs audit without passing branch_id; confirm audit_logs row has branch_id set from context when user is branch-scoped.

---

## Final hardening pass (backend-only)

A later **final backend hardening pass** added: controller-level branch guards (`ensureBranchAccess`) after find in Clients, Appointments, Sales (invoices), Inventory (products, suppliers) for show/edit/update/destroy and related single-record actions; branch enforcement in **client custom field definitions** (create/update in ClientService) and in **StockMovementService** and **InventoryCountService** write paths. See `system/docs/archive/system-root-summaries/HARDENING-SUMMARY.md` §5.

---

## Intentionally Postponed

- **Read-path scoping in controllers:** List/index still use existing filters (e.g. `branch_id` from query). Defaulting list to current branch when query has no branch_id is not implemented; can be added later in controllers.
- **Multi-branch UI / switcher:** No UI branch switcher or “current branch” indicator; session/request override is enough for backend foundation. UI can be added later.
- **RecomputeInvoiceFinancials total validation:** Not reopened; Sales Phase 3.5 behavior is preserved.
