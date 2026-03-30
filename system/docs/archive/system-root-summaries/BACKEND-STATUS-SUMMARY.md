# Backend Status After Final Hardening Pass

Concise status of the backend after the focused final hardening pass (no UI redesign, no new feature modules).

---

## Modules and routes covered

| Module | Branch enforcement | Permission / notes |
|--------|--------------------|--------------------|
| **Inventory** | StockMovementService, InventoryCountService: branch forced from context + `assertBranchMatch` on create. ProductController, SupplierController: `ensureBranchAccess` on show, edit, update, destroy. | Existing auth + permissions unchanged. |
| **Clients** | ClientService: custom field definitions create (enforceBranchOnCreate), update (assertBranchMatch). ClientController: ensureBranchAccess on show, edit, update, destroy, registrationsShow. | clients.view, clients.edit, etc. |
| **Appointments** | AppointmentController: ensureBranchAccess on show, edit, update, consumePackage. Service-layer asserts unchanged. | appointments.view, appointments.edit, etc. |
| **Sales** | InvoiceController: ensureBranchAccess on show, edit, update, cancel, redeemGiftCard. InvoiceService already had assert on cancel/update/delete. | sales/invoices routes unchanged. |
| **Documents** | ConsentService/definitions already branch-aware. | **PermissionMiddleware** added: `documents.view` (GET list definitions, list client consents, check consents), `documents.edit` (POST create definition, sign consent). Codes must exist in DB and be assigned to roles — no seed in this pass. |
| **Reports** | ReportService branch filter; no controller single-record access. | reports.view. Unchanged in this pass. |
| **Staff availability** | Availability/staff endpoints unchanged. | appointments.view. Unchanged in this pass. |
| **Public booking** | No auth; branch from request. Unchanged. | No permission middleware (public). |

---

## Hardening rules added (this pass)

1. **Stock movement / inventory count:** Branch forced from `BranchContext` when user is branch-scoped; `assertBranchMatch($branchId)` before applying; product branch must still match movement/count branch.
2. **Client custom field definitions:** Create uses `enforceBranchOnCreate`; update uses `assertBranchMatch($existing['branch_id'])` after find.
3. **Controller single-record guards:** After `find($id)` and 404 check, `ensureBranchAccess($entity)` → `BranchContext::assertBranchMatch($entity['branch_id'])`; on `DomainException` → 403. Applied to clients, appointments, invoices, products, suppliers on show/edit/update/destroy (and cancel, redeemGiftCard, consumePackage where applicable).
4. **Document routes:** All five document/consent routes now use `PermissionMiddleware::for('documents.view')` or `for('documents.edit')` in addition to AuthMiddleware.

---

## Readiness for manual QA

See **`system/docs/BACKEND-READINESS-QA.md`** for: prerequisite data (branch, user, service, staff, client, permissions), routes/endpoints to test per flow (appointments, sales, reports, staff availability, documents, public booking), and a single manual QA flow checklist. The permissions `reports.view`, `documents.view`, and `documents.edit` are now seeded in `system/data/seeders/001_seed_roles_permissions.php` and assigned to role **owner** with all other permissions.

---

## Intentionally postponed

- **Permission seed for documents:** `documents.view` and `documents.edit` are now seeded; owner has them. Other roles must be assigned these (and `reports.view`) via role_permissions if needed.
- **Delete for client custom field definitions:** No delete flow implemented; nothing to harden.
- **Other single-record controllers (Staff, Services-Resources, etc.):** Same `ensureBranchAccess` pattern can be applied in a follow-up where entities have `branch_id`.
- **List/index default branch:** Controllers still use existing filters; defaulting list to current branch when query has no branch_id is not in scope.

---

## Manual smoke test checklist

(See **`system/docs/BACKEND-READINESS-QA.md`** for the full QA flow checklist and routes-by-flow.)

- [ ] **Branch-scoped user:** Log in as user with `branch_id` set. Open clients, appointments, invoices, products, suppliers lists; create/edit records; confirm only own branch (or global) visible and writable.
- [ ] **Cross-branch direct access:** As branch 1 user, open URL for show/edit of a client, appointment, invoice, product, or supplier that belongs to branch 2 (e.g. `/clients/123`, `/sales/invoices/456`). Expect **403** (not 404).
- [ ] **Stock movement / inventory count:** As branch user, create stock movement or inventory count; confirm branch is set from context and product branch matches; attempt with product from another branch should fail at service layer.
- [ ] **Client custom fields:** As branch user, create/update custom field definition; confirm branch enforced; attempt update of another branch’s definition (if ID known) should fail (403 or domain exception).
- [ ] **Documents:** After seed, owner has `documents.view` and `documents.edit`. GET definitions and client consents; POST create definition and sign consent. With a role that has only documents.view, POST should return 403.
- [ ] **Sales, reports, availability, public booking:** No regressions; existing flows (payments, refunds, gift card, reports JSON, staff availability, public slots/book) work as before.

---

## Existing behavior preserved

- Sales: payments, refunds, gift card redemption, invoice cancel/update (service-layer branch assert already present).
- Reports: branch and date filters; reports.view permission.
- Staff availability: GET availability/staff/{id}; branch filter in service.
- Consents: required-consent check before appointment create; ConsentService branch-aware.
- Public booking: slots, book, `GET /api/public/booking/consent-check` (branch gate + **410** — no public client consent probe; PB-HARDEN-08); branch from request; no auth. **POST book:** no anonymous `client_id`; public-safe errors for consent/sensitive failures (PB-HARDEN-NEXT); POST book adds non-IP `book_contact` + `book_slot` abuse buckets (PB-HARDEN-ABUSE-01).
