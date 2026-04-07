# Business IA — Canonical Law (Program 01)

**Program:** `BUSINESS-IA-CANONICAL-REBUILD-PROGRAM-01`  
**Artifact:** authoritative read-first spec. **Does not replace code.**  
**Last anchored to repo:** Phase 0 audit (`system/shared/layout/base.php`, `SettingsController`, route registrars under `system/routes/web/`).

---

## 1. Scope and non-goals

**In scope**

- Frozen **target** information architecture (homes, ownership, navigation law).
- **Current vs target** mapping using **existing routes and modules** (no invented URLs).
- Sequencing for later implementation phases.

**Explicit non-goals (this document)**

- No route path changes, no HTTP method changes, no permission key renames.
- No `SettingsController` POST allowlist or `section=` contract changes.
- No database schema changes.
- No new product features promised; only alignment of **surfacing** and **language** to what already exists.

Implementers must **re-audit live files** at the start of every task; this file is the law, not a substitute for diffing `main`.

---

## 2. Top-level menu law (target)

Exactly **ten** primary homes, in this order:

| # | Home | One-sentence purpose |
|---|------|----------------------|
| 1 | **Overview** | Operational snapshot and entry to the tenant workspace (`/dashboard`). |
| 2 | **Calendar** | Execution: scheduling, appointments, calendar views (`/appointments/*`, `/calendar` family). |
| 3 | **Clients** | Relationship system of record plus **client-owned value visibility** (profile, history, consents, client-scoped sales/docs tabs). |
| 4 | **Team** | People, delivery capacity, schedules, service assignments, **payroll operations**, commissions/performance where implemented. |
| 5 | **Catalog** | **Definitions only**: services, categories, spaces, equipment, **package plans**, **membership plans** — not client-held instances. |
| 6 | **Sales** | **Money movement**: checkout, invoices, payments, refunds, register, **gift card issue/redeem/balance** — liabilities and stored-value **operations**. |
| 7 | **Inventory** | Stock truth and inventory operations (`/inventory/*`). |
| 8 | **Marketing** | Growth execution (e.g. campaigns under `/marketing/*`; client marketing tab remains client-scoped). |
| 9 | **Reports** | **Measurement only**: surfaces that map to real report endpoints (see §3.9). |
| 10 | **Admin** | **Policies, controls, defaults** — establishment, security, notifications, hardware, appointment/booking rules, payments/receipt defaults, waitlist/marketing **policy** fields, membership **policy** text/timing, VAT/payment methods, price reasons; **not** the primary home for day-to-day operational entity CRUD. |

**Canonical ownership mantra**

- **Calendar = execution**  
- **Clients = relationship + client-owned value**  
- **Team = people + delivery + payroll operations**  
- **Catalog = definitions**  
- **Sales = money movement + liabilities + checkout**  
- **Inventory = stock truth**  
- **Marketing = growth execution**  
- **Reports = measurement**  
- **Admin = policies / controls / defaults**

**Non-negotiable conceptual law**

- Definition ≠ live record ≠ transaction ≠ policy.  
- One concept → one **home** (primary mental model); historical URLs may lag until a task explicitly migrates **surfacing** safely.  
- Admin is **not** an operational CRUD junk drawer.

---

## 3. Subsection trees (target) with current route anchors

Paths below are **current** unless marked *(planned)*. Tasks may add shells or links; they must not invent fake analytics.

### 3.1 Overview

- Dashboard: `GET /dashboard`

### 3.2 Calendar

- Calendar day (primary nav href today): `GET /appointments/calendar/day`  
- Other appointment/calendar routes: `system/routes/web/register_appointments_calendar.php`  
- Active-state family in layout: paths starting `/appointments` or `/calendar` (`$navIsAppointments` in `system/shared/layout/base.php`).

### 3.3 Clients

- Index / CRUD: `GET /clients`, `/clients/create`, `/clients/{id}`, `/clients/{id}/edit`, … — `register_clients.php`  
- Workspace tabs (permission-gated): appointments, sales, billing, photos, documents, mail-marketing — see comments in `register_clients.php`  
- Registrations, custom fields, duplicates/merge: same registrar  
- **Target:** active memberships, client packages, owned gift cards/balances, balances due summary, visit/timeline/consent/docs — aggregate **under client context** using existing or new **client-scoped** routes only when backed by real data.

### 3.4 Team

- Staff: `system/routes/web/register_staff.php` (primary nav uses `/staff`)  
- Payroll operations: `system/routes/web/register_payroll.php` (`/payroll/*`)  
- **Target:** directory, schedules, service assignments, payroll **runs/ops**, commissions/performance — **current** surface is the truth until expanded.

### 3.5 Catalog (definitions)

