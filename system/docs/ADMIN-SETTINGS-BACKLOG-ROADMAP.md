# Admin / Settings Backlog — Implementation Roadmap (Booker-style)

> **HISTORICAL REFERENCE ONLY** (settings map + closure record) — Active backbone execution: `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md`. Strict status: `system/docs/TASK-STATE-MATRIX.md`. Further settings **expansion** is **DEFERRED** — `system/docs/DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`.

Structured roadmap for **admin/settings closure and module notes that touch settings**. Product/platform queues in `BOOKER-PARITY-MASTER-ROADMAP.md` are **deferred** during backbone closure; this file remains a **locked map** reference, not the active spine.

**Booker settings HTML mapping: CLOSED / COMPLETE.** The classification below is the locked source-of-truth. No further search for or addition of settings pages from the Booker tree. Do not expand the map.

---

## Final settings / admin map (locked)

### Pure settings pages (14)

| # | Booker page | Our settings group / page |
|---|-------------|---------------------------|
| 1 | Informations Etablissement | Establishment |
| 2 | Politique d'annulation | Cancellation policy |
| 3 | Paramètres des Rendez-vous | Appointments |
| 4 | Paramètres de paiement | Payments |
| 5 | Modes de paiement personnalisés | Custom payment methods (admin CRUD for `payment_methods`) |
| 6 | Types de TVA | VAT types (admin CRUD for `vat_rates`) |
| 7 | Répartition des TVA | VAT distribution (report) |
| 8 | Notifications internes | Internal notifications (settings/config for notification behaviour) |
| 9 | Matériel Informatique | IT equipment (settings/config) |
| 10 | Sécurité | Security |
| 11 | Paramètres Marketing | Marketing |
| 12 | Paramètres de liste d'attente | Waitlist |
| 13 | Réservation en Ligne | Online booking |
| 14 | Paramètres des memberships | Membership settings |

### Module / master-data pages (not part of settings architecture)

These are **not** pure settings pages; they stay outside the settings key/value architecture:

| # | Booker page | Our equivalent |
|---|-------------|-----------------|
| 1 | Espaces | Spaces (master data) |
| 2 | Matériel | Equipment / resources (master data) |
| 3 | Employés | Staff / employees (master data) |
| 4 | Connexions | Logins / connections (admin) |
| 5 | Prestation | Services (master data) |
| 6 | Forfaits | Packages (master data) |
| 7 | Séries | Series (recurring appointments module) |
| 8 | Memberships | Membership definitions (master data) |
| 9 | Nouveau membership | Membership create wizard (module flow) |
| 10 | Stokage documents | Document types / templates (module) |
| 11 | Nouveau type de document | Document type create/edit (module) |

**Classification rules (locked):**
- **Paramètres des memberships** = settings architecture page (config keys).
- **Nouveau membership** = module create wizard, NOT a settings page.
- **Stokage documents** = document types/templates module, NOT a pure settings page.
- **Nouveau type de document** = module create/edit form, NOT a settings page.

---

## Pure settings phase — CLOSED

**Status:** All 14 locked pure settings pages are implemented. The pure settings phase is **CLOSED**. No further scope added to the settings map.

### Final pure settings status table

| # | Booker page | Our implementation | Status |
|---|-------------|--------------------|--------|
| 1 | Informations Etablissement | SettingsService get/set Establishment; settings page section. | Implemented |
| 2 | Politique d'annulation | get/set Cancellation; settings section; AppointmentService cancel uses it. | Implemented |
| 3 | Paramètres des Rendez-vous | get/set Appointments; settings section. | Implemented |
| 4 | Paramètres de paiement | get/set Payments; settings section. | Implemented |
| 5 | Modes de paiement personnalisés | CRUD /settings/payment-methods; payment_methods table; PaymentService uses. | Implemented |
| 6 | Types de TVA | CRUD /settings/vat-rates; vat_rates table; services/invoices use. | Implemented |
| 7 | Répartition des TVA | GET /reports/vat-distribution; ReportController vatDistribution(). | Implemented |
| 8 | Notifications internes | get/set Notification settings (which events create notifications); notifications table; list/mark-read /notifications; appointment cancel, refund, waitlist convert create notifications when enabled. | Implemented |
| 9 | Matériel Informatique | get/set Hardware (use_cash_register, use_receipt_printer); settings section; PaymentService uses use_cash_register. | Implemented |
| 10 | Sécurité | get/set Security (password_expiration, inactivity_timeout_minutes); settings section. | Implemented |
| 11 | Paramètres Marketing | get/set Marketing; settings section. | Implemented |
| 12 | Paramètres de liste d'attente | get/set Waitlist; settings section. | Implemented |
| 13 | Réservation en Ligne | get/set Online booking; settings section. | Implemented |
| 14 | Paramètres des memberships | get/set Membership settings (terms_text, renewal_reminder_days, grace_period_days); settings section. | Implemented (operationalized baseline) |

