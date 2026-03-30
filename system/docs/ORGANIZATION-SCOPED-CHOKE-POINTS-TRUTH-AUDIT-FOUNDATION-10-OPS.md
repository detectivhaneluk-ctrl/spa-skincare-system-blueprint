# Organization-scoped choke points — truth audit (FOUNDATION-10)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-10 — ORGANIZATION-SCOPED-CHOKE-POINTS-TRUTH-AUDIT  
**Mode:** Read-only truth audit only (no runtime changes, no schema changes, no repository refactors).  
**Source of truth:** Accepted FOUNDATION-06 (`ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`), FOUNDATION-07 (boundary + checklist docs), FOUNDATION-08 (schema + `BranchDirectory` org pin on create), FOUNDATION-09 (`OrganizationContext` + middleware after `BranchContextMiddleware`).  
**ZIP:** Last full-project ZIP is treated as runtime-consistent with FOUNDATION-09 only; this working tree is checkpointed separately at end of this wave.

---

## 0) Proved baseline (post F-08 / F-09)

| Fact | Evidence |
|------|----------|
| `organizations` + `branches.organization_id` exist | `ORGANIZATION-SCHEMA-BRANCH-OWNERSHIP-FOUNDATION-08-OPS.md`, migration `086_organizations_and_branch_ownership_foundation.sql` |
| New branches get `organization_id` = `MIN(organizations.id)` among active orgs | `BranchDirectory::createBranch()` → `defaultOrganizationIdForNewBranch()` |
| HTTP request has `OrganizationContext` resolved **after** branch context | `Dispatcher` middleware order; `ORGANIZATION-CONTEXT-RESOLUTION-FOUNDATION-09-OPS.md` |
| **No** business module reads `OrganizationContext` for writes/lists today | `grep` over `system/**/*.php`: `OrganizationContext` appears only in `bootstrap.php`, `OrganizationContext*.php`, `OrganizationContextMiddleware.php`, `Dispatcher.php`, `BranchContext.php` docblock, and `verify_organization_context_resolution_readonly.php` — **no** controllers/services/repositories. |
| Branch enforcement on many mutating paths is **request branch** only | `BranchContext::assertBranchMatch` / `enforceBranchOnCreate` — **no** `organization_id` predicate on SQL or context checks. |
| When `BranchContext::getCurrentBranchId()` is **null**, branch asserts are **no-ops** | `BranchContext::assertBranchMatch` returns early if context null; `enforceBranchOnCreate` returns data unchanged — allows HQ/global operators to pass IDs across branches if permissions allow. |

**Definition used in this audit:** An **organization predicate** means code or SQL that constrains the row to the **resolved** `OrganizationContext::getCurrentOrganizationId()` (e.g. join `branches.organization_id`, or explicit `organizations` filter). **Branch-only** checks are **not** org predicates for multi-org safety.

---

## 1) Answers to required audit questions (code-backed)

### Q1 — Which mutating choke points can create/update/delete tenant-owned data without an explicit organization predicate today?

**All** authenticated staff mutating routes and their downstream services/repositories operate **without** `OrganizationContext` enforcement. Representative route registrars (POST = mutating unless noted):

