# OLLIRA — 7-Module Information Architecture Master Backlog (Program V2-01)

**Program:** `OLLIRA-IA-7MODULE-REBUILD-PROGRAM-V2-01`
**Status:** ACTIVE — This is the **sole authoritative product backlog** for Ollira UI/IA work.
**Declared:** 2026-04-07
**Supersedes:** `BUSINESS-IA-CANONICAL-REBUILD-PROGRAM-01` (10-module plan — see Purge Log §1)
**Does NOT supersede:** `FOUNDATION-A1..A8` (backend kernel — separate program, remains valid)
**Architecture Law:** 7 primary homes as declared in §2
**Live execution queue:** `BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md` → superseded; use §6 of this document

---

## §1 — HARD RESET & PURGE LOG

### 1.1 Reset Declaration

The previous product IA program (`BUSINESS-IA-CANONICAL-REBUILD-PROGRAM-01`) operated on a
**10-module primary navigation model** (Overview, Calendar, Clients, Team, Catalog, Sales,
Inventory, Marketing, Reports, Admin). That model is **superseded**.

The new Ollira IA operates on a **7-module primary navigation model**. All future navigation,
copy, breadcrumb, and role-visibility work is governed by §2 of this document.

**What is NOT reset:**
- `FOUNDATION-A1..A8` (backend tenant kernel, auth, repositories) — independent program, still LIVE
- All closed backend proofs and CLI guardrails — sealed, not affected
- Route paths, HTTP methods, permission keys — unchanged unless an explicit task in this backlog permits

### 1.2 Old-Task Disposition Table

Every task from the old program is assigned one of four verdicts:

| Verdict | Meaning |
|---------|---------|
| **ELIMINATED** | Task is obsolete. The concept it served no longer exists in the 7-module IA. Do not execute. |
| **ABSORBED** | Task is still valid but has been re-homed into a new EPIC in this backlog. Execute from the new location only. |
| **CLOSED-KEPT** | Task was already CLOSED in the old program. The delivered work remains and is compatible with the new IA. |
| **FOUNDATION-ONLY** | Task belongs to the backend program. Not affected by this reset. |

### 1.3 Full Purge Decision Table

| Old Task ID | Old Task Name | Verdict | Reason / New Home |
|-------------|--------------|---------|-------------------|
| 1.0 | Pre-flight nav audit | **ELIMINATED** | Superseded by this master plan audit |
| 1.1 | Ten-home primary rail (add Catalog, Reports) | **ELIMINATED** | New IA has 7 homes. Catalog absorbed into SETTINGS. Reports absorbed into HOME analytics. |
| 1.2 | Active-state families for Catalog | **ELIMINATED** | Catalog is no longer a primary nav home |
| 1.3 | Reports entry strategy as primary nav | **ELIMINATED** | Reports are embedded in HOME (§2 Module 1). No standalone `/reports` primary nav item. |
| 2.1 | Settings shell copy + sidebar | **ABSORBED** → EPIC-07 SETTINGS, FEAT-07.1 | Re-done under SETTINGS module scope |
| 2.2 | Section honesty pass | **CLOSED-KEPT** | CLOSED 2026-04-07. Compatible with new IA. |
| 3.1 | Catalog hub copy + card order | **CLOSED-KEPT** | CLOSED 2026-04-07. Work absorbed into SETTINGS > Services. |
| 3.2 | Secondary "back to catalog" breadcrumbs | **CLOSED-KEPT** | CLOSED 2026-04-08. "Back to Catalog" language replaced by "Back to Services" in new IA. |
| 4.1 | Sales workspace shell copy | **CLOSED-KEPT** | CLOSED 2026-04-07. Module renamed to CASHIER in new IA. |
| 4.2 | Package/gift-card placement | **CLOSED-KEPT** | CLOSED 2026-04-07. Packages → CLIENTS. Gift Cards → CASHIER. |
| 5.1 | Client profile owned-value aggregation | **CLOSED-KEPT** | CLOSED 2026-04-07. Absorbed into EPIC-03 CLIENTS baseline. |
| 5.2 | Deep links from clients list + profile to client-held surfaces | **ABSORBED** → EPIC-03 CLIENTS, FEAT-03.2, STORY-03.2.11 | **DONE** (2026-04-07); `client_id` on list + profile; verifier `verify_story_03_2_11_client_profile_deep_links_01.php`. |
| 6.1 | Payroll highlights Team in nav | **CLOSED-KEPT** | CLOSED (old plan). Compatible. |
| 6.2 | Admin payroll policy copy alignment | **ABSORBED** → EPIC-07 SETTINGS, FEAT-07.4 | Payroll policy belongs in SETTINGS > Roles & Payroll Policy |
| 7.1 | Report module audit | **ELIMINATED** | Reports embedded in HOME analytics. No standalone Reports primary nav. |
| 7.2 | Reports home (honest index) | **ELIMINATED** | Duplicate of 1.3. Reports under HOME. |
| 7.3 | VAT guide position | **ABSORBED** → EPIC-07 SETTINGS, FEAT-07.9 | VAT config lives in SETTINGS > Finance Defaults |
| 8.1 | Permission → home map for 7 archetypes | **ABSORBED** → EPIC-07 SETTINGS, FEAT-07.4 | Still needed; re-homed, now based on 7 modules not 10 |
| 8.2 | Hide dead primary-nav homes by permission | **ABSORBED** → EPIC-01 HOME, FEAT-01.5 (Role-Aware Nav) | Still needed; applies to 7-module nav |
| 9.1 | Breadcrumb component pass | **ABSORBED** → PHASE-4 POLISH, FEAT-P4.1 | Still needed across all 7 modules |
| 9.2 | Tab labels pass | **ABSORBED** → PHASE-4 POLISH, FEAT-P4.2 | Still needed across all 7 modules |
| 10.1 | Empty states + dead-end CTAs | **ABSORBED** → PHASE-4 POLISH, FEAT-P4.3 | Still needed |
| 10.2 | Final verifier sweep | **ABSORBED** → PHASE-4 POLISH, FEAT-P4.4 | Still needed; verifier bundle must be extended for 7-module IA |
| FOUNDATION-A1..A8 | Backend kernel + tenant architecture | **FOUNDATION-ONLY** | Separate program. Not affected. |
| SCALE-WAVE-01..07 | Infrastructure hardening | **FOUNDATION-ONLY** | Separate program. Not affected. |
| PLT-AUTH-02, PLT-MFA-01 | Auth + MFA | **FOUNDATION-ONLY** | Separate program. Not affected. |

