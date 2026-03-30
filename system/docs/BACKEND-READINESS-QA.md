# Backend Readiness — Manual QA Support

Minimal backend readiness and smoke-test support. No new features, no UI redesign.

---

## 1. Prerequisite data for operational testing

Before running manual QA, ensure the following exist. After migrations and seed, only **branch**, **user**, **service**, **staff**, and **client** may need to be created manually (or via existing UI).

| Prerequisite | Purpose | How to ensure |
|--------------|---------|----------------|
| **Migrations** | All tables present | Run `php system/scripts/migrate.php` (or project equivalent). |
| **Roles & permissions** | Auth and permission middleware | Run `php system/scripts/seed.php`. This seeds roles (owner, admin, reception), all permissions (including `reports.view`, `documents.view`, `documents.edit`), and assigns **all permissions to role `owner`**. |
| **Baseline settings** | App settings | Same seed script: `002_seed_baseline_settings.php` (currency, company_name, timezone). |
| **At least one branch** | Branch context, scoping, public booking | Insert into `branches`: `name`, `code`, `deleted_at` NULL. e.g. `INSERT INTO branches (name, code, deleted_at) VALUES ('Main', 'MAIN', NULL);` |
| **At least one user** | Login and permission checks | Insert into `users` (email, password hash, etc.). Assign to role via `user_roles` (e.g. role_id for `owner`). For branch-scoped tests, set `users.branch_id` to that branch; for unscoped (superadmin) leave `users.branch_id` NULL. |
| **At least one service** | Appointments, slots, public booking | Create via Services & Resources → Services, or insert into `services` (name, duration_mins, branch_id or NULL, etc.). |
| **At least one staff** | Appointments, availability, public booking | Create via Staff UI or insert into `staff` (name, branch_id or NULL, etc.). Staff must have working hours in `staff_schedules` for availability/slots to be non-empty. |
| **At least one client** | Appointments, invoices, documents/consents | Create via Clients UI or insert into `clients` (first_name, last_name, branch_id or NULL, etc.). |
| **Document definitions (optional)** | Consent flow | For staff sign flows and **public booking** consent gating at POST book: create at least one `document_definitions` row (branch_id, code, name, etc.). Link service to required consent via `service_required_consents` if testing consent gating. |

**Summary:** Run migrations → run seed → create one branch, one user (owner role), one service, one staff (with schedule), one client. Then you can run the flows below.

---

## 2. Routes / endpoints to test by flow

Use these for manual QA. All authenticated routes require login; public booking routes do not.

### Appointments

| Method | Route / path | Purpose |
|--------|----------------|--------|
| GET | `/appointments` | List appointments (filter by branch_id). |
| GET | `/appointments/calendar/day` | Day calendar view (branch_id, date). |
| GET | `/appointments/create` | Create form. |
| POST | `/appointments` or `/appointments/create` | Create appointment. |
| GET | `/appointments/{id}` | Show appointment. |
| GET | `/appointments/{id}/edit` | Edit form. |
| POST | `/appointments/{id}` | Update appointment. |
| POST | `/appointments/{id}/cancel` | Cancel appointment. |
| POST | `/appointments/{id}/reschedule` | Reschedule. |
| POST | `/appointments/{id}/status` | Update status. |
| POST | `/appointments/{id}/consume-package` | Consume package (needs packages.use). |
| GET | `/appointments/slots` | Get slots (branch_id, date, etc.). |
| GET | `/appointments/availability/staff/{id}` | Staff availability JSON (branch_id, date). |
| GET | `/appointments/waitlist` | Waitlist page. POST waitlist routes for create/update/link/convert. |
| POST | `/appointments/blocked-slots` | Create blocked slot. |

**Permissions:** `appointments.view`, `appointments.create`, `appointments.edit`, `appointments.delete`, `packages.use` (for consume).

---

### Sales / payments / refunds