- **Hub (current):** `GET /services-resources` — `modules/services-resources/views/index.php` (labeled “Catalog” in UI).  
- Services: `/services-resources/services/*`  
- Categories: `/services-resources/categories/*`  
- Spaces (rooms): `/services-resources/rooms/*`  
- Equipment: `/services-resources/equipment/*`  
- Package **plans**: `/packages` (definitions); client-held packages: `/packages/client-packages/*` (canonical **Clients** for “owned”; route unchanged until a task proves redirect/shell).  
- Membership **plans**: `/memberships`; client memberships: `/memberships/client-memberships/*` (canonical **Clients** for enrolled state).  
- **Gift cards:** issuance/ledger UI today under `/gift-cards/*` — canonical **Sales** for operations; Catalog may hold **read-only discovery** links only if a task explicitly allows it.

### 3.6 Sales

- Sales workspace: `GET /sales` and related — `register_sales_public_commerce_staff.php` / sales module views  
- Gift cards: `/gift-cards/*` — `modules/gift-cards/routes/web.php`  
- Packages (definitions + client packages): `/packages/*`  
- Active-state family: `$navIsSales` = `/sales`, `/gift-cards`, `/packages` prefixes (`base.php`).

### 3.7 Inventory

- `GET /inventory` and sub-routes — `register_inventory.php`

### 3.8 Marketing

- Primary nav href today: `/marketing/campaigns` — `register_marketing.php`  
- Active family: prefix `/marketing`

### 3.9 Reports

- **Report endpoints (real, JSON-backed reporting):**  
  `GET /reports/revenue-summary`, `/reports/payments-by-method`, `/reports/refunds-summary`, `/reports/appointments-volume`, `/reports/new-clients`, `/reports/staff-appointment-count`, `/reports/gift-card-liability`, `/reports/inventory-movements`, `/reports/vat-distribution` — `register_reports.php`, permission `reports.view`.  
- **Reports HTML hub (live):** `GET /reports` — `ReportController::index` + `modules/reports/views/index.php`; lists only the same GET paths (no fabricated metrics).  
- Settings **read-only** operator guide (not a report UI): `GET /settings/vat-distribution-guide` — `VatDistributionController` / `SettingsController::vatDistributionGuide` commentary.  
- **Primary nav (live):** `$navItems` in `base.php` includes `['/reports', 'Reports', $navIsReports]`; `$navIsReports` = prefix `/reports`.  
- **Target (ongoing polish):** keep the Reports home honest; Phase 7 backlog covers audit/copy cross-links (e.g. VAT guide positioning), not inventing new metrics.

### 3.10 Admin (policies / controls / defaults)

- Main shell: `GET /settings`, `POST /settings` — `section` query drives subsection (`SettingsController::SECTION_ALLOWED_KEYS`).  
- **Allowed sections (write contract keys):** `establishment`, `cancellation`, `appointments`, `payments`, `waitlist`, `marketing`, `security`, `notifications`, `hardware`, `memberships` (plan-policy keys), `public_channels` (combined `online_booking` + `intake` + `public_commerce` allowlist — **do not split POST keys without a dedicated contract task**).  
- Extended Admin-adjacent routes (same “control plane”): payment methods, VAT rates, price modification reasons — `register_settings.php`.  
- Branches: `/branches/*` — `register_branches.php`  
- Documents module (org/client docs): `register_documents.php` — ownership split: **client docs** live under **Clients** context; **policy** may remain Admin-adjacent per future tasks.  
- **Sidebar permission gates:** `SettingsShellSidebar::permissionFlagsForUser()` — links to branches, gift cards, packages, memberships, payroll, services-resources, staff, reports, etc. are **permission-driven**, not “Admin owns” by label alone.

---

## 4. Ownership rules (frozen)

| Domain | Canonical home | Current primary surfacing (routes/modules) | Migration note (logical only) |
|--------|----------------|--------------------------------------------|-------------------------------|
| Membership **plan** definition | Catalog | `/memberships`, hub links from `/services-resources` | Keep URLs; align nav + copy + secondary nav to Catalog. |
| **Client** membership (enrolled) | Clients | `/memberships/client-memberships/*` | Surface from client profile/tabs; no schema change implied. |
| Membership **policy** (terms, renewal reminder, grace) | Admin | `settings` section `memberships` | Remains Admin; not “plan CRUD”. |
| Package **plan** | Catalog | `/packages` | Same as memberships plans. |
| **Client** package (assigned) | Clients | `/packages/client-packages/*` | Often linked from Sales shell today — move **mental model** and breadcrumbs toward Clients. |
| Gift card **issue/redeem/balance/adjust** | Sales | `/gift-cards/*`, invoice redeem POSTs on sales | Catalog hub must not imply Sales ownership long-term except optional discovery. |
| Gift card **liability measurement** | Reports | `/reports/gift-card-liability` | Link from Reports home, not fake charts. |
| Payroll **operations** | Team | `/payroll/*` | Active state today tied to Admin nav family — Phase 1/6 adjust **active prefix** and labels safely. |
| Payroll **policy / permissions** | Admin | Implicit in roles/permissions + settings as applicable | Admin does not own payroll runs. |
| Branches (org structure) | Admin (registry) or explicit “Establishment” subtree | `/branches/*`, establishment screens under `/settings` | Clarify copy: branch registry vs booking context. |
| Waitlist **policy** | Admin | `settings` section `waitlist` | Operations on waitlist entries belong to execution (appointments/calendar context). |
| Public channels (online booking, intake, public commerce) | Admin | `section=public_channels` combined POST allowlist | Never break `PUBLIC_CHANNELS_WRITE_KEYS` contract. |
| Documents / consents | Clients (client-held) + Admin (policy if any) | `/clients/{id}/documents`, documents module | Tabs already permission-gated. |
| VAT / payment methods | Admin | `/settings/payment-methods`, `/settings/vat-rates`, guides | Reports consume data; Admin defines configuration. |