---

## §2 — THE 7-MODULE ARCHITECTURE LAW (Frozen)

This section is the authoritative replacement for `BUSINESS-IA-CANONICAL-LAW-01.md`.

### 2.1 The Seven Primary Homes

| # | Module | One-sentence purpose | Primary route prefix |
|---|--------|----------------------|---------------------|
| 1 | **HOME** | Operational command center: KPIs, Action Feed, analytics, Quick Actions. | `/dashboard` |
| 2 | **CALENDAR** | All scheduling execution: appointments, views, booking wizard, waitlist. | `/appointments/*`, `/calendar` |
| 3 | **CLIENTS** | Client relationship system: profile, history, loyalty, memberships, packages, gift cards, **and marketing execution**. | `/clients/*`, `/memberships/*`, `/packages/*`, `/gift-cards/*`, `/marketing/*` |
| 4 | **TEAM** | People + delivery: staff directory, rotas, service assignments, commissions, payroll operations. | `/staff/*`, `/payroll/*` |
| 5 | **CASHIER** | Money movement: POS checkout, invoices, payments, refunds, cash drawer, register, financial reports. | `/sales/*` |
| 6 | **STOCK** | Inventory truth: products, stock control, purchase orders, supplier management. | `/inventory/*` |
| 7 | **SETTINGS** | Policies, controls, defaults, AND service/catalog definitions: business profile, branches, services & pricing, roles & permissions, online booking, notifications, integrations, audit log. | `/settings/*`, `/services-resources/*`, `/branches/*` |

### 2.2 Canonical Ownership Mantra

- **HOME = command + measurement**
- **CALENDAR = scheduling execution**
- **CLIENTS = relationship + loyalty + marketing**
- **TEAM = people + delivery + payroll**
- **CASHIER = money movement**
- **STOCK = inventory truth**
- **SETTINGS = policies + controls + service definitions**

### 2.3 Key Differences from Old 10-Module Plan

| Old Module | New Location | Reason |
|------------|-------------|--------|
| Overview → | HOME | Same concept, renamed |
| Calendar → | CALENDAR | Unchanged |
| Clients → | CLIENTS | Unchanged |
| Team → | TEAM | Unchanged |
| **Catalog** → | SETTINGS > Services & Pricing | Catalog is a definition/policy concept, not a daily operational home |
| Sales → | CASHIER | Renamed to reflect the role, not the module |
| Inventory → | STOCK | Renamed for clarity |
| **Marketing** → | CLIENTS > Marketing | Marketing is client-segment execution; it belongs with the CRM |
| **Reports** → | HOME > Analytics (management) + CASHIER > Financial Reports (ops) | Reports are not a home; they are panels inside operational homes |
| Admin → | SETTINGS | Renamed; now also absorbs service/catalog definitions |

### 2.4 Non-Negotiable Laws

1. **7 homes. No more.** Any request to add an 8th primary nav item requires a dedicated architecture review task.
2. **Catalog is not a home.** Service definitions live in SETTINGS > Services & Pricing.
3. **Marketing is not a home.** Campaigns and automations live inside CLIENTS.
4. **Reports are not a home.** Analytics live in HOME (management view). Financial reports live in CASHIER.
5. **Role-aware nav:** A receptionist sees [HOME, CALENDAR, CLIENTS, CASHIER]. A stylist sees [HOME, CALENDAR, MY CLIENTS]. A manager sees all 7.
6. **No dead ends.** Every page must have a logical next action.

---

## §3 — MASTER PRODUCT BACKLOG

Format: `EPIC-XX` → `FEAT-XX.Y` → `STORY-XX.Y.Z`

Status vocabulary: `DONE` | `IN PROGRESS` | `NEXT` | `OPEN` | `DEFERRED`

---

### EPIC-01: HOME — Dashboard & Command Center

**Goal:** Replace the passive dashboard with an active command center that tells the business what to do next.

#### FEAT-01.1: KPI Strip (Today's Pulse)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.1.1 | Build `GET /dashboard` data aggregation endpoint returning: today's bookings count, today's revenue, no-show count, chair/room utilisation % | OPEN | Extend existing `DashboardController` |
| STORY-01.1.2 | Design and render KPI strip UI (4 cards: Bookings, Revenue, No-Shows, Utilisation) | OPEN | |
| STORY-01.1.3 | KPI strip is branch-aware (switches on branch change) | OPEN | |
| STORY-01.1.4 | KPI strip is role-aware (Manager sees all; Receptionist/Stylist sees own lane only) | OPEN | |

#### FEAT-01.2: My Day Panel (Role-Contextual Queue)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.2.1 | Build "My Day" panel: shows today's appointments for the logged-in staff member | OPEN | |
| STORY-01.2.2 | For Manager/Owner: "My Day" shows all chairs/lanes summary view | OPEN | |
| STORY-01.2.3 | Each appointment card in "My Day" links directly to the appointment record | OPEN | |

#### FEAT-01.3: Action Feed (Smart Alerts)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.3.1 | Build Action Feed API: returns ordered list of alerts (arriving clients, low stock, unconfirmed bookings, staff rebooking drop) | OPEN | |
| STORY-01.3.2 | Alert types: arrival warning (30 min), unconfirmed online bookings, low stock items, no-show follow-up pending | OPEN | |
| STORY-01.3.3 | Each alert has a one-click action (e.g. "Confirm Booking", "View Stock") | OPEN | |
| STORY-01.3.4 | Action Feed auto-refreshes every 60 seconds | OPEN | |

