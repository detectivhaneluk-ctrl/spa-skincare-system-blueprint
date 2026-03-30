# Organization-scoped repository pattern — truth audit (FOUNDATION-12)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-12 — ORGANIZATION-SCOPED-REPOSITORY-PATTERN-TRUTH-AUDIT  
**Mode:** Read-only audit only (no runtime changes, no repository edits, no schema changes).  
**Source of truth:** FOUNDATION-06 (`ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`), FOUNDATION-07 checklist/design, FOUNDATION-08 schema/branch ownership, FOUNDATION-09 `OrganizationContext`, FOUNDATION-10 choke-point audit, FOUNDATION-11 minimal service-layer org guards.

---

## Executive answers (audit questions 1–10)

### Q1 — Which repositories/services still use ID-only `find()` / `load()` on tenant-owned rows?

**Proved pattern:** Across `system/modules/**/repositories/*.php` and `system/core/**/Repositories/*.php`, the dominant read pattern for tenant-owned entities is **`WHERE id = ?`** (sometimes plus `deleted_at IS NULL` or `FOR UPDATE`) **without** joining `branches.organization_id` or filtering by `OrganizationContext`.

**Representative evidence (non-exhaustive; pattern repeats module-wide):**

| Area | Repository | ID-only (or equivalent) methods |
|------|------------|----------------------------------|
| Sales | `InvoiceRepository` | `find`, `findForUpdate` — `WHERE i.id = ?` / `WHERE id = ?` (no org) |
| Sales | `PaymentRepository` | `find`, `findForUpdate` — `WHERE id = ?` |
| Sales | `InvoiceItemRepository` | loads by `invoice_id` (FK), not org |
| Sales | `RegisterSessionRepository` | `find`, `findForUpdate` — by `register_sessions.id` (`find` joins `branches` for display only, **no** `organization_id` predicate) |
| Sales | `CashMovementRepository` | session/branch-linked rows; ID paths by primary key (see file) |
| Clients | `ClientRepository` | `find`, `findForUpdate` — `WHERE id = ?` |
| Appointments | `AppointmentRepository` | `find` — `WHERE a.id = ?` |
| Appointments | `WaitlistRepository` | `find` — by waitlist PK (see file) |
| Appointments | `BlockedSlotRepository` | by PK / branch helpers |
| Staff | `StaffRepository`, schedules/breaks/exceptions | `find` by staff PK |
| Services/resources | `ServiceRepository`, `RoomRepository`, `EquipmentRepository`, `ServiceCategoryRepository` | `find` by PK |
| Inventory | `ProductRepository`, categories/brands/suppliers, movements, counts | `find` by PK |
| Documents | `DocumentRepository`, consent repos | PK / FK lookups without org |
| Marketing | `MarketingCampaignRepository`, `MarketingCampaignRunRepository`, related | `find` — `WHERE id = ?` |
| Payroll | `PayrollRunRepository`, `PayrollCompensationRuleRepository`, commission lines | `find` by PK |
| Intake | `IntakeFormTemplateRepository`, submissions, assignments, fields | `find` by PK |
| Memberships/packages | `MembershipDefinitionRepository`, `ClientMembershipRepository`, `MembershipSaleRepository`, `PackageRepository`, `ClientPackageRepository`, etc. | `find` / `findForUpdate` by PK |
| Gift cards | `GiftCardRepository`, `GiftCardTransactionRepository` | PK / card id patterns |
| Notifications | `NotificationRepository`, outbound message/attempt repos | PK-based |
| Public commerce | `PublicCommercePurchaseRepository` | Invoice correlation: **`findCorrelatedToInvoiceRow`** / branch + live-invoice join (FND-TNT-05); token hash + id-only **`update`** remain guest/caller-scoped |
| Online booking | `PublicBookingManageTokenRepository`, `PublicBookingAbuseGuardRepository` | token/id scoped, not org table |
| Core auth | `UserPasswordResetTokenRepository`, `PasswordResetRequestLogRepository` | auth/session support — **not** branch/org rows |
| Core permissions | `StaffGroupPermissionRepository` | RBAC pivot — **defer** per project rules |

**68** repository class files exist under `system/` (glob `**/*Repository.php`); the **overwhelming majority** follow the same ID-primary-key read model. **No** repository method in this tree currently accepts `organization_id` or applies an `EXISTS`/JOIN org predicate as of this audit.

---

### Q2 — Which are already protected by FOUNDATION-11 choke points, and which remain exposed via non-guarded paths?

**FOUNDATION-11 proved surface (service layer only):**  
`OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization` is wired into:

