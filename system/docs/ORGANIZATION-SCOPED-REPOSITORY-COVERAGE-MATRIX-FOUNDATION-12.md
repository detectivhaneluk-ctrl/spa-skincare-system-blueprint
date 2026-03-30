# F-12 — Repository coverage matrix (FOUNDATION-11 vs persistence layer)

**Companion:** `ORGANIZATION-SCOPED-REPOSITORY-PATTERN-TRUTH-AUDIT-FOUNDATION-12-OPS.md`  
**Legend:** **F-11** = `OrganizationScopedBranchAssert` on listed **service** methods. **Repo** = `*Repository::find` / `findForUpdate` / typical `UPDATE WHERE id` (no org join proved in F-12 audit).

| Domain | Primary repositories (examples) | F-11 org guard (service/controller) | Repo-level org predicate today | Typical list scope |
|--------|----------------------------------|-------------------------------------|-------------------------------|-------------------|
| Branches | *(not a Repository class)* — `BranchDirectory` | Yes (`BranchDirectory` + admin UX) | N/A (core class) | Admin lists all branches |
| Sales invoices/payments | `InvoiceRepository`, `PaymentRepository`, `InvoiceItemRepository` | Yes on **`InvoiceService`** / **`PaymentService`** mutates | **No** | Optional `branch_id` + `OR NULL` |
| Sales register | `RegisterSessionRepository`, `CashMovementRepository` | **No** (F-11 list) — `BranchContext` in `RegisterSessionService` | **No** | By `branch_id` helpers |
| Clients | `ClientRepository`, field repos | Yes on **`ClientService`** mutates | **No** | Search; optional `branch_id` |
| Appointments | `AppointmentRepository`, `WaitlistRepository`, `BlockedSlotRepository` | **No** F-11 | **No** | Optional `branch_id` + `OR NULL` |
| Staff | `StaffRepository`, schedules/breaks/exceptions, `StaffGroupRepository` | **No** F-11 | **No** | Usually branch filters in services |
| Services/resources | `ServiceRepository`, rooms, equipment, categories | **No** F-11 | **No** | Branch filters in repos/services |
| Inventory | `ProductRepository`, suppliers, movements, counts | **No** F-11 | **No** | Branch filters |
| Documents/consents | `DocumentRepository`, consent repos | **No** F-11 | **No** | Mixed |
| Marketing | `MarketingCampaign*Repository` | **No** F-11 | **No** | Optional `branch_id` + `OR NULL` |
| Payroll | `PayrollRunRepository`, rules, commission lines | **No** F-11 | **No** | Branch on run (schema) |
| Intake | Intake form repos | **No** F-11 | **No** | Template/branch mix |
| Memberships/packages | Multiple `Membership*`, `Package*` repos | **Deferred** module policy | **No** | Various |
| Gift cards | `GiftCardRepository`, transactions | **No** F-11 | **No** | Branch in service |
| Notifications | `NotificationRepository`, outbound repos | **No** F-11 | **No** | User/branch visibility in service |
| Public commerce | `PublicCommercePurchaseRepository` + uses `InvoiceRepository` | **No** (and must not inherit staff-only guards blindly) | **No** | Token/invoice id |
| Online booking | `PublicBookingManageTokenRepository`, etc. | **No** | **No** | Token/branch from booking |
| Core auth/RBAC | Auth + `StaffGroupPermissionRepository` | **Out of scope** | **No** | N/A |

**Read path gap (examples):** `InvoiceController::show` / `PaymentController::create` use repositories directly; **F-11 does not run** on those GET/render paths.