### Storage-only foundations (not fully operationalized yet)

- **Hardware receipt-printer flag:** `hardware.use_receipt_printer` is stored/editable; **no print driver** in-repo — value is snapshotted on `payment_recorded` / `payment_refunded` / `invoice_gift_card_redeemed` audits (`hardware_use_receipt_printer`).
- **Marketing settings depth:** keys are stored/editable; client create/edit forms resolve **branch-effective** marketing defaults/labels (`ClientController` + `BranchContext` / client `branch_id`). **`marketing.default_opt_in`** is applied when **public booking** creates a client (`PublicBookingService::resolveClient`) and when **registration convert** creates a new client (`ClientRegistrationService::convert`). No campaign/automation engine is wired.

### Remaining gaps before closure

None. All 14 pure settings pages have backend + settings UI (or report link) in place.

### Next depth (after settings closure) — module notes only

**Repo truth:** Staff groups (**permissions merge + service→group enforcement + JSON admin**), memberships (**billing/settlement/sales/lifecycle baseline**), series (**internal operational baseline**: materialize + cancel paths), documents (**metadata + internal download**; not public file URLs) are **shipped baselines**. Remaining work = **depth only** (dunning/external capture, series edit/split/recurrence, intake/workflow, optional denser staff-group permissions assignment).

**Cross-cutting Booker parity** (dashboard, outbound channels, marketing engine, public commerce, payroll) is owned by **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** (and **§5.D** hold items), not this file.

Module-only follow-ons (when scoped):

1. **Memberships** — remaining depth: dunning/comms, external capture, staff invoice/sale path polish (**not** greenfield engine).
2. **Series** — remaining depth: edit/split, richer recurrence (**operational baseline shipped**).
3. **Documents** — **next:** workflow / forms / integration depth (**foundation shipped**).
4. **Staff groups** — optional denser assignment surface only; **RBAC merge + booking enforcement already shipped**.

Espaces, Matériel, Employés CRUD, Prestation, Forfaits, Connexions, document types are separate scoping.

---

## Current state (historical snapshot + pointer)

This section described the **pre-backlog** baseline. For **current** repo truth, use § **Backlog areas — status and dependencies** and the phased tables below (many rows are historical “plan”; shipped baselines are called out in the status column and in § **Next depth (after settings closure)**).

- **Settings / appointments / sales / reports / public booking / packages / gift cards:** Evolved substantially since the original snapshot; pure settings phase is **closed** (see § Pure settings phase — CLOSED).
- **Staff:** CRUD; schedules/breaks; **staff_groups + staff_group_members** foundation exists (internal CRUD + attach/detach); salary/hour/payroll fields remain out of scope here.
- **Documents:** Definitions, consents, service-required consents; **file storage foundation** exists (`documents` + `document_links`, internal upload/list/show/relink/detach/archive/delete metadata routes). **Deployment:** direct HTTP access to `system/storage/` must be denied at the web server (see isolation hardening); no public file download route.

---

## Backlog areas — status and dependencies

**Pure settings (14 pages):** All implemented. See § Pure settings phase — CLOSED above.