---

## 5. Definition vs live record vs transaction vs policy

| Kind | Meaning | Examples in this product |
|------|---------|----------------------------|
| **Definition** | Sellable/bookable template | Service row, package plan, membership plan, room/equipment type |
| **Live record** | State attached to a client, staff, or branch | Client package instance, client membership, client document, appointment |
| **Transaction** | Money or stored-value movement | Invoice, payment, refund, gift card issue/redeem |
| **Policy** | Org/branch default or control | Settings sections, security, notifications, cancellation rules, public commerce toggles |

Admin **hosts policy**; it does **not** replace Catalog/Sales/Clients for definitions, transactions, or client-held records.

---

## 6. Role-based visibility law (target)

Implementation is **Phase 8**; this section freezes **intent**. Actual permission keys remain the source of truth (`PermissionService`, seeders).

| Role (archetype) | Should see (homes) | Should not see by default |
|------------------|--------------------|---------------------------|
| **Receptionist** | Overview, Calendar, Clients, Sales (checkout/invoices as permitted), Marketing (if campaigns used), Reports (read-only if permitted) | Inventory (unless role grants), deep Admin |
| **Staff** | Overview, Calendar, Team (self/schedule as permitted), Clients (limited), Catalog (read if permitted) | Admin, payroll manager views unless granted |
| **Manager** | All operational homes + Reports | Full platform/registry outside tenant |
| **Owner** | Full tenant operational + Admin + Reports | Platform control plane (separate plane) |

**Rule:** Hide **homes** by permission bundles; do not expose destinations the user cannot access (avoid dead nav).

---

## 7. Breadcrumb and navigation law

1. **Primary nav** reflects the **canonical home** for the operator’s mental model after migration; until URLs move, **active-state families** in `base.php` may still group legacy prefixes (e.g. `/gift-cards` under Sales active state — **correct** today).  
2. **Secondary nav / tabs** must name the **subsection** (Definition vs Client record vs Transaction) honestly.  
3. When a page’s URL sits under a legacy prefix, breadcrumb should read **canonical home → subsection → page**, not repeat misleading “Admin” for operational CRUD.  
4. **Settings** routes remain `/settings?section=…`; bookmarked URLs must keep working.  
5. **No mega-menu:** one row (or sidebar list) of ten homes; deeper structure is secondary nav or workspace tabs.  
6. **Reports:** only link to **real** endpoints or documented guides; no placeholder metrics.

---

## 8. Migration roadmap (logical — phases 1–10)

Aligned to program execution order; each phase is a **bounded** task with verifiers (see `BUSINESS-IA-CANONICAL-BACKLOG-01.md`).

| Phase | Goal |
|-------|------|
| 1 | Primary nav: **maintain** the ten-home rail (**Catalog**, **Reports**, and the rest) and icon parity; adjust **active-state prefixes** only when a bounded task requires it — live `base.php` already implements `$navIsCatalog`, `$navIsReports`, Team includes `/payroll`, Admin prefixes exclude catalog plan URLs (verify with read-only scripts). |
| 2 | Admin boundary: settings shell + side links — operational entities **read** as Catalog/Sales/Clients/Team; preserve `SettingsController` contracts. |
| 3 | Catalog finalization: hub copy + links; definitions only; gift cards de-emphasized or discovery-only. |
| 4 | Sales finalization: workspace copy and grouping; gift card + invoice flows unchanged at route level unless a task proves migration. |
| 5 | Clients: aggregate client-owned memberships/packages/gift cards/balances where data exists. |
| 6 | Team + payroll: payroll operations under Team in nav/state; Admin keeps policy/permissions. |
| 7 | Reports: audit `ReportController` and views; index or shell linking only real reports. |
| 8 | Role-based nav visibility. |
| 9 | Breadcrumbs, secondary nav, naming consistency (singular/plural, plan vs record). |
| 10 | Cross-system polish **only** after ownership is true. |

**Physical module moves** are **out of scope** unless a future task proves necessity (default: **no** `mv` of `system/modules/*`).

---

## 9. Read-only verifiers (do not drift)

Run and extend these when touching nav/catalog/admin copy:

- `system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php`
- `system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php`
- `system/scripts/read-only/verify_admin_ia_business_first_truth_01.php`

New lanes should add **read-only** verifiers under `system/scripts/read-only/` with explicit file anchors.

---

## 10. Document control

- **Supersedes:** informal IA notes for Program 01 only.  
- **Conflict resolution:** if code and this doc disagree, **code is truth for behavior**; update this doc in the same PR as intentional behavior change, or mark the doc stale until fixed.