- `BranchDirectory::updateBranch`, `softDeleteBranch`; `createBranch` pins `organization_id` from `OrganizationContext` when resolved (`ORGANIZATION-SCOPED-CHOKE-POINTS-MINIMAL-ENFORCEMENT-FOUNDATION-11-OPS.md`).
- `InvoiceService`: `create`, `update`, `cancel`, `delete`, `recomputeInvoiceFinancials`, `redeemGiftCardPayment`.
- `PaymentService`: `create`, `refund`.
- `ClientService`: `create`, `update`, `delete`, `addClientNote`, `deleteClientNote`, `mergeClients`, `createCustomFieldDefinition`, `updateCustomFieldDefinition`.

**Still exposed (proved call paths):**

| Exposure class | Evidence |
|----------------|----------|
| **All `*Repository::find` / `findForUpdate`** | SQL remains ID-only; **no** org predicate at persistence layer. |
| **Staff invoice UI read paths** | `InvoiceController::show`, `edit` call `InvoiceRepository::find($id)` then `ensureBranchAccess` → **`BranchContext::assertBranchMatch` only** (```462:471:system/modules/sales/controllers/InvoiceController.php```). **No** `OrganizationScopedBranchAssert` on GET. Superadmin with null branch context: **no** branch assert → **cross-branch read** still possible at controller + repo layer. |
| **Payment form read path** | **Superseded in tree:** `PaymentController::create` / `store` / `refund` call **`ensureProtectedTenantScope()`** then **`ensureBranchAccessForInvoice`** after `InvoiceRepository::find` (sales tenant scope + branch match). Original audit line kept for historical ZIP deltas only. |
| **Client profile read** | `ClientController::show` uses `ClientRepository::find($id)` then `ensureBranchAccess` → branch only (same pattern as invoices). **Update (FND-TNT-06 wave):** `InvoiceController::show` / cashier / membership checkout client satellite reads use **`findLiveReadableForProfile`** with invoice/cashier branch envelope — not org-only **`find()`**. |
| **Client index list** | `ClientController::index` calls `ClientRepository::list($filters, …)` with **search only** — **no** `branch_id` in filters when not passed (```52:54:system/modules/clients/controllers/ClientController.php```) → **unscoped list** for operators whose context allows. |
| **Register / money-adjacent** | `RegisterSessionService` uses `BranchContext` on `openSession` and loads sessions by id in `closeSession` / movements — **not** in F-11 list; `RegisterSessionRepository::findForUpdate` is ID-only. |
| **Public commerce** | `PublicCommerceService` calls `InvoiceRepository::find`, `PackageRepository::find`, `MembershipDefinitionRepository::find` (e.g. ```97:97:system/modules/public-commerce/services/PublicCommerceService.php```). **Guest/token** flows; **must not** be “fixed” by naive staff-only org predicates without a parallel public contract. |
| **Fulfillment reconciler** | `PublicCommerceFulfillmentReconciler` uses `invoiceRepo->find`, `membershipSales->find`, `packages->find` (internal automation). |
| **Other services** | `AppointmentService`, `ProductService`, `StaffService`, `GiftCardService`, `DocumentService`, `IntakeFormService`, `MembershipSaleService`, etc., use repositories with **`BranchContext`** asserts on many writes — **not** F-11 **org** asserts and **not** repository org predicates. |

**Summary:** F-11 **narrows mutating choke points** for **invoices, payments, clients** (and branch admin) when **`OrganizationContext` resolves**. It does **not** retrofit **repository reads**, **controller GET** paths, **lists without branch filter**, **register**, **memberships/packages**, or **public** modules.

---

### Q3 — Which list/index/search builders rely only on branch filtering, nullable branch semantics, or no scope?

**Branch filter optional or “OR NULL” (cross-branch leakage when filter omitted):**

- `InvoiceRepository::list` / `count`: optional `branch_id`; predicate `(branch_id = ? OR branch_id IS NULL)` when set (```44:47:system/modules/sales/repositories/InvoiceRepository.php```).
- `AppointmentRepository::list`: same optional branch + `OR NULL` (```49:51:system/modules/appointments/repositories/AppointmentRepository.php```).
- `MarketingCampaignRepository::list` / `count`: optional `branch_id`; `(branch_id = ? OR branch_id IS NULL)` (```30:32:system/modules/marketing/repositories/MarketingCampaignRepository.php```).
- Many other `list` methods follow the same **optional `branch_id`** pattern (services, products, staff lists — verify per module as needed).

**No branch column in filter (global search risk):**

- `ClientRepository::list` / `count`: **search** only; `branch_id` only if present in `$filters` (```121:138:system/modules/clients/repositories/ClientRepository.php```). **Client index** often passes **no** branch filter (see Q2).
- `ClientRepository::findDuplicates` / `searchDuplicates`: **no** org predicate; can return clients **across branches/orgs** (```188:204:system/modules/clients/repositories/ClientRepository.php```).