#### FEAT-01.4: Quick Actions Bar (Persistent Strip)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.4.1 | Add persistent Quick Actions strip: [+ New Booking] [Check In Client] [Quick Sale] [+ New Client] | OPEN | Visible on HOME and CALENDAR |
| STORY-01.4.2 | Quick Actions respect role: Receptionist sees all 4; Stylist sees none or limited | OPEN | |
| STORY-01.4.3 | [+ New Booking] opens the CALENDAR new-booking drawer directly | OPEN | |
| STORY-01.4.4 | [Quick Sale] opens CASHIER POS with no pre-loaded appointment | OPEN | |

#### FEAT-01.5: Role-Aware Primary Navigation

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.5.1 | Primary nav renders only permitted homes per user role | OPEN | Absorbed from old Task 8.2 |
| STORY-01.5.2 | Receptionist nav: [HOME, CALENDAR, CLIENTS, CASHIER] | OPEN | |
| STORY-01.5.3 | Stylist/Therapist nav: [HOME, CALENDAR, CLIENTS (read)] | OPEN | |
| STORY-01.5.4 | Manager/Owner nav: all 7 homes | OPEN | |
| STORY-01.5.5 | Permission → home map configuration in SETTINGS > Roles & Permissions | OPEN | Absorbed from old Task 8.1 |

#### FEAT-01.6: Management Analytics Panels (Manager/Owner Only)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.6.1 | Revenue Overview panel (Today / Week / Month / Custom range) | OPEN | Absorbs old Reports primary nav |
| STORY-01.6.2 | Chair / Room Utilisation Heatmap | OPEN | |
| STORY-01.6.3 | Top Services by Revenue (bar chart) | OPEN | |
| STORY-01.6.4 | Top Staff by Revenue (ranked list) | OPEN | |
| STORY-01.6.5 | Retention Rate & Rebooking % trend | OPEN | |
| STORY-01.6.6 | New vs Returning Clients ratio | OPEN | |
| STORY-01.6.7 | Cancellation & No-Show Rate | OPEN | |
| STORY-01.6.8 | Product Sales vs Service Revenue split | OPEN | |
| STORY-01.6.9 | Analytics panels are below-the-fold; not shown to Receptionist/Stylist | OPEN | |

#### FEAT-01.7: First-Time User Onboarding Flow

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-01.7.1 | Onboarding checklist (collapsible): Add branch → Add services → Add team → Set schedule → Take first booking | OPEN | |
| STORY-01.7.2 | Empty state: when no data exists, show guided prompt instead of zero-filled cards | OPEN | |
| STORY-01.7.3 | Onboarding progress bar dismissed permanently after all 5 steps complete | OPEN | |

---

### EPIC-02: CALENDAR — Appointments & Smart Scheduling

**Goal:** The primary daily workspace for all appointment operations.

#### FEAT-02.1: Calendar Views

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-02.1.1 | Day View: lane per staff member, drag-drop blocks | DONE | Existing `/appointments/calendar/day` |
| STORY-02.1.2 | Week View: condensed occupancy for managers | OPEN | |
| STORY-02.1.3 | Month View: occupancy overview | OPEN | |
| STORY-02.1.4 | Room/Resource View: lane per room/bed instead of per staff | OPEN | |
| STORY-02.1.5 | View switcher (Day/Week/Month/Room) in calendar toolbar | OPEN | |

#### FEAT-02.2: Appointment Card

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-02.2.1 | Appointment card: client name + photo + loyalty tier badge | OPEN | |
| STORY-02.2.2 | Appointment card: service(s) + assigned staff + duration | DONE | Partial |
| STORY-02.2.3 | Appointment card: status badge (Confirmed/Arrived/In Progress/Completed/No-Show/Cancelled) | OPEN | |
| STORY-02.2.4 | Appointment card: deposit status indicator | OPEN | |
| STORY-02.2.5 | Appointment card: allergy / contraindication flag (red icon) | OPEN | Clinic-critical |
| STORY-02.2.6 | Quick actions on card: [Check In] [Start] [Complete] [Reschedule] [Cancel] [Add Service] | OPEN | |

#### FEAT-02.3: New Booking Wizard (Drawer)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-02.3.1 | Step 1: Client search (live search) + quick-add new client inline | DONE | Existing wizard |
| STORY-02.3.2 | Step 2: Service selector (search + category filter + duration display) | DONE | |
| STORY-02.3.3 | Step 3: Staff preference ("Any available" or specific staff) | DONE | |
| STORY-02.3.4 | Step 4: Smart slot picker (shows only genuinely available slots given staff + duration) | DONE | |
| STORY-02.3.5 | Step 5: Add-ons / package selection | OPEN | |
| STORY-02.3.6 | Step 6: Deposit collection (card-on-file / payment link / skip) | OPEN | |
| STORY-02.3.7 | Step 7: Confirmation + auto-trigger SMS/email reminder | OPEN | |
| STORY-02.3.8 | Wizard opens as a side drawer, not a full-page navigation | OPEN | Current wizard is full-page |

#### FEAT-02.4: Waitlist Manager

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-02.4.1 | Waitlist queue: list clients waiting per service/staff | OPEN | `WaitlistService` exists |
| STORY-02.4.2 | Auto-notify waitlisted client when a slot opens (SMS/email) | OPEN | |
| STORY-02.4.3 | One-click: convert waitlist entry → confirmed booking | OPEN | |
| STORY-02.4.4 | Waitlist dashboard panel on CALENDAR view | OPEN | |

#### FEAT-02.5: Recurring Appointments

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-02.5.1 | Define recurrence rule on booking: weekly / fortnightly / monthly / custom | OPEN | `AppointmentSeriesService` exists |
| STORY-02.5.2 | Bulk-reschedule series (move all future occurrences) | OPEN | |
| STORY-02.5.3 | Bulk-cancel series | OPEN | |

#### FEAT-02.6: Blocked Time & Break Management

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-02.6.1 | Block staff time: lunch, training, holiday | DONE | `BlockedSlotService` exists |
| STORY-02.6.2 | Block room/equipment for maintenance | OPEN | |
| STORY-02.6.3 | Blocked blocks visible in calendar lane (distinct colour) | OPEN | |