| Domain | Registrar / module routes | Typical service/controller choke |
|--------|----------------------------|-----------------------------------|
| Branches | `system/routes/web/register_branches.php` | `BranchAdminController` → `BranchDirectory` (id-only admin loads; **no** org check on update/delete) |
| Clients | `register_clients.php` | `ClientService`, `ClientRegistrationService`, `ClientIssueFlagService` — branch helpers, **no** org |
| Documents | `register_documents.php` | `DocumentService`, `ConsentService` — branch asserts in places; **no** org |
| Settings | `register_settings.php` | `SettingsController::store`, `PaymentMethodsController`, `VatRatesController` — branch/global semantics; **no** org |
| Staff | `register_staff.php` | `StaffService`, `StaffGroupService`, schedules — branch; **no** org |
| Services/resources | `register_services_resources.php` | `ServiceService`, `RoomService`, `EquipmentService`, categories — branch; **no** org |
| Appointments | `register_appointments_calendar.php` | `AppointmentService`, `WaitlistService`, `AppointmentSeriesService` — branch; **no** org |
| Inventory | `register_inventory.php` | `ProductService`, suppliers, movements, etc. — branch; **no** org |
| Sales | `register_sales_public_commerce_staff.php` | `InvoiceService`, `PaymentService`, `RegisterSessionService`, staff `PublicCommerceStaffController` — branch on invoice/payment paths; **no** org |
| Marketing | `register_marketing.php` | `MarketingCampaignService` — branch; **no** org |
| Payroll | `register_payroll.php` | `PayrollService`, `PayrollRuleController` — branch; **no** org |
| Notifications | `register_notifications.php` | `NotificationController` — user + branch visibility; **no** org |
| Gift cards | `modules/gift-cards/routes/web.php` | `GiftCardService` — branch; **no** org |
| Intake | `modules/intake/routes/web.php` | `IntakeFormService` — branch; **no** org (plus **public** `POST /public/intake/submit` — no auth) |
| Memberships | `modules/memberships/routes/web.php` | **Out of scope for deep change this wave** per project instructions; still **no** org predicate in code |
| Packages | `modules/packages/routes/web.php` | **Out of scope for deep change**; still **no** org predicate |

**Public / guest mutating** (no `AuthMiddleware`; org context often **unresolved** when multi-org):

| Route | Registrar |
|-------|-----------|
| `POST /api/public/booking/book`, manage cancel/reschedule, etc. | `register_core_dashboard_auth_public.php` |
| `POST /api/public/commerce/purchase`, `.../finalize` | same |

These finalize tenant-owned rows using **branch/token** flows — **no** `OrganizationContext` write guard.

### Q2 — Safest first enforcement targets (highest risk reduction, lowest blast radius)?

Ordered **minimal-first** for the **next** implementation wave (not done in F-10):

1. **`BranchDirectory` + `BranchAdminController`** — Small surface; **structural** control of which org owns a branch; `createBranch` already sets `organization_id` but **update/softDelete/admin read** do not compare to `OrganizationContext`. Prevents cross-org branch administration when multi-org is enabled.
2. **Money paths already centralized** — `InvoiceService` / `PaymentService` (and staff register session if a single service owns open/close/move): add org assertion **via loaded branch row** (`OrganizationContext::assertBranchBelongsToCurrentOrganization`) after id-load — **no** broad repository rewrite if applied at service layer only.
3. **PII core** — `ClientService` (and tightly coupled client subflows): same pattern: after load client row, assert owning branch’s `organization_id` matches context (join or cached branch row).

**Why not “everything” first:** Repository-wide `find($id)` changes touch F-06’s ID-only exposure map across modules; choke-point service asserts reuse existing branch rows and F-09’s helper.

### Q3 — Which choke points already have branch checks that can later be upgraded to org+branch?

Non-exhaustive list (grep: `assertBranchMatch` / `enforceBranchOnCreate`):

- Sales: `InvoiceService`, `PaymentService`, `RegisterSessionService` (partial — verify per method when implementing).
- Inventory: `ProductService`, categories/brands/suppliers, `StockMovementService`.
- Services/resources: `ServiceService`, `RoomService`, `EquipmentService`, `ServiceCategoryService`.
- Appointments: `AppointmentService`, `WaitlistService`, `AppointmentSeriesService`; `AppointmentController` uses `assertBranchMatch` in places.
- Clients: `ClientService`, `ClientRegistrationService`, `ClientIssueFlagService`; `ClientController` assert on some paths.
- Staff: `StaffService`, `StaffGroupService`, `StaffGroupPermissionService`, schedules/breaks.
- Documents/consents: `DocumentService` patterns (per F-06), `ConsentService`.
- Marketing: `MarketingCampaignService`, controller.
- Payroll: `PayrollService`, `PayrollRuleController`, `PayrollRunController`.
- Gift cards: `GiftCardService`.
- Intake admin: `IntakeFormService`.
- Public commerce staff: `PublicCommerceService` (invoice branch assert).
- Memberships/packages: multiple services (**deferred** modules for edits).