**No tenant column at all on list:**

- Various global or cross-branch catalog patterns (e.g. `VatRateRepository` — global `branch_id IS NULL` rows mixed with branch rows; **settings** modules — out of scope for this wave’s implementation but high-risk if org-scoped blindly).

**None** of the audited list builders add **`organizations.id` / `branches.organization_id`** join filters tied to `OrganizationContext`.

---

### Q4 — Which repository patterns can be upgraded first with lowest blast radius?

**Criteria used:** (a) **Staff-only** controller/service entrypoints (no import from `PublicCommerce*` / public booking services); (b) rows carry **`branch_id`** (or unambiguous chain to branch); (c) **not** core RBAC/auth; (d) **not** invoice/payment **guest** paths.

**Lowest blast (recommended first technical family for a *repository* wave):**

1. **Marketing** — `MarketingCampaignRepository`, `MarketingCampaignRunRepository`, `MarketingCampaignRecipientRepository`: wired only from `MarketingCampaignController` / `MarketingCampaignService` (bootstrap grep shows **no** `PublicCommerce` / `OnlineBooking` usage). Lists already understand `branch_id`; ID-only `find` is the gap.
2. **Payroll** (staff module) — `PayrollRunRepository`, `PayrollCompensationRuleRepository`, `PayrollCommissionLineRepository`: staff controllers; money-sensitive but **not** the same paths as `PublicCommerceService`.

**Second tier (still staff-heavy; verify caller map before each change):**

- **Intake admin** templates/assignments (distinguish **admin** repos from **public submit** flows — do not scope public submission repos in the same wave without a separate design).
- **Documents** admin (`DocumentService` / `DocumentRepository`) — `ConsentService` participates in `AppointmentService`; appointment creation touches **public booking**; **defer** blanket repo changes until caller graph is split staff vs public.

**Already joined to `branches` but without org column filter:**

- `RegisterSessionRepository::find` — joins `branches` for labels; adding `organization_id` predicate would be a **small SQL delta** but **must** be paired with `RegisterSessionService` + **F-11-style org assert** or equivalent — still **money/register** adjacent; treat as **R1.5** after marketing/payroll pilot, not before proving register closeout paths.

---

### Q5 — Which patterns must be deferred (public, settings, storage, RBAC, high-risk money breadth)?

| Bucket | Why defer |
|--------|-----------|
| **`PublicCommerceService` / `PublicCommerceFulfillmentReconciler` / purchase repo** | Guest/token commerce; org resolution differs from staff session. |
| **`PublicBookingService` + `AvailabilityService` + `BlockedSlotRepository`** | Public booking uses availability; changing blocked-slot queries without a **public** contract breaks slots. |
| **`ClientRepository::lockActiveByEmailBranch` / phone locks** | Used for **anonymous public** client resolution; org rules must match booking/commerce semantics. |
| **`InvoiceRepository` / `PaymentRepository` when shared with public** | Any `find` change affects `PublicCommerceService` (proved). Needs **dual API** (staff-scoped vs internal token-scoped) or **caller-provided scope**, not a single global `find`. |
| **`invoice_number_sequences` / `allocateNextInvoiceNumber`** | Global sequence key today (`InvoiceRepository`); org-aware sequencing is a **schema/product** decision (F-07 checklist **G-SEQUENCES**). |
| **`VatRateRepository` / payment method global rows** | Global `branch_id IS NULL` catalog semantics; settings surface (per F-10). |
| **`StaffGroupPermissionRepository` / auth token repos** | RBAC and auth — explicit project **out of scope**. |
| **Memberships / packages repositories** | Project instruction to defer deep edits; high coupling to invoices and public commerce. |
| **Raw `grep`-wide `UPDATE … WHERE id = ?`** | Repository `update`/`softDelete` methods are ID-only; touching all at once = **maximum blast**. |

---

### Q6 — Which domain families to group for the first minimal repository wave?

**Group A (pilot):** **Marketing** — three repositories, one service, one controller; no public consumers in repo.  
**Group B (pilot 2):** **Payroll** — run + rule + commission line repos under staff module.  

**Do not** mix Group A with **sales** or **public** in the **first** repository wave — different failure modes and test burden.

---

### Q7 — Exact repositories/methods for the **next** implementation wave vs **stay out**

**Include (proposed R1 — subject to fresh FULL ZIP audit approval):**