---

### EPIC-03: CLIENTS — CRM, Loyalty & Marketing

**Goal:** The complete relationship and value system for every client, including marketing execution (absorbed from old Marketing primary nav).

#### FEAT-03.1: Client Directory

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.1.1 | Client list with search (name, phone, email) | DONE | `/clients` |
| STORY-03.1.2 | Filter by loyalty tier, last visit date, tags, custom fields | OPEN | |
| STORY-03.1.3 | Smart segments: "Lapsed 60+ days", "Birthday this month", "High spenders", "At-risk" | OPEN | |
| STORY-03.1.4 | Bulk import from CSV | OPEN | |
| STORY-03.1.5 | Export client list to CSV | OPEN | |

#### FEAT-03.2: Client Profile (The Core Record)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.2.1 | Overview tab: photo, contact, lifetime value, visit count, loyalty points, tier badge | OPEN | |
| STORY-03.2.2 | Appointment History tab | DONE | Existing |
| STORY-03.2.3 | Purchase History tab (services + retail products per visit) | DONE | Existing |
| STORY-03.2.4 | Treatment Notes tab (SOAP notes for clinics; colour formula notes for salons) | OPEN | |
| STORY-03.2.5 | Consultation Forms tab (digital intake forms, consent forms, patch test records) | OPEN | |
| STORY-03.2.6 | Before & After Photos tab | OPEN | Clinic-critical |
| STORY-03.2.7 | Preferences tab (preferred staff, favourite services, product allergies) | OPEN | |
| STORY-03.2.8 | Owned Value tab (loyalty points, memberships, packages, gift card balances, invoice rollup) | DONE | CLOSED Phase 5.1 |
| STORY-03.2.9 | Invoices & Payments tab (full financial ledger per client) | DONE | Existing |
| STORY-03.2.10 | Documents tab (signed consents, prescriptions for clinics) | OPEN | |
| STORY-03.2.11 | **Deep links from clients list + client profile** to client-held surfaces: `/memberships/client-memberships`, `/packages/client-packages`, `/gift-cards` with **`client_id`** filter (exact client scope) | DONE | 2026-04-07: `/clients` index + `clients/views/show.php`; `verify_story_03_2_11_client_profile_deep_links_01.php` exits 0 |
| STORY-03.2.12 | [Quick Book] always visible on client profile summary; starts live `/appointments/create` → full-page wizard with **client_id** in wizard state (same contract as Add Appointment) | DONE | `verify_story_03_2_12_client_profile_quick_book_01.php` exits 0 |

#### FEAT-03.3: Loyalty Programme

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.3.1 | Points scheme: earn X points per £/$ spent | OPEN | Config in SETTINGS > Loyalty |
| STORY-03.3.2 | Tier management: Bronze / Silver / Gold / Platinum (configurable names and thresholds) | OPEN | |
| STORY-03.3.3 | Points balance visible on client profile and on CASHIER POS at checkout | OPEN | |
| STORY-03.3.4 | Redeem points at checkout (convert to discount) | OPEN | |
| STORY-03.3.5 | Points ledger per client | OPEN | |

#### FEAT-03.4: Memberships & Packages

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.4.1 | Membership plans list (definitions) — visible from CLIENTS as "current client memberships" | DONE | `/memberships` |
| STORY-03.4.2 | Enrol client in a membership plan | DONE | |
| STORY-03.4.3 | Client membership status: active / paused / expired | DONE | |
| STORY-03.4.4 | Package plans list (definitions) | DONE | `/packages` |
| STORY-03.4.5 | Assign package to client | DONE | |
| STORY-03.4.6 | Package usage tracker per client | DONE | |
| STORY-03.4.7 | Package / membership expiry alerts (visible in Action Feed and client profile) | OPEN | |

#### FEAT-03.5: Gift Vouchers (Client-Held)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.5.1 | Issue gift voucher (amount or service-specific) | DONE | `/gift-cards` |
| STORY-03.5.2 | Voucher balance lookup | DONE | |
| STORY-03.5.3 | Redeem at CASHIER POS | DONE | |
| STORY-03.5.4 | Voucher validity and expiry management | DONE | |
| STORY-03.5.5 | Client gift card balance visible on client profile Owned Value tab | DONE | Phase 5.1 |

#### FEAT-03.6: Marketing — Campaigns & Automations

*This feature absorbs the old standalone Marketing primary nav into the CLIENTS module.*

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.6.1 | Campaign builder: select audience (smart segment), compose email + SMS, schedule or send now | OPEN | `/marketing/*` routes exist |
| STORY-03.6.2 | Campaign templates library (welcome, seasonal, reactivation) | OPEN | |
| STORY-03.6.3 | Campaign performance report: open rate, click rate, bookings generated | OPEN | |
| STORY-03.6.4 | Automation triggers: appointment reminder (24h + 2h), post-visit thank you + review request | OPEN | Marketing automations worker exists |
| STORY-03.6.5 | Automation triggers: rebooking nudge (X days after last visit), birthday offer, lapsed client win-back | OPEN | |
| STORY-03.6.6 | Automation triggers: package expiry warning, membership renewal reminder | OPEN | |
| STORY-03.6.7 | Marketing nav item visible inside CLIENTS module sidebar (not as a primary nav home) | OPEN | Key navigation change |

#### FEAT-03.7: Consultation Forms Manager

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-03.7.1 | Form builder (drag-drop fields: text, checkbox, signature, photo upload) | OPEN | |
| STORY-03.7.2 | Assign form to a service category (auto-send before appointment) | OPEN | |
| STORY-03.7.3 | Client form submission history on client profile | OPEN | |
| STORY-03.7.4 | Digital consent signature capture | OPEN | Clinic-critical |

---

### EPIC-04: TEAM — Staff Management

**Goal:** Complete people management: schedules, service assignments, commissions, and payroll operations.