**Upgrade pattern:** After loading entity with `branch_id`, load `branches.organization_id` (or join) and call `OrganizationContext::assertBranchBelongsToCurrentOrganization`. When `OrganizationContext` is null (ambiguous multi-org, no branch), **fail closed** for mutating routes in a later wave (policy decision — not implemented here).

### Q4 — Which paths rely only on ID-based lookup + caller-side checks?

Per F-06 and spot checks still true:

- Many `*Repository::find($id)` methods — **primary key only** (e.g. `ProductRepository::find` cited in F-06).
- `VatRateService::getById` / repository `find` — **by id any `branch_id`**; controller is expected to filter for “global admin” only (`VatRateService` docblock).
- `NotificationController::markRead` — `NotificationService::find($id)` then **visibility** check in controller (user id + branch id), **not** org.
- `PaymentMethodsController` — `getById($id)` for edit/update; **global** `branch_id NULL` catalog; no `BranchContext` assert (intentionally global today).
- `BranchAdminController` — `getBranchByIdForAdmin($id)` — **id only**, no org.

### Q5 — Public or semi-public write/finalize endpoints — defer early touch?

**Defer** until admin/staff choke points and org resolution policy for unauthenticated traffic are settled:

- `POST /api/public/booking/book`, `.../manage/cancel`, `.../manage/reschedule`
- `POST /api/public/commerce/purchase`, `POST /api/public/commerce/purchase/finalize`
- `POST /public/intake/submit` (no auth middleware)

These combine **token/session**, **branch id**, and **settings** — high blast radius; F-07 §4 calls for explicit public-flow verification **post-implementation**.

### Q6 — Admin/internal write surfaces to prioritize before public flows?

1. Branch catalog admin (`/branches/*`).  
2. Staff-authenticated sales/invoicing/payments/register.  
3. Clients + appointments + inventory + services-resources (operational core).  
4. Settings domains (`SettingsController`, payment methods, VAT rates) — **after** G-SETTINGS style rules from F-07 (mixed global/branch).  
5. Marketing dispatch / payroll settle — high business impact; still internal-only.

### Q7 — Minimal enforcement perimeter (next implementation wave)?

**In scope for a boring next wave (suggested):**

- `system/core/Branch/BranchDirectory.php` — admin mutations + optional `getBranchByIdForAdmin` guard when org context resolved.
- `system/modules/branches/controllers/BranchAdminController.php` — only if directory does not cover all paths.
- `system/modules/sales/services/InvoiceService.php`  
- `system/modules/sales/services/PaymentService.php`  
- `system/modules/clients/services/ClientService.php`  

**Explicitly out of that minimal perimeter (later):** public booking/commerce/intake, full repository refactors, RBAC rewrite, storage paths, packages/subscriptions modules, UI.

### Q8 — Future implementation order (after F-10)

1. **Mutating choke points** — service-layer `OrganizationContext` + branch `organization_id` assert (or fail-closed when context unresolved on mutating staff routes).  
2. **Scoped repository patterns** — introduce `findForOrg` / list predicates **only** where choke-point duplication would explode (incremental).  
3. **Wider domain rollout** — inventory, appointments, documents, payroll, marketing, settings merge rules, sequences (F-07 gates G-QUERY-SCOPE, G-SETTINGS, G-SEQUENCES).

### Q9 — What stays out of scope until choke-point enforcement succeeds?

- Full **module-by-module** repository rewrites in one wave.  
- **RBAC** org-keyed assignments (F-07 G-RBAC).  
- **Storage** org prefixes (F-07 G-STORAGE).  
- **Packages/subscriptions** billing matrices.  
- **UI** changes.  
- **Middleware rewrite** beyond adding **narrow** fail-closed rules (if any) agreed in a spec wave.  
- **Public** finalize/booking/commerce (until staff path proven).