| Method | Route / path | Purpose |
|--------|----------------|--------|
| GET | `/sales` | Sales hub. |
| GET | `/sales/invoices` | List invoices. |
| GET | `/sales/invoices/create` | Create invoice form. |
| POST | `/sales/invoices` | Create invoice. |
| GET | `/sales/invoices/{id}` | Show invoice. |
| GET | `/sales/invoices/{id}/edit` | Edit invoice. |
| POST | `/sales/invoices/{id}` | Update invoice. |
| POST | `/sales/invoices/{id}/cancel` | Cancel invoice. |
| GET | `/sales/invoices/{id}/payments/create` | Record payment form. |
| POST | `/sales/invoices/{id}/payments` | Record payment. |
| POST | `/sales/payments/{id}/refund` | Refund payment. |
| POST | `/sales/invoices/{id}/redeem-gift-card` | Redeem gift card on invoice. |
| GET | `/sales/register` | Register page. |
| POST | `/sales/register/open` | Open register session. |
| POST | `/sales/register/{id}/close` | Close session. |
| POST | `/sales/register/{id}/movements` | Cash movement. |

**Permissions:** `sales.view`, `sales.create`, `sales.edit`, `sales.delete`, `sales.pay`, `gift_cards.redeem`.

---

### Reports

All **GET**, return **JSON**. Query params: `date_from`, `date_to`, `branch_id` (all optional). Auth + `reports.view` required.

| Method | Route | Purpose |
|--------|--------|--------|
| GET | `/reports/revenue-summary` | Revenue summary. |
| GET | `/reports/payments-by-method` | Payments by method. |
| GET | `/reports/refunds-summary` | Refunds summary. |
| GET | `/reports/appointments-volume` | Appointments volume. |
| GET | `/reports/new-clients` | New clients. |
| GET | `/reports/gift-card-liability` | Gift card liability. |
| GET | `/reports/inventory-movements` | Inventory movements. |

---

### Staff availability

| Method | Route | Purpose |
|--------|--------|--------|
| GET | `/appointments/availability/staff/{id}` | JSON: staff availability for a date (query: branch_id, date). |

**Permission:** `appointments.view`.

---

### Documents / consents

| Method | Route | Purpose |
|--------|--------|--------|
| GET | `/documents/definitions` | List document definitions (branch-aware). |
| POST | `/documents/definitions` | Create definition. |
| GET | `/documents/clients/{id}/consents` | List consents for client. |
| POST | `/documents/clients/{id}/consents/sign` | Sign consent. |
| GET | `/documents/clients/{id}/consents/check` | Check consent status (e.g. for service). |

**Permissions:** `documents.view` (GET), `documents.edit` (POST create definition, sign consent).

---

### Public online booking (no auth)

| Method | Route | Purpose |
|--------|--------|--------|
| GET | `/api/public/booking/slots` | Query: `branch_id`, `service_id`, `date` (YYYY-MM-DD), optional `staff_id`. Returns slots. |
| POST | `/api/public/booking/book` | Body: branch_id, service_id, staff_id, start_time, first_name/last_name/email (+ optional phone, notes). **No `client_id`** (positive `client_id` → 422 + generic error; PB-HARDEN-NEXT). Creates appointment. Rate-limited. |
| GET | `/api/public/booking/consent-check` | Query: `branch_id` only. Read bucket + `requireBranchPublicBookability`; **410 Gone** + `{ "success": false, "error": "Public consent status lookup is disabled. Required consents are enforced when you submit a booking." }` when bookable — **not** ok/missing/expired; no `ConsentService` on this route (PB-HARDEN-08). |

---

## 3. Manual QA flow checklist

Use this as the main smoke-test checklist for “ready for manual QA”.

### Auth and permissions

- [ ] Login with a user that has role **owner** (all permissions). Can reach dashboard and all module areas (clients, staff, services, appointments, sales, inventory, reports, documents).
- [ ] Without `reports.view`: GET `/reports/revenue-summary` → 403. With `reports.view` (e.g. owner) → 200 and JSON.
- [ ] Without `documents.view`: GET `/documents/definitions` → 403. With `documents.view` → 200. Without `documents.edit`: POST create definition or sign consent → 403. With `documents.edit` → succeeds (or validation error).

### Branch and scoping

- [ ] **Branch-scoped user:** User with `users.branch_id` set. Lists (clients, appointments, invoices, products) show only that branch (or global). Create client/invoice/appointment without sending branch_id → branch set from context; save succeeds.
- [ ] **Cross-branch access:** As branch 1 user, open direct URL to show/edit of a client, appointment, invoice, product, or supplier that belongs to branch 2 → **403** (not 404).
- [ ] **Unscoped user (superadmin):** User with `users.branch_id` NULL. Can create/edit records with any branch or null; no branch restriction.