#### FEAT-04.1: Staff Directory

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-04.1.1 | Staff list with status (Active / Inactive / On Leave) | DONE | `/staff` |
| STORY-04.1.2 | Filter by branch, role, speciality | OPEN | |
| STORY-04.1.3 | Quick-add new staff member (name, role, branch, services) | DONE | |

#### FEAT-04.2: Staff Profile

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-04.2.1 | Personal details: name, photo, contact, role, branch | DONE | |
| STORY-04.2.2 | Services Offered tab: link staff to specific services they can perform | DONE | |
| STORY-04.2.3 | Working Schedule tab: weekly rota, shift hours per day | OPEN | |
| STORY-04.2.4 | Time Off & Holidays tab: request / approve / block on calendar | OPEN | |
| STORY-04.2.5 | Commission Rules tab: % per service / per product sale / tiered rates | OPEN | |
| STORY-04.2.6 | Performance tab: revenue generated, bookings, avg ticket, rebooking rate | OPEN | |
| STORY-04.2.7 | Payroll Summary tab: calculated commissions per period | OPEN | |
| STORY-04.2.8 | System Access Role tab: assign Receptionist / Stylist / Manager role | OPEN | Links to SETTINGS > Roles |

#### FEAT-04.3: Rota / Schedule Builder

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-04.3.1 | Weekly schedule grid per staff member | OPEN | |
| STORY-04.3.2 | Copy previous week function | OPEN | |
| STORY-04.3.3 | Multi-staff conflict detector (two staff same shift, no coverage warning) | OPEN | |
| STORY-04.3.4 | Schedule feeds directly into CALENDAR availability engine | OPEN | |

#### FEAT-04.4: Commission Calculator

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-04.4.1 | Run commission report for a date range | OPEN | `/payroll/*` routes exist |
| STORY-04.4.2 | Per-staff breakdown: services rendered + products sold + totals | OPEN | |
| STORY-04.4.3 | Override / adjustment with notes | OPEN | |
| STORY-04.4.4 | Mark pay run as "Paid" with timestamp | OPEN | |
| STORY-04.4.5 | Export commission report to CSV / PDF | OPEN | |

#### FEAT-04.5: Performance Dashboard

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-04.5.1 | Per-staff metrics: utilisation rate, revenue, avg ticket, rebooking % | OPEN | |
| STORY-04.5.2 | Optional team leaderboard (configurable ON/OFF in SETTINGS) | OPEN | |
| STORY-04.5.3 | Performance data feeds HOME analytics (FEAT-01.6) | OPEN | |

---

### EPIC-05: CASHIER — Point of Sale, Invoicing & Finance

**Goal:** All money movement in one place. Renamed from "Sales" for role clarity.

#### FEAT-05.1: POS Terminal

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-05.1.1 | Search client → auto-load completed appointment(s) for checkout | DONE | `/sales` cashier |
| STORY-05.1.2 | Add services (walk-in or add-ons to existing appointment) | DONE | |
| STORY-05.1.3 | Add retail products (barcode scan / search) | OPEN | |
| STORY-05.1.4 | Apply discount: amount / % / voucher code / loyalty points redemption | OPEN | |
| STORY-05.1.5 | Split payment: card + cash + voucher in one transaction | OPEN | |
| STORY-05.1.6 | Tip allocation: assign to staff or split | OPEN | |
| STORY-05.1.7 | Print or email receipt | OPEN | |
| STORY-05.1.8 | Post-checkout: [Rebook Now] prompt | OPEN | |
| STORY-05.1.9 | Loyalty points balance displayed at checkout; allow redemption in same transaction | OPEN | |

#### FEAT-05.2: Invoices

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-05.2.1 | Invoice list (filter by date, client, status, branch) | DONE | Existing |
| STORY-05.2.2 | Invoice detail: view / download PDF / resend to client | DONE | |
| STORY-05.2.3 | Mark outstanding invoices as paid | DONE | |
| STORY-05.2.4 | Refund / void invoice flow | DONE | |

#### FEAT-05.3: Cash Drawer Management

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-05.3.1 | Open shift: enter float amount | DONE | Register module |
| STORY-05.3.2 | Cash-in / cash-out entries with reason | OPEN | |
| STORY-05.3.3 | Close shift: actual count vs system expected, discrepancy flagged | OPEN | |

#### FEAT-05.4: Financial Reports (embedded in CASHIER)

*These replace the old standalone Reports primary nav for operational financial data.*

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-05.4.1 | Daily Takings Report: revenue by payment method + by service category + by staff + discounts log | DONE | `/reports/revenue-summary` etc. |
| STORY-05.4.2 | Revenue report with custom date range | DONE | |
| STORY-05.4.3 | Tax / VAT report | DONE | `/reports/vat-distribution` |
| STORY-05.4.4 | Commission payroll report | DONE | |
| STORY-05.4.5 | Gift voucher liability report | DONE | `/reports/gift-card-liability` |
| STORY-05.4.6 | Export reports to CSV / PDF / accounting software (Xero, QuickBooks link) | OPEN | |
| STORY-05.4.7 | Reports accessible from CASHIER module sidebar (not a separate primary nav home) | OPEN | Key navigation change |

---

### EPIC-06: STOCK — Inventory, Products & Suppliers

**Goal:** Complete stock truth. Renamed from "Inventory" for clarity.

#### FEAT-06.1: Product Catalogue

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-06.1.1 | Product list (search, filter by brand/category/type) | DONE | `/inventory` |
| STORY-06.1.2 | Product record: name, SKU, brand, category, retail price, cost price | DONE | |
| STORY-06.1.3 | Stock level per branch, reorder point, reorder quantity | OPEN | |
| STORY-06.1.4 | Link product to services (professional usage tracking) | OPEN | |
| STORY-06.1.5 | Bulk import / export | OPEN | |

#### FEAT-06.2: Stock Control

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-06.2.1 | Stock adjustment: receive delivery / manual correction / damage write-off | DONE | Partial |
| STORY-06.2.2 | Stock take: compare actual count vs system, flag discrepancies | OPEN | |
| STORY-06.2.3 | Internal usage log: auto-deduct professional products when a service is marked complete | OPEN | |
| STORY-06.2.4 | Transfer stock between branches | OPEN | |