| Area | Status | Notes / dependencies |
|------|--------|----------------------|
| **Establishment / general settings** | **Implemented** | SettingsService get/set Establishment; settings page section. |
| **Cancellation policy** | **Implemented** | Settings keys; settings section; AppointmentService cancel uses them. |
| **Appointment settings** | **Implemented** | Settings keys; settings section. |
| **Payment settings** | **Implemented** | Settings keys; settings section; default method, receipt notes, etc. |
| **Custom payment methods** | **Implemented** | payment_methods table; CRUD /settings/payment-methods; PaymentService uses. |
| **VAT types and VAT distribution** | **Implemented** | vat_rates table; CRUD /settings/vat-rates; GET /reports/vat-distribution. |
| **Internal notifications** | **Implemented** | Notification settings keys; notifications table; list/mark-read; events create notifications when enabled. |
| **Marketing settings** | **Implemented** | Settings keys; settings section. |
| **Waitlist settings** | **Implemented** | Settings keys; settings section. |
| **Online booking settings** | **Implemented** | Settings keys; settings section. |
| **Matériel Informatique (hardware)** | **Implemented** | Settings keys (use_cash_register, use_receipt_printer); settings section; PaymentService uses. |
| **Membership settings** | **Implemented (operationalized baseline)** | Settings keys (terms_text, renewal_reminder_days, grace_period_days) are used by membership runtime for reminders and grace-period access checks. |
| **Employee groups** | **Implemented (foundation + policy + service booking + permission merge)** | `staff_groups` + `staff_group_members`; `service_staff_groups` via **`ServiceController`/`ServiceService`** (`staff_group_ids`, **`staff_group_ids_sync`**); **`staff_group_permissions`** → `permissions.id`; `PermissionService` merges role + group-derived codes (via `staff.user_id`, branch-scoped); **`GET`/`POST /staff/groups/{id}/permissions`** calls `StaffGroupPermissionService`. **Remaining:** optional denser assignment surface (HTTP already). |
| **Staff hours / salaries** | **Partial** | Staff schedules and breaks exist. **Payroll commission runs** ship (`/payroll/*`, migration `076`); **no** in-repo time clock / timesheets / full wage compliance — see **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.D**. |
| **Memberships (module)** | **Implemented (baseline), partial product depth** | Definitions + authoritative issuance/lifecycle; renewal billing + settlement + **`membership_sales`**; initial sale via **`POST /memberships/sales`** or staff **`POST /sales/invoices`** (membership plan on new invoice). **Remaining depth:** dunning/comms, external capture — see **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** (P2). |
| **Series** | **Implemented (operational baseline)** | `appointment_series` + `appointments.series_id`, `AppointmentSeriesService` (lifecycle, `materializeFutureOccurrences`, whole/forward/single-occurrence cancel), internal `POST /appointments/series`, `/appointments/series/materialize`, `/appointments/series/cancel`, `/appointments/series/occurrence/cancel`; unique `(series_id, start_at)` (`061`). **Remaining depth:** edit/split, richer recurrence. |
| **Document storage / upload management** | **Implemented (foundation), partial depth** | Internal backend: `documents` + `document_links`, allowlisted owner links, metadata routes, **non–web-readable** `system/storage/`, **authenticated internal** file download (`GET /documents/files/{id}/download`, audited). Broader workflow/retention/product depth remains; **no** public/anonymous file access. |

---

## Phased roadmap

**Archive note:** Phases **A–G** below are the **historical** implementation plan that delivered the pure settings map. **Pure settings (14 pages) are CLOSED** — see § **Pure settings phase — CLOSED**. Do not treat A–G as an active task queue; current drivers are **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** (features) and **§6** (platform).

Phases were chosen for **time efficiency** and **minimal dependency chains**. Each phase could be implemented in one or more iterations; “later UI” meant admin UI could follow in a subsequent pass.

---

### Phase A — Settings and payment foundation (no new features)

**Goal:** Make settings and payment methods ready for admin configuration without changing business behaviour yet.

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| A1 | **Establishment / general settings** | Use existing SettingsService; add structured keys (e.g. address, phone, logo_url, timezone already seeded) and group in code. Optional: branch override in get/set. | Later: settings UI grouped by section (general, billing, etc.). |
| A2 | **Custom payment methods** | New table `payment_methods` (code, label, is_active, sort_order, branch_id nullable). Seed default (cash, card, bank_transfer, other). PaymentService + PaymentController + report use list from DB. | Later: CRUD for payment methods in admin. |

**Dependencies:** None.  
**Outcome:** Single source of truth for payment methods; settings ready for grouped/establishment use.

---

### Phase B — Appointment and booking config

**Goal:** Cancellation policy, appointment defaults, and online booking config so behaviour can be tuned without code changes.

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| B1 | **Cancellation policy** | Settings keys or small table (e.g. cancel_cutoff_hours, cancel_fee_type, cancel_fee_amount). AppointmentService cancel flow reads and applies (e.g. block cancel or add fee). | Later: form under Settings or Appointments. |
| B2 | **Appointment settings** | Settings keys: default_booking_window_days, confirmation_reminder_hours, etc. Optional: per-service overrides already exist (buffer_before/after). | Later: settings UI section “Appointments”. |
| B3 | **Online booking settings** | Settings or branch-level flag (e.g. allows_public_booking); optional min_advance_hours, max_days_ahead. PublicBookingService checks before returning slots / accepting book. | Later: Settings or Branch edit. |

**Dependencies:** A1 optional (for consistency). B1/B2 independent; B3 can use B2 keys.

---

### Phase C — Payment and VAT