### Appointments

- [ ] List appointments with optional branch_id/date; create appointment (client, service, staff, branch, date/time); show and edit appointment; update; cancel; reschedule; update status.
- [ ] GET `/appointments/slots` with branch_id, date, (optional) staff_id → returns slots (or empty if no staff schedule).
- [ ] GET `/appointments/availability/staff/{id}` with branch_id, date → returns availability JSON.
- [ ] Waitlist: list, create, update status, link to appointment, convert to appointment (if implemented).
- [ ] Blocked slots: create and delete (if UI/routes available).

### Sales / payments / refunds

- [ ] List invoices; create draft invoice (add line items); update; show. Record payment on invoice; confirm balance/paid amount.
- [ ] Refund a payment; confirm invoice/refund state and audit if applicable.
- [ ] Cancel invoice (draft/open, no posted payments).
- [ ] Redeem gift card on invoice (if gift card module and balance allow).
- [ ] Register: open session, add cash movement, close session (branch-scoped user only for own branch).

### Reports

- [ ] Call each of the seven report endpoints with auth and `reports.view`. Use optional `date_from`, `date_to`, `branch_id`. Expect 200 and valid JSON structure (success, data or empty).

### Staff availability

- [ ] GET `/appointments/availability/staff/{id}` with branch_id and date; confirm response matches expectations (working hours, breaks, existing appointments).

### Documents / consents

- [ ] List definitions; create definition (branch set from context when user is branch-scoped).
- [ ] For a client, list consents; sign a consent; check consent status for a service (GET check). If service has required consents, creating an appointment without signed consent should fail with clear message.

### Public online booking

- [ ] GET `/api/public/booking/slots` with branch_id, service_id, date → 200 and slots (or empty).
- [ ] POST `/api/public/booking/book` with valid branch_id, service_id, staff_id, start_time, name/email → 201 and appointment_id; with positive `client_id` → 422 + `ERROR_PUBLIC_BOOKING_GENERIC` (PB-HARDEN-NEXT).
- [ ] GET `/api/public/booking/consent-check?branch_id=…` → **410** and the fixed **PB-HARDEN-08** error JSON when branch is publicly bookable; **422** when gate fails. Consent ok/missing/expired is **not** returned here — verify gating via POST book + documents section.

### Inventory (if in scope)

- [ ] List products/suppliers; create product (branch or global); show, edit, update, delete; confirm branch guard (other branch → 403).
- [ ] Create stock movement and inventory count; confirm branch from context and product branch match.

### Regression

- [ ] No regressions in existing behavior: payments, refunds, gift card redemption, reports JSON, staff availability, consents gating, public booking.

---

## 4. Seed/demo helpers

- **Roles and permissions:** `php system/scripts/seed.php` seeds roles, permissions (including `reports.view`, `documents.view`, `documents.edit`), and assigns all permissions to **owner**. No additional demo user or branch is created; create one branch and one user (assigned to owner) manually or via UI for QA.
- **Baseline settings:** Same seed adds default settings (currency, company name, timezone).
- **Demo data (branch, user, service, staff, client):** Not automated. Documented above as prerequisites; create via UI or direct SQL to avoid password-hashing and business-rule coupling.

---

## 5. Intentionally postponed

- Automated E2E or API test suite: not in scope; checklist is for manual QA.
- Extra seed data (branches, users, services, staff, clients): documented as prerequisites; not added to avoid conflicts and to keep seed minimal.
- UI changes or new feature work: out of scope for this pass.

---

## 6. Ready for manual QA — status

| Item | Status |
|------|--------|
| Prerequisite data documented | Yes (§1). |
| Routes/endpoints listed by flow | Yes (§2). |
| Single smoke-test checklist | Yes (§3). |
| Permissions for reports and documents seeded | Yes (`reports.view`, `documents.view`, `documents.edit` in `001_seed_roles_permissions.php`; owner gets all). |
| Minimal seed changes | Yes: three permission rows only; no new tables or business logic. |
| Backend status summary | See `system/docs/archive/system-root-summaries/BACKEND-STATUS-SUMMARY.md`. |

**Conclusion:** Backend is **ready for manual QA** once migrations and seed are run and at least one branch, one user (owner), one service, one staff (with schedule), and one client exist. Use §2 for which routes to hit and §3 as the main checklist.