#### FEAT-06.3: Purchase Orders

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-06.3.1 | Create PO: select supplier → add products + quantities | OPEN | |
| STORY-06.3.2 | PO status: Draft / Sent / Partially Received / Received | OPEN | |
| STORY-06.3.3 | Receive delivery: auto-update stock levels | OPEN | |
| STORY-06.3.4 | PO history and PDF export | OPEN | |

#### FEAT-06.4: Suppliers

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-06.4.1 | Supplier directory: name, contact, lead time, payment terms | OPEN | |
| STORY-06.4.2 | Link supplier to products | OPEN | |

#### FEAT-06.5: Alerts & Reports

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-06.5.1 | Low stock alert dashboard panel | OPEN | |
| STORY-06.5.2 | Low stock alerts feed into HOME Action Feed (FEAT-01.3) | OPEN | |
| STORY-06.5.3 | Stock valuation report (retail + professional) | OPEN | |
| STORY-06.5.4 | Dead stock report (no movement in X days) | OPEN | |
| STORY-06.5.5 | Best-selling retail products report | OPEN | |
| STORY-06.5.6 | Professional usage cost per service (profitability per treatment) | OPEN | |

---

### EPIC-07: SETTINGS — System Configuration

**Goal:** All policies, controls, defaults, AND service/catalog definitions. Replaces both old Admin and old Catalog primary homes.

#### FEAT-07.1: Business Profile

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.1.1 | Business name, logo, contact details, timezone, currency, language | DONE | `/settings?section=establishment` |
| STORY-07.1.2 | Tax / VAT number and default rates | DONE | |
| STORY-07.1.3 | Default business hours | DONE | |

#### FEAT-07.2: Branches & Locations

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.2.1 | Add / edit / archive branches | DONE | `/branches/*` |
| STORY-07.2.2 | Branch-specific hours, contact, address | DONE | |
| STORY-07.2.3 | Resources per branch: chairs, rooms, beds | OPEN | |
| STORY-07.2.4 | Staff assignment per branch | DONE | |

#### FEAT-07.3: Services & Pricing (absorbs old Catalog)

*This sub-section is the new home for what was the "Catalog" primary nav.*

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.3.1 | Service categories (hierarchy: Category → Sub-category → Service) | DONE | `/services-resources/categories/*` |
| STORY-07.3.2 | Service record: name, duration, price, description, category, eligible staff | DONE | `/services-resources/services/*` |
| STORY-07.3.3 | Variable pricing per staff tier (junior / senior pricing) | OPEN | |
| STORY-07.3.4 | Duration buffers (setup / cleanup time after each service) | OPEN | |
| STORY-07.3.5 | Service packages / combos definition | DONE | `/packages/definitions` |
| STORY-07.3.6 | Membership plan definitions | DONE | `/memberships/definitions` |
| STORY-07.3.7 | Spaces / Rooms setup (link to services) | DONE | `/services-resources/rooms/*` |
| STORY-07.3.8 | Equipment management | DONE | `/services-resources/equipment/*` |
| STORY-07.3.9 | **Navigation**: Services & Pricing is a sub-section of SETTINGS, not a primary nav home. Update `base.php` active-state logic. | OPEN | Key change from old plan |

#### FEAT-07.4: Roles & Permissions

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.4.1 | Role list: Owner / Manager / Receptionist / Stylist / Therapist | DONE | `PermissionService` |
| STORY-07.4.2 | Permission matrix: module → view / create / edit / delete per role | DONE | |
| STORY-07.4.3 | Assign role to staff member | DONE | |
| STORY-07.4.4 | Permission → home map: configure which homes each role sees (feeds FEAT-01.5) | OPEN | Absorbed from old Task 8.1 |
| STORY-07.4.5 | Payroll policy: who can approve pay runs, commission rules defaults | OPEN | Absorbed from old Task 6.2 |

#### FEAT-07.5: Online Booking

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.5.1 | Enable / disable online booking per branch | DONE | `section=public_channels` |
| STORY-07.5.2 | Booking widget embed code | OPEN | |
| STORY-07.5.3 | Services available online (whitelist) | DONE | |
| STORY-07.5.4 | Deposit requirements per service | DONE | |
| STORY-07.5.5 | Cancellation / reschedule policy | DONE | `section=cancellation` |
| STORY-07.5.6 | Booking confirmation + reminder message templates | OPEN | |
| STORY-07.5.7 | Buffer time between online bookings | OPEN | |

#### FEAT-07.6: Notifications

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.6.1 | SMS / Email template editor per event type | OPEN | |
| STORY-07.6.2 | Notification triggers ON/OFF per channel | OPEN | `section=notifications` |
| STORY-07.6.3 | Sender name / reply-to configuration | OPEN | |

#### FEAT-07.7: Integrations

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.7.1 | Payment gateway configuration (Stripe / Square) | OPEN | `section=payments` |
| STORY-07.7.2 | Accounting integration (Xero / QuickBooks export setup) | OPEN | |
| STORY-07.7.3 | Google Calendar sync | OPEN | |
| STORY-07.7.4 | Marketing integration (Mailchimp / Klaviyo API key) | OPEN | |
| STORY-07.7.5 | API / Webhooks (developer access) | OPEN | |

#### FEAT-07.8: Consultation & Consent Forms

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.8.1 | Form template builder (admin view; form library) | OPEN | |
| STORY-07.8.2 | Assign form templates to service categories | OPEN | |
| STORY-07.8.3 | Link to CLIENTS module (client form submissions on profile) | OPEN | |

#### FEAT-07.9: Finance Defaults (VAT, Payment Methods, Price Reasons)

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.9.1 | VAT rate configuration | DONE | `/settings/vat-rates` |
| STORY-07.9.2 | Custom payment methods | DONE | `/settings/payment-methods` |
| STORY-07.9.3 | Price modification reasons (discount reasons) | DONE | |
| STORY-07.9.4 | VAT distribution guide (operator reference doc) | DONE | `/settings/vat-distribution-guide` |

#### FEAT-07.10: Audit Log