**Goal:** Payment settings and VAT types so invoicing and reporting can use configurable VAT and payment behaviour.

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| C1 | **Payment settings** | Settings keys: default_payment_method_id (optional), receipt_show_tax, etc. Payment form defaults from settings. | Later: settings section “Payments”. |
| C2 | **VAT types** | New table `vat_rates` (id, name, rate_percent, branch_id nullable, is_default). Services already have vat_rate_id; add FK and migrate. Invoice line logic can resolve VAT from service or default. | Later: VAT CRUD in admin. |
| C3 | **VAT distribution** | Report or extension of existing reports: breakdown by VAT rate (and optionally branch). Read-only; uses payments/invoices + vat_rates. | Later: report endpoint + UI. |

**Dependencies:** A2 for C1 (default payment method). C2 independent; C3 depends on C2.

---

### Phase D — Waitlist and notifications

**Goal:** Waitlist config and internal notification backbone (no external channels required in first slice).

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| D1 | **Waitlist settings** | Settings keys: waitlist_max_entries_per_client, waitlist_auto_notify (bool), etc. WaitlistService reads where needed. | Later: settings section “Waitlist”. |
| D2 | **Internal notifications** | New table `notifications` (user_id, branch_id nullable, type, title, body, link_url, read_at, created_at). Create on relevant events (e.g. waitlist match, appointment reminder). No email/SMS in this phase. | Later: in-app inbox / bell UI. |

**Dependencies:** B2 optional for D1. D2 independent; D1 “auto_notify” can later trigger D2.

---

### Phase E — Marketing and staff structure

**Goal:** Marketing consent config; staff groups baseline (**permissions + booking enforcement**) shipped.

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| E1 | **Marketing settings** | Settings keys: marketing_consent_label, marketing_default_opt_in, etc. Client create/edit reads for labels and defaults. | Later: settings section “Marketing”. |
| E2 | **Employee groups** | **Shipped:** `staff_groups` / members / **`staff_group_permissions`** / **`service_staff_groups`** (service create/edit HTTP); `PermissionService` + `StaffGroupPermissionService`; JSON **`/staff/groups/{id}/permissions`**; booking eligibility + RBAC merge. **Remaining:** optional denser assignment surface. | — |

**Dependencies:** None. E1 and E2 independent.

---

### Phase F — Staff pay and memberships

**Goal:** Optional staff pay data; memberships module (**billing baseline shipped**; no full payroll execution).

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| F1 | **Staff hours / salaries** | Optional table or columns: e.g. `staff_pay_rates` (staff_id, effective_from, hourly_rate, salary_amount, currency). Sensitive; access control and audit. No payroll run in scope. | Later: admin form (restricted permission). |
| F2 | **Memberships** | **Shipped (baseline):** definitions + client memberships; assignment; reminder/grace; benefit redemption; **renewal billing + settlement + `membership_sales` + lifecycle HTTP**. **Remaining depth only:** dunning/comms, external capture, staff invoice path polish — not a greenfield engine. | Module CRUD/assign; staff sale/invoice paths per master roadmap §2. |
| F3 | **Membership settings** | **Shipped:** Settings keys renewal_reminder_days, grace_period_days; `MembershipService` uses for reminders and grace-period access. | Settings section “Memberships” implemented. |

**Dependencies:** E2 optional for F1. F2/F3 baselines shipped; remaining membership depth → master roadmap **§5.C** (P2).

---

### Phase G — Series and documents

**Goal:** Series operational baseline + internal document files shipped; further **workflow/forms** depth is backlog.

| # | Item | Backend | UI / admin |
|---|------|---------|-------------|
| G1 | **Series** | **Shipped (operational baseline):** `appointment_series`, nullable `appointments.series_id`, `AppointmentSeriesService` (lifecycle, materialize, whole/forward/single cancel), internal `POST /appointments/series` + `/materialize` + `/cancel` + `/occurrence/cancel`; occurrences use existing locked appointment pipeline; unique `(series_id, start_at)` (`061`). **Remaining depth:** edit/split, richer recurrence patterns. | — |
| G2 | **Document storage / upload management** | **Shipped (foundation):** `documents` + `document_links` (owner allowlist), internal upload/register/list/show/relink/detach/archive/delete **metadata** routes; files under `system/storage/` (not web-readable). **Shipped:** authenticated internal binary delivery `GET /documents/files/{id}/download` (same auth/permission gate as metadata read; attachment; audited). **Not shipped:** public/anonymous/token file access. **Future:** workflow/retention/product depth. | Later: admin UX may call the controlled download route (no raw storage URL). |

**Dependencies:** G1 depends on appointments (existing). G2 depends on documents (existing). G1 and G2 independent of each other.

---

## Dependency diagram (summary)