### Q10 — Objective acceptance gates for the next implementation wave

| Gate | Pass criteria |
|------|----------------|
| **G-CHOKE-ORG-1** | For the **minimal perimeter** services/controllers, every **mutating** path that loads a row by id either (a) asserts `OrganizationContext::assertBranchBelongsToCurrentOrganization` using the **branch row’s** `organization_id`, or (b) documents an explicit fail-closed branch when org context is unresolved **and** route is mutating. |
| **G-CHOKE-ORG-2** | **Branch admin** cannot update/soft-delete a branch whose `organization_id` ≠ resolved org when context is non-null; behavior when context null is **explicitly defined** (deny vs superadmin — single documented policy). |
| **G-CHOKE-ORG-3** | No new dependency from **deferred** modules (packages/subscriptions/public flows) required to pass G-CHOKE-ORG-1–2. |
| **G-CHOKE-ORG-4** | Existing read-only verifiers still pass: `verify_organization_branch_ownership_readonly.php`, `verify_organization_context_resolution_readonly.php`. |
| **G-REGRESSION** | Smoke paths for invoice create/pay, client update, branch admin — unchanged for **single-org** deployment (behavioral parity). |

---

## 2) Risk tiering summary

| Tier | Meaning | Examples |
|------|---------|----------|
| **H1 — Highest** | Money movement + cross-tenant ID exposure | Invoice/payment/refund/register; public commerce **finalize** |
| **H2** | PII + clinical/compliance | Clients, documents, consents, intake |
| **H3** | Operational integrity | Appointments, inventory movements, staff assignments |
| **H4** | Catalog / settings | Global payment methods, VAT global rows, `settings` keys |
| **S — Safest first (structural, small)** | Few files, blocks org drift | `BranchDirectory` admin CRUD |

---

## 3) ID-only lookup exposure map (condensed)

- **Repositories:** Primary-key `find` without org join — pervasive; see F-06 §3–4.  
- **Controllers** that call `getById` / `find` then mutate: **safe only if** downstream service asserts branch **and** (future) org via branch ownership.  
- **Global catalog** endpoints (`PaymentMethodsController` ADMIN_BRANCH_ID `null`, `VatRatesController` global list) — **by design** not branch-scoped today; org model may later require org-scoped catalog rows — **not** a one-line fix.

---

## 4) Recommended first implementation perimeter (exact)

**Files (minimal):**

1. `system/core/Branch/BranchDirectory.php`  
2. `system/modules/branches/controllers/BranchAdminController.php` (wiring only if needed)  
3. `system/modules/sales/services/InvoiceService.php`  
4. `system/modules/sales/services/PaymentService.php`  
5. `system/modules/clients/services/ClientService.php`  

**Companion (inject / use):** `Core\Organization\OrganizationContext` + small helper to load `organization_id` for a `branch_id` (avoid N+1 if batching later).

---

## 5) Next-wave scope boundary (explicit)

**In:** Service-layer assertions on the five targets above; optional dispatcher-level **mutating-route** org-resolution guard **only if** spec’d as a single if-statement policy (no broad middleware rewrite).  

**Out:** Repository-wide scoping, public routes, packages/subscriptions deep changes, storage, RBAC, UI, settings overhaul.

---

## 6) Non-goals (this audit wave)

- Implement FOUNDATION-11 or any enforcement.  
- Change runtime code, schema, or ZIP contents beyond producing the checkpoint artifact.  
- Re-audit every repository method line-by-line (F-06 remains the deep table/ID reference).

---

## 7) Related artifacts

- F-06: `ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06.md`  
- F-07: `ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md`, `ORGANIZATION-BOUNDARY-ENFORCEMENT-CHECKLIST-FOUNDATION-07.md`  
- F-08: `ORGANIZATION-SCHEMA-BRANCH-OWNERSHIP-FOUNDATION-08-OPS.md`  
- F-09: `ORGANIZATION-CONTEXT-RESOLUTION-FOUNDATION-09-OPS.md`  

---

*End of FOUNDATION-10 read-only audit.*