| Story ID | Story | Status | Notes |
|----------|-------|--------|-------|
| STORY-07.10.1 | System event log: who changed what, when, on which record | OPEN | `FounderImpersonationAuditService` partial |
| STORY-07.10.2 | Filter audit log by user, date, module | OPEN | |
| STORY-07.10.3 | GDPR-compliant export of audit log | OPEN | |

---

## §4 — PHASED EXECUTION ROADMAP

### Dependency Law (non-negotiable)

```
PHASE 1 (Platform) must be CLOSED before PHASE 2 starts.
PHASE 2 (Navigation) must be CLOSED before PHASE 3 features ship.
PHASE 3 features can run in parallel with each other.
PHASE 4 (Polish) runs after all PHASE 3 EPICs reach their MVP milestone.
```

---

### PHASE 1 — Platform Foundation

**Status: CLOSED**
**Evidence: FOUNDATION-A1..A8, SCALE-WAVE-01..07, PLT-AUTH-02, PLT-MFA-01**

| Task | Status |
|------|--------|
| TenantContext Kernel (FOUNDATION-A1) | CLOSED |
| Authorization Kernel (FOUNDATION-A2) | CLOSED |
| Service Layer DB Ban (FOUNDATION-A3) | CLOSED |
| Canonical Repository API (FOUNDATION-A4) | CLOSED |
| Media Pilot Rewrite (FOUNDATION-A5) | CLOSED |
| Mechanical CI Guardrails (FOUNDATION-A6) | CLOSED |
| Migration Map complete (FOUNDATION-A7) | CLOSED |
| Redis + Session + Queue hardening (WAVE-01..07) | CLOSED |
| Auth + MFA (PLT-AUTH-02, PLT-MFA-01) | CLOSED |

**What Phase 1 unlocks:** The platform is safe to build product features on. Tenant isolation is enforced. Authorization is centralized. CI guardrails prevent regression.

---

### PHASE 2 — Navigation Architecture Restructure

**Status: IN PROGRESS**
**Goal:** Restructure primary nav from 10 homes → 7 homes. No new features. Only navigation, active-state, and copy changes.

| Task | EPIC | Story | Priority | Status |
|------|------|-------|----------|--------|
| Remove Catalog from primary nav; add to SETTINGS sidebar | EPIC-07 | STORY-07.3.9 | P0 | **NEXT** |
| Remove Marketing from primary nav; surface inside CLIENTS | EPIC-03 | STORY-03.6.7 | P0 | OPEN |
| Remove Reports from primary nav; surface inside CASHIER and HOME | EPIC-05 | STORY-05.4.7 | P0 | OPEN |
| Deep links from clients list + profile (Phase 5.2 carried over) | EPIC-03 | STORY-03.2.11 | P0 | **DONE** (`verify_story_03_2_11_client_profile_deep_links_01.php`) |
| Role-aware 7-module nav (hide dead homes by permission) | EPIC-01 | STORY-01.5.1..5 | P1 | OPEN |
| Update verifier bundle for 7-module nav structure | EPIC-P4 | FEAT-P4.4 | P1 | OPEN |

**Phase 2 done when:**
- Primary nav has exactly 7 items for all roles
- No dead nav items for any role
- All existing read-only verifiers pass (+ extended for 7-module structure)

---

### PHASE 3 — Core Module Build-Out

**Status: OPEN**
**Goal:** Each module reaches its MVP feature set. Can be parallelized across modules.

#### Phase 3A — HOME (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-01.1.1..4 (KPI Strip) | Phase 2 complete |
| P0 | STORY-01.3.1..4 (Action Feed) | Phase 2 complete |
| P0 | STORY-01.4.1..4 (Quick Actions bar) | Phase 2 complete |
| P1 | STORY-01.6.1..9 (Analytics Panels) | KPI Strip done |
| P2 | STORY-01.7.1..3 (Onboarding flow) | Analytics done |

#### Phase 3B — CALENDAR (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-02.2.1..6 (Appointment card full UI) | Phase 2 complete |
| P0 | STORY-02.3.8 (Wizard as drawer, not full-page) | Phase 2 complete |
| P1 | STORY-02.1.2..5 (Week/Month/Room views) | Day view is done |
| P1 | STORY-02.4.1..4 (Waitlist Manager) | Phase 2 complete |
| P2 | STORY-02.5.1..3 (Recurring appointments) | Wizard drawer done |

#### Phase 3C — CLIENTS (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-03.2.11 (Deep links) | DONE (2026-04-07) |
| P0 | STORY-03.2.1 (Profile overview tab) | Phase 2 complete |
| P0 | STORY-03.6.1..3 (Campaign builder) | CLIENTS nav change done |
| P1 | STORY-03.3.1..5 (Loyalty Programme) | Profile tabs done |
| P1 | STORY-03.6.4..6 (Marketing Automations) | Campaign builder done |
| P2 | STORY-03.4.7 (Package/membership expiry alerts) | Loyalty done |
| P3 | STORY-03.7.1..4 (Consultation Forms) | Profile tabs done |

#### Phase 3D — TEAM (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-04.3.1..4 (Rota / Schedule Builder) | Phase 2 complete |
| P1 | STORY-04.4.1..5 (Commission Calculator) | Rota done |
| P2 | STORY-04.5.1..3 (Performance Dashboard) | Commission done |

#### Phase 3E — CASHIER (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-05.1.4..5 (Discount + split payment) | Phase 2 complete |
| P0 | STORY-05.1.9 (Loyalty points at checkout) | STORY-03.3.1 done |
| P1 | STORY-05.3.2..3 (Cash drawer close shift) | Phase 2 complete |
| P1 | STORY-05.4.7 (Reports in CASHIER sidebar, not primary nav) | Phase 2 complete |
| P2 | STORY-05.4.6 (Export to CSV/PDF/accounting) | Reports surfacing done |