```
A (settings + payment methods) ──┬── B (cancellation, appointment, online booking)
                                 ├── C (payment settings, VAT) [C1 needs A2]
                                 └── (general settings used across admin)

B2 (appointment settings) ─────── D1 (waitlist settings)
D2 (notifications) ────────────── standalone

E (marketing, employee groups) ─── F1 (staff pay) [optional]
F2 (memberships baseline) ─────── F3 (membership settings)   [baseline shipped]

Appointments (existing) ───────── G1 (series operational baseline shipped; UX/recurrence depth remains)
Documents (existing) ──────────── G2 (internal storage + download shipped; workflow/forms/integration depth remains)
```

---

## Implementation status vs locked map

**Single source of truth:** See § **Pure settings phase — CLOSED** above for the final pure settings status table (all 14 pages). No pending pure settings groups remain.

**Pages wrongly treated as settings:** None. Module/master-data pages (Espaces, Matériel, Employés, Prestation, Forfaits, Séries, Memberships, Nouveau membership, Stokage documents, Nouveau type de document) are not part of the settings key/value form.

---

## Recommended order after manual QA

**Pure settings phase: CLOSED.** Phases A–D and the membership settings / definitions / client membership **baseline** are implemented.

**Baselines shipped:** E2 (staff groups + permissions + service linkage), F2/F3 (memberships + billing/settlement + settings), G1 (series operational baseline), G2 (documents + internal download). **Not** next greenfield builds.

**Module-depth focus (master roadmap §5.C is primary):**

1. **Memberships** — dunning/comms, external capture, staff invoice path polish.
2. **Series** — edit/split, richer recurrence.
3. **Documents** — workflow / forms / integration (**foundation shipped**).
4. **F1** — staff pay fields (optional).

Espaces, Matériel, Employés, Prestation, Forfaits, Connexions, document types are separate scoping.

---

## Next implementation order (strict priority, after settings closure)

**Pure settings: CLOSED.**

**This file:** module notes and settings-adjacent gaps only. **Primary execution queue:** **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C.**

**Depth-only (not greenfield):**

1. **Memberships** — remaining product depth (dunning, external capture, staff invoice path polish); billing baseline **shipped**.
2. **Series** — edit/split + richer recurrence; operational baseline **shipped**.
3. **Documents** — workflow / forms / integration depth; internal file path **shipped**.
4. **Staff groups** — optional denser assignment only; enforcement **shipped**.

Espaces, Matériel, Employés CRUD, Prestation, Forfaits, Connexions, document types stay outside the settings architecture.

---

## What is not in this roadmap

- Cross-module Booker parity execution (dashboard, outbound messaging, marketing engine, public commerce, payroll) — see **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** / **§5.D**.
- Presentation-layer refactors unrelated to settings closure or the module notes above.
- Speculative architecture changes unrelated to settings or the module notes above.

---

## Active backend task queue (synced)

**Primary queue:** **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** (strict priorities) and **§5.D** (secondary/hold).

**This file — residual module/settings-adjacent notes (not top-queue drivers):**

- **Memberships:** depth only (dunning/comms, external capture, staff invoice path polish); billing/settlement/sales baseline **shipped**.
- **Series:** depth only (edit/split, richer recurrence); operational baseline **shipped**.
- **Documents:** workflow / forms / integration depth; public file delivery **not** in scope; foundation **shipped**.
- **Staff groups:** optional denser assignment; RBAC merge + service linkage + booking enforcement **shipped**.
- **Settings:** unwired/partial keys (`establishment.language`; receipt printer = audit flag, no driver); VAT branch parity; implicit-global callers — see **`SETTINGS-PARITY-ANALYSIS-01.md`** / master roadmap **§5.D**.
- **Security / marketing / external notify / public booking hardening / public commerce money trust:** master roadmap **§5.C** / **§5.D**.

---

## Document meta

- **Booker-level parity driver:** `BOOKER-PARITY-MASTER-ROADMAP.md` **§5.C** (this file is settings + module adjunct only).
- **Platform / tenancy / subscription / ops driver:** same file **§6** (not settings-specific).
- **Booker settings HTML mapping:** CLOSED. Final classification: § Final settings / admin map (locked).
- **Pure settings phase:** CLOSED. All 14 pure settings pages implemented. See § Pure settings phase — CLOSED.
- **Proposed phased roadmap:** § Phased roadmap (Phases A–G).
- **Dependency notes:** § Backlog areas — status and dependencies; § Dependency diagram.
- **Status vs locked map:** § Implementation status vs locked map.
- **Next implementation order:** § Next implementation order (module/master-data after settings closure).