| Repository | Methods to scope first | Predicate shape (implementation hint only) |
|------------|------------------------|--------------------------------------------|
| `MarketingCampaignRepository` | `find`, optionally `list`/`count` when org resolved | `JOIN branches b ON b.id = marketing_campaigns.branch_id` + `b.organization_id = ?` when `branch_id` not null; explicit policy for `branch_id IS NULL` rows |
| `MarketingCampaignRunRepository` | `find`, `findForUpdate` | Join campaign → branch → org, or denormalize `organization_id` in a **later** schema wave (not F-12) |
| `MarketingCampaignRecipientRepository` | ID update paths used by service | Trace from `MarketingCampaignService` |
| `PayrollRunRepository` | `find`, `update`, `delete` | Join `branch_id` on run (if column exists — confirm schema) or equivalent |
| `PayrollCompensationRuleRepository` | `find` | Same |
| `PayrollCommissionLineRepository` | hot paths by id | Same |

**Stay out of R1:**

- All `modules/public-commerce/**` repositories and any method invoked from `PublicCommerceService` / reconciler.
- `ClientRepository`, `InvoiceRepository`, `PaymentRepository`, `AppointmentRepository`, `WaitlistRepository`, `BlockedSlotRepository` **until** staff vs public caller split is documented and tested.
- `invoice_number_sequences`, `VatRateRepository` global rows, RBAC repos, auth repos.
- **Any** “change every `find` in the codebase” rollout — **not** justified by this audit.

---

### Q8 — Acceptance gates for the next repository implementation wave

1. **Caller closure:** For every changed repository method, a **written caller list** (file/class/method) with **staff vs public** classification; no public caller unless explicitly designed.  
2. **Org authority:** Scope parameter derives from **`OrganizationContext`** (or single-org fallback already in middleware), **not** from raw request org id.  
3. **Null-context policy:** Documented behavior when `OrganizationContext::getCurrentOrganizationId()` is null (match F-09/F-11: no-op vs fail-closed — **explicit per route**).  
4. **Regression:** Existing read-only verifiers still pass: `verify_organization_context_resolution_readonly.php`, `verify_organization_branch_ownership_readonly.php`, `verify_organization_scoped_choke_points_foundation_11_readonly.php`.  
5. **Proof artifact:** New or extended read-only verifier **or** migration note that lists **which** SQL predicates were added (file/method).  
6. **No drift:** Public booking/commerce/intake behavior unchanged (smoke or scripted checks as project defines).  

---

### Q9 — Highest regression risks if repository scoping is applied too broadly too early

- **Public checkout / finalize** breaks if `InvoiceRepository::find` suddenly requires org match that guest requests do not satisfy.  
- **Online booking** breaks if `BlockedSlotRepository` / availability SQL changes effective slots.  
- **Superadmin / HQ** workflows break if null-org behavior is inconsistent between **list** (unscoped) and **find** (newly strict).  
- **Duplicate client merge / search** breaks if `searchDuplicates` is org-filtered inconsistently with legacy global duplicate policy.  
- **Invoice numbering / sequences** collide or skip if org is bolted on without **G-SEQUENCES** design.  
- **Performance:** N+1 or heavy JOINs on hot `find` paths without index review.  

---

### Q10 — Phased order after the first repository-scoping wave succeeds

1. **R1 (done well):** Marketing + Payroll pilot repos (Q7).  
2. **R2:** Staff operational repos (**schedules, breaks, exceptions, staff_groups**) — still staff-only; watch `AvailabilityService` read paths.  
3. **R3:** Services/resources + inventory **staff** lists/finds — `ServiceRepository`, `ProductRepository`, etc.; ensure public catalog providers are not broken.  
4. **R4:** Appointments + waitlist + blocked slots — **only** after **public vs staff** query split is designed (may mirror “staff controller uses scoped repo, public uses narrow API”).  
5. **R5:** Clients + documents + intake — PII; requires duplicate/search policy per org.  
6. **R6:** Sales invoice/payment repos — **dual** staff/public find strategies + sequence (`G-SEQUENCES`).  
7. **R7:** Memberships + packages (explicit project approval).  
8. **R8:** Settings VAT/payment global catalog (settings overhaul wave).  
9. **R9:** RBAC/storage (explicit program waves).  

This order is **risk-ordered**, not “importance to product marketing.”

---

## Non-goals (this wave)

- No code changes, no repository refactors, no new middleware, no public route edits, no FOUNDATION-13 planning in implementation form.

---

## Companion artifact

- **`ORGANIZATION-SCOPED-REPOSITORY-COVERAGE-MATRIX-FOUNDATION-12.md`** — condensed F-11 vs repo exposure matrix.

---

## Checkpoint ZIP

Packaging for full ZIP audit: exclude `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip` per project rules.