#### Phase 3F — STOCK (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-06.1.3..4 (Stock levels + service linking) | Phase 2 complete |
| P0 | STORY-06.5.1..2 (Low stock alerts + Action Feed) | Stock levels done |
| P1 | STORY-06.2.2..4 (Stock take + transfer) | Stock levels done |
| P2 | STORY-06.3.1..4 (Purchase Orders) | Suppliers done |
| P3 | STORY-06.5.3..6 (Stock reports) | PO done |

#### Phase 3G — SETTINGS (MVP)

| Priority | Story | Dependency |
|----------|-------|------------|
| P0 | STORY-07.3.9 (Services & Pricing nav — Phase 2 task) | Phase 2 |
| P0 | STORY-07.4.4..5 (Permission → home map) | Phase 2 complete |
| P1 | STORY-07.5.6..7 (Booking templates + buffer time) | Phase 2 complete |
| P1 | STORY-07.6.1..3 (Notification templates) | Phase 2 complete |
| P2 | STORY-07.7.1..5 (Integrations) | Phase 3 modules done |
| P3 | STORY-07.10.1..3 (Audit Log) | Phase 3 complete |

---

### PHASE 4 — Polish, Consistency & Role Refinement

**Status: DEFERRED (after Phase 3 EPICs reach MVP)**

| Task ID | Task | Absorbed From |
|---------|------|--------------|
| FEAT-P4.1 | Breadcrumb component pass: standardize plan vs record vs client vs org wording across all 7 modules | Old Task 9.1 |
| FEAT-P4.2 | Tab labels pass: client workspace tabs, wizard steps, SETTINGS sub-sections | Old Task 9.2 |
| FEAT-P4.3 | Empty states + dead ends: remove misleading CTAs across all modules | Old Task 10.1 |
| FEAT-P4.4 | Final verifier sweep: extend read-only lane scripts for 7-module IA structure; all exit 0 | Old Task 10.2 |
| FEAT-P4.5 | Before & After Photo Gallery (clinic use case) | New requirement |
| FEAT-P4.6 | Mobile responsiveness pass across all 7 module primary views | New requirement |

---

## §5 — ROLE-BASED ACCESS QUICK REFERENCE

| Module | OWNER | MANAGER | RECEPTIONIST | STYLIST/THERAPIST |
|--------|-------|---------|--------------|-------------------|
| HOME | Full + Analytics | Full + Analytics | My Day only | My Day only |
| CALENDAR | All staff | All staff | All staff | Own column only |
| CLIENTS | Full + Marketing | Full + Marketing | View + Edit + Book | View notes only |
| TEAM | Full | Full | Hidden | Own profile only |
| CASHIER | Full + Reports | Full + Reports | Full POS | Hidden |
| STOCK | Full | Full | View only | Hidden |
| SETTINGS | Full | Services & Pricing + some | Hidden | Hidden |

---

## §6 — LIVE EXECUTION LANE (replaces BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md)

**Closed (2026-04-07):** `STORY-03.2.11` — Deep links from **`/clients` index row** and **client profile** (`modules/clients/views/show.php`)
to client-held surfaces with stable **`client_id`** query params:
`/memberships/client-memberships?client_id=…`, `/packages/client-packages?client_id=…`,
`/gift-cards?client_id=…`. Permission gates: `memberships.view`, `packages.view`, `gift_cards.view`.

**Current single live task:** **PHASE 2 navigation restructure** — start with `STORY-07.3.9` (remove Catalog from
primary nav; integrate under SETTINGS > Services & Pricing).

**Done bar (STORY-03.2.11 — met):**
1. Clients **list** row and **client profile** expose working links to membership, package, and gift card **index** surfaces using **`client_id`** (exact filter; not display-name search).
2. Links respect existing permission gates (no 403 regression).
3. `php system/scripts/read-only/verify_story_03_2_11_client_profile_deep_links_01.php` exits `0` (run locally after pull).
4. No route paths or POST contracts changed.

**Next (Phase 2):** `STORY-07.3.9` and remaining §PHASE 2 table rows until Phase 2 exit criteria are met.

---

## §7 — VERIFIER BUNDLE (current + planned)

### Current (inherited, still valid)

```bash
php system/scripts/read-only/verify_business_nav_entry_clarity_safe_lane_02.php
php system/scripts/read-only/verify_catalog_growth_subsection_business_clarity_03.php
php system/scripts/read-only/verify_admin_ia_business_first_truth_01.php
php system/scripts/read-only/verify_story_03_2_11_client_profile_deep_links_01.php
php system/scripts/read-only/verify_story_03_2_12_client_profile_quick_book_01.php
```

### To be created in Phase 2

```
verify_ollira_7module_nav_structure_01.php   — asserts exactly 7 primary nav homes
verify_ollira_role_nav_visibility_01.php     — asserts receptionist sees 4, stylist 3, manager 7
verify_catalog_under_settings_01.php         — asserts /services-resources active state under SETTINGS family
verify_reports_under_cashier_and_home_01.php — asserts no standalone /reports in primary nav
verify_marketing_under_clients_01.php        — asserts /marketing active state under CLIENTS family
```

---

## §8 — DOCUMENT CONTROL

| Role | Canonical File |
|------|---------------|
| **Architecture law (this document)** | `system/docs/OLLIRA-IA-7MODULE-MASTER-BACKLOG-V2-01.md` |
| **Live execution queue** | §6 of this document |
| **Backend kernel + auth** | `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` (separate program) |
| **Task state inventory** | `system/docs/TASK-STATE-MATRIX.md` (backend; not IA) |
| **Superseded IA program** | `system/docs/BUSINESS-IA-CANONICAL-LAW-01.md` (**SUPERSEDED**) |
| **Superseded IA backlog** | `system/docs/BUSINESS-IA-CANONICAL-BACKLOG-01.md` (**SUPERSEDED**) |
| **Superseded IA lock** | `system/docs/BUSINESS-IA-LIVE-EXECUTION-LOCK-01.md` (**SUPERSEDED** — §6 above is the new lock) |

**Conflict resolution:** If any superseded document appears to contradict this document, **this document wins** for all product IA decisions. Backend documents (`FOUNDATION-*`, `TASK-STATE-MATRIX.md`) remain authoritative for their own domain.
