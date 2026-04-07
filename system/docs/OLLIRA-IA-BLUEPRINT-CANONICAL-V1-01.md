# Ollira — Information Architecture Blueprint (Canonical V1)

**Document type:** Product IA Reference — frozen design law
**Status:** ACTIVE — canonical reference for all UI/UX decisions
**Program:** `OLLIRA-IA-7MODULE-REBUILD-PROGRAM-V2-01`
**Master backlog:** `system/docs/OLLIRA-IA-7MODULE-MASTER-BACKLOG-V2-01.md`
**Declared:** 2026-04-07

> **How to use this document:**
> This is the *why* and *what* of the Ollira information architecture — the product design rationale.
> For *implementation tasks and execution order*, use the Master Backlog.
> For *backend kernel and tenancy*, use `FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.
> This document is frozen. Do not edit it to reflect implementation shortcuts. It is the target state.

---

## Strategic Foundation: Design Principles

These five laws govern every navigation and layout decision in Ollira. If a proposed change
violates one of these laws, the change requires an explicit architectural exception decision.

| Law | Definition | Implication |
|-----|-----------|-------------|
| **Miller's Law** | Top navigation capped at 7 items. The brain cannot hold more than 7±2 items without cognitive strain. | Hard cap: no 8th primary nav item without an architecture review. |
| **Progressive Disclosure** | Show the minimum needed per task. Advanced options live one layer deeper, never on the surface. | Daily users should never see config screens. Managers should never see empty report tabs. |
| **Role-Contextual Rendering** | The same URL tree renders differently depending on the user's role. Not separate systems — one system, filtered by permission. | One codebase. Permission gates determine what renders, not separate views. |
| **Frequency-First Ordering** | Items used 10× per day appear first. Items used monthly appear last. | Calendar before Stock. Quick Actions before Analytics. POS before Reports. |
| **Zero Dead Ends** | Every action has a logical "next step" suggestion. | A booked appointment offers "Process Deposit." A checked-out client offers "Rebook." A completed service offers "Note formula." |

---

## The Architecture Tree

---

### 1. HOME — Dashboard & Command Center

> **Concept:** Not a passive report. An active command center that tells the business what to do next.

```
1. HOME
├── 1.1  Today's Pulse
│         Live KPI strip: revenue today, bookings today, no-shows, chair/room utilisation %
│
├── 1.2  My Day
│         Role-contextual queue:
│         • Stylist  → their own appointment queue
│         • Manager  → all chairs / lanes summary
│         • Reception → all today's appointments with check-in status
│
├── 1.3  Action Feed
│         Smart alerts, auto-surfaced:
│         "3 clients arriving in 30 min"
│         "Stock of Olaplex running low"
│         "Emma's rebooking rate dropped 12%"
│         "3 online bookings need confirmation"
│
├── 1.4  Quick Actions Bar  [persistent — always visible]
│         [+ New Booking]  [Check In Client]  [Quick Sale]  [+ New Client]
│
├── 1.5  Analytics Panels  [Manager / Owner only — below the fold]
│   ├── 1.5.1  Revenue Overview        (Today / Week / Month / Custom range)
│   ├── 1.5.2  Chair / Room Utilisation Heatmap
│   ├── 1.5.3  Top Services by Revenue
│   ├── 1.5.4  Top Staff by Revenue / Bookings
│   ├── 1.5.5  Retention Rate & Rebooking %
│   ├── 1.5.6  New vs Returning Clients ratio
│   ├── 1.5.7  Cancellation & No-Show Rate
│   └── 1.5.8  Product Sales vs Service Revenue split
│
└── 1.6  Branch Switcher
          Multi-location pill in the top bar (not a page — a persistent context control)
```

---

### 2. CALENDAR — Appointments & Smart Scheduling

> **Concept:** The heart of the system. Where the most daily clicks happen. Must be frictionless, fast, and beautiful.

```
2. CALENDAR
├── 2.1  Views
│   ├── 2.1.1  Day View     — lane per staff member, drag-drop blocks
│   ├── 2.1.2  Week View    — condensed occupancy, good for managers
│   ├── 2.1.3  Month View   — occupancy overview
│   └── 2.1.4  Room / Chair View — for spa/clinic (resources, not people)
│
├── 2.2  Appointment Card  (on click/tap — opens as overlay, not new page)
│   ├── Client name + photo + loyalty tier badge
│   ├── Service(s) booked + assigned staff
│   ├── Booking channel (online / walk-in / phone / app)
│   ├── Status: Confirmed / Arrived / In Progress / Completed / No-Show / Cancelled
│   ├── Deposit status + balance due
│   ├── Client notes + allergies / contraindications flag   [clinic-critical — red icon]
│   └── Quick actions: [Check In] [Start] [Complete] [Reschedule] [Cancel] [Add Service]
│
├── 2.3  New Booking Wizard  (drawer, NOT a new page)
│   ├── Step 1: Client search / quick-add new client inline
│   ├── Step 2: Service selector (search + category filter + duration display)
│   ├── Step 3: Staff preference ("Any available" or specific person)
│   ├── Step 4: Smart slot picker (shows only genuinely available slots)
│   ├── Step 5: Add-ons / package selection
│   ├── Step 6: Deposit collection (card-on-file / payment link / skip)
│   └── Step 7: Confirmation + auto-SMS/email trigger
│
├── 2.4  Waitlist Manager
│   ├── Live waitlist queue per service/staff
│   ├── Auto-notify client when slot opens (configurable ON/OFF)
│   └── One-click: convert waitlist entry → confirmed booking
│
├── 2.5  Online Booking Settings
│         (context link → SETTINGS > Online Booking; breadcrumb preserved)
│
├── 2.6  Recurring Appointments
│   ├── Define recurrence rule (weekly / fortnightly / monthly / custom)
│   └── Bulk-reschedule series / bulk-cancel series
│
└── 2.7  Blocked Time / Break Management
    ├── Staff holidays / lunch / training blocks
    └── Room / equipment maintenance blocks
```

---

### 3. CLIENTS — CRM, Loyalty & Marketing

> **Concept:** Grouped together because in a beauty business, knowing your client IS your marketing.
> The client record IS the campaign. Separating CRM from Marketing creates a constant context-switch.

```
3. CLIENTS
├── 3.1  Client Directory
│   ├── Search / Filter: name, phone, email, tag, loyalty tier, last visit date
│   ├── Smart Segments (auto-calculated):
│   │   "Lapsed 60+ days"  |  "Birthday this month"  |  "High spenders"  |  "At-risk"
│   └── Import / Export (CSV)
│
├── 3.2  Client Profile  (single canonical record)
│   ├── 3.2.1   Overview           — photo, contact, lifetime value, visit count, loyalty points
│   ├── 3.2.2   Appointment History
│   ├── 3.2.3   Purchase History   — services + retail products per visit
│   ├── 3.2.4   Treatment Notes    — SOAP notes (clinics); formula/colour notes (salons)
│   ├── 3.2.5   Consultation Forms — intake forms, consent forms, patch test records
│   ├── 3.2.6   Photos / Before-After  [clinic/aesthetic use]
│   ├── 3.2.7   Preferences        — preferred staff, favourite services, product allergies
│   ├── 3.2.8   Loyalty & Credits  — points balance, active memberships, gift voucher balances
│   ├── 3.2.9   Invoices & Payments — full financial ledger per client
│   ├── 3.2.10  Documents          — signed consents, prescriptions (clinic)
│   └── [Quick Book] button        — always visible on every tab; no dead end
│
├── 3.3  Loyalty Programme
│   ├── Points scheme configuration (earn X pts per £/$ spent)
│   ├── Tier management (Bronze / Silver / Gold / Platinum — customisable names)
│   ├── Rewards catalogue
│   └── Points ledger per client
│
├── 3.4  Memberships & Packages
│   ├── Membership plans (monthly fee → included services)
│   ├── Prepaid packages ("Buy 5 facials, get 1 free")
│   ├── Package usage tracker
│   └── Expiry & renewal management
│
├── 3.5  Gift Vouchers
│   ├── Issue (by amount or by service)
│   ├── Redeem at CASHIER POS
│   └── Voucher validity & balance lookup
│
├── 3.6  Marketing
│   ├── 3.6.1  Campaigns
│   │   ├── Campaign builder (email + SMS, unified)
│   │   ├── Audience: segment-based targeting (from Smart Segments)
│   │   ├── Templates library
│   │   ├── Schedule / send now
│   │   └── Performance report: open rate, click rate, bookings generated
│   │
│   ├── 3.6.2  Automations  (trigger-based, runs silently)
│   │   ├── Appointment reminder (24h before + 2h before)
│   │   ├── Post-visit thank you + review request link
│   │   ├── Rebooking nudge (X days after last visit — configurable)
│   │   ├── Birthday offer (auto-sends N days before birthday)
│   │   ├── Lapsed client win-back (triggered after X days no visit)
│   │   └── Package expiry warning (N days before package expires)
│   │
│   └── 3.6.3  Reviews & Reputation
│       ├── Auto-send review link after appointment completion
│       └── Google / internal review feed
│
└── 3.7  Consultation Forms Manager
    ├── Form builder (drag-drop fields: text, checkbox, signature, photo)
    ├── Assign form to a service type (auto-sent before appointment)
    └── Client submission history
```

---

### 4. TEAM — Staff Management

> **Concept:** Grouped as "Team" not "HR" — it should feel collaborative, not administrative.
> Includes scheduling, commissions, performance, and access role at the staff level.

```
4. TEAM
├── 4.1  Staff Directory
│   ├── Status: Active / Inactive / On Leave
│   ├── Filter by branch, role, speciality
│   └── Quick-add new staff
│
├── 4.2  Staff Profile
│   ├── 4.2.1  Personal Details    — name, photo, contact, role, branch
│   ├── 4.2.2  Services Offered    — which services this person performs (links to SETTINGS > Services)
│   ├── 4.2.3  Working Schedule    — weekly rota, shift hours per day
│   ├── 4.2.4  Time Off & Holidays — request / approve / block on CALENDAR
│   ├── 4.2.5  Commission Rules    — % per service / per product / tiered
│   ├── 4.2.6  Performance         — revenue generated, bookings, avg ticket, retention rate
│   ├── 4.2.7  Payroll Summary     — calculated commissions per period
│   └── 4.2.8  System Access Role  — Receptionist / Stylist / Manager (→ SETTINGS > Roles)
│
├── 4.3  Rota / Schedule Builder
│   ├── Weekly view per staff member
│   ├── Copy previous week
│   ├── Multi-staff conflict detector
│   └── Export to PDF / share with team
│
├── 4.4  Commission Calculator
│   ├── Run period report (custom date range)
│   ├── Per-staff breakdown: services rendered + products sold
│   ├── Override / adjustment with notes
│   └── Mark pay run as Paid (timestamped)
│
├── 4.5  Performance Dashboard
│   ├── Leaderboard (optional — privacy-configurable in SETTINGS)
│   ├── Utilisation rate per staff member
│   └── Rebooking rate per staff member
│
└── 4.6  Internal Communication  [optional enhancement — Phase 4]
    ├── Shift notes / handover notes
    └── Announcements board
```

---

### 5. CASHIER — Point of Sale, Invoicing & Finance

> **Concept:** Named "Cashier" not "Finance" or "POS" — it should feel like a role, not a module.
> The receptionist lands here after every appointment completion.
> POS is used 30+ times per day. Financial reports are used once per week. They must not be mixed at the same depth.

```
5. CASHIER
├── 5.1  POS Terminal
│   ├── Search client → auto-pulls completed appointment(s) for checkout
│   ├── Add services (walk-in additions or add-ons)
│   ├── Add retail products (barcode scan / search)
│   ├── Apply discount: amount / % / voucher code / loyalty points redemption
│   ├── Split payment: card + cash + gift voucher in one transaction
│   ├── Tip allocation: assign to specific staff or split proportionally
│   ├── Print or email receipt
│   └── Post-checkout: [Rebook Now] prompt
│
├── 5.2  Invoices
│   ├── All invoices list (filter: date, client, status, branch)
│   ├── Invoice detail: view / download PDF / resend to client
│   ├── Mark outstanding invoices as paid
│   └── Refund / void invoice
│
├── 5.3  Cash Drawer Management
│   ├── Open shift: enter float amount
│   ├── Cash-in / cash-out entries (with reason)
│   └── Close shift: actual count vs system expected; discrepancy flagged
│
├── 5.4  Daily Takings Report
│   ├── Revenue by payment method (card / cash / voucher / split)
│   ├── Revenue by service category
│   ├── Revenue by staff member
│   └── Discounts & voids log
│
├── 5.5  Financial Reports
│   ├── Revenue report (custom date range)
│   ├── Tax / VAT report
│   ├── Commission payroll report
│   ├── Gift voucher liability report
│   ├── Package & membership revenue
│   └── Export to CSV / PDF / accounting software (Xero, QuickBooks)
│
└── 5.6  Payment Methods & Card Processing
          (context link → SETTINGS > Integrations > Payment Gateway)
```

---

### 6. STOCK — Inventory, Products & Suppliers

> **Concept:** Two distinct use cases in beauty that most systems conflate:
> **Retail products** (sold to clients at the counter) and **professional products**
> (used during services, consumed, tracked for cost control and profitability per treatment).

```
6. STOCK
├── 6.1  Product Catalogue
│   ├── All products list (search, filter by brand / category / type)
│   ├── Product record:
│   │   ├── Name, SKU, brand, category
│   │   ├── Retail price / professional cost price
│   │   ├── Current stock level + location (branch)
│   │   ├── Reorder point + reorder quantity
│   │   ├── Supplier link
│   │   └── Used in services (professional usage tracking)
│   └── Bulk import / export
│
├── 6.2  Stock Control
│   ├── Stock adjustments (receive delivery / manual correction / damage write-off)
│   ├── Stock take (actual count vs system; flag discrepancies)
│   ├── Internal usage log (professional products consumed per service)
│   └── Transfer between branches
│
├── 6.3  Purchase Orders
│   ├── Create PO (select supplier → add products + quantities)
│   ├── PO status: Draft / Sent / Partially Received / Received
│   ├── Receive delivery (auto-updates stock levels)
│   └── PO history
│
├── 6.4  Suppliers
│   ├── Supplier directory (name, contact, lead time, payment terms)
│   └── Supplier's product catalogue link
│
├── 6.5  Alerts & Reports
│   ├── Low stock alert dashboard (also surfaced in HOME Action Feed)
│   ├── Stock valuation report (retail + professional combined)
│   ├── Dead stock report (no movement in X days)
│   ├── Best-selling retail products
│   └── Professional usage cost per service (profitability per treatment)
│
└── 6.6  Service-Product Linking
          Define which professional products are consumed per service, in what quantity.
          Auto-deduct from stock when service is marked complete in CALENDAR.
```

---

### 7. SETTINGS — System Configuration

> **Concept:** Only managers/owners ever come here. Comprehensive but never surfaces to daily users.
> Organised by "what you're configuring" — not by technical function.
> Also the home for service/catalog definitions (absorbed from old Catalog primary nav).

```
7. SETTINGS
├── 7.1  Business Profile
│   ├── Business name, logo, contact details
│   ├── Tax / VAT number, currency, timezone, language
│   └── Business hours (default; overridable per branch)
│
├── 7.2  Branches & Locations
│   ├── Add / edit / archive branches
│   ├── Branch-specific hours, contact, address
│   ├── Resources per branch (chairs, rooms, treatment beds)
│   └── Staff assignment per branch
│
├── 7.3  Services & Pricing  [absorbs old "Catalog" primary nav]
│   ├── Service categories (hierarchy: Category → Sub-category → Service)
│   ├── Service record: name, duration, price, description, category, eligible staff
│   ├── Variable pricing per staff tier (junior / senior / specialist pricing)
│   ├── Duration buffers (setup / cleanup time after each service)
│   └── Service packages / combos
│
├── 7.4  Roles & Permissions
│   ├── Role list: Owner / Manager / Receptionist / Stylist / Therapist / Clinic Staff
│   ├── Permission matrix: module → view / create / edit / delete per role
│   └── Assign role to staff member (links to TEAM > Staff Profile)
│
├── 7.5  Online Booking
│   ├── Enable / disable online booking (per branch)
│   ├── Booking widget settings (embed code / shareable link)
│   ├── Services available online (whitelist)
│   ├── Deposit requirements per service
│   ├── Cancellation / reschedule policy
│   ├── Booking confirmation + reminder message templates
│   └── Buffer time between online bookings
│
├── 7.6  Notifications
│   ├── SMS / Email template editor (per event type)
│   ├── Notification triggers ON / OFF per channel
│   └── Sender name / reply-to configuration
│
├── 7.7  Integrations
│   ├── Payment gateway (Stripe / Square / custom PSP)
│   ├── Accounting (Xero / QuickBooks)
│   ├── Google Calendar sync
│   ├── Marketing (Mailchimp / Klaviyo API key)
│   ├── Review platforms (Google Business, Trustpilot)
│   └── API / Webhooks (developer access)
│
├── 7.8  Consultation & Form Templates  [clinic-critical]
│   ├── Form template builder (admin view; creates the library)
│   └── Assign form templates to service categories (client-facing forms live on Client Profile)
│
├── 7.9  Loyalty & Promotions Config
│   ├── Points earning rules (earn X pts per £/$ spent on services / products)
│   ├── Points redemption rules (X pts = £Y discount)
│   ├── Tier thresholds (Bronze / Silver / Gold / Platinum spend levels)
│   └── Discount / promotion codes
│
└── 7.10 Audit Log
     ├── System event log: who changed what, when, on which record
     └── Filter by user, date, module
```

---

## Role-Based Access — Navigation Rendering Per Role

### Primary Navigation Matrix

```
MODULE           │ OWNER  │ MANAGER │ RECEPTIONIST │ STYLIST/THERAPIST
─────────────────┼────────┼─────────┼──────────────┼──────────────────
HOME             │ FULL   │ FULL    │ MY DAY ONLY  │ MY DAY ONLY
CALENDAR         │ ALL    │ ALL     │ ALL STAFF    │ OWN COLUMN ONLY
CLIENTS          │ FULL   │ FULL    │ VIEW + EDIT  │ VIEW NOTES ONLY
  └─ Marketing   │ FULL   │ FULL    │ HIDDEN       │ HIDDEN
TEAM             │ FULL   │ FULL    │ HIDDEN       │ OWN PROFILE ONLY
CASHIER          │ FULL   │ FULL    │ FULL POS     │ HIDDEN
  └─ Reports     │ FULL   │ FULL    │ HIDDEN       │ HIDDEN
STOCK            │ FULL   │ FULL    │ VIEW ONLY    │ HIDDEN
SETTINGS         │ FULL   │ PARTIAL │ HIDDEN       │ HIDDEN
```

---

### Receptionist View — Optimised for Speed

> The receptionist's entire day is 4 actions: **Book → Check In → Checkout → Rebook.**

Primary navigation renders as:

```
[HOME]   [CALENDAR]   [CLIENTS]   [CASHIER]
```

4 items. No cognitive noise.

**Minimum click paths:**

| Task | Clicks |
|------|--------|
| Book a new appointment | 1 (Quick Action) → 7-step drawer |
| Check in a client | 1 click on appointment card → [Check In] |
| Process payment | Calendar → click appointment → [Checkout] → POS auto-loads services |
| Look up a client record | Clients → Search (1 keystroke shows results) |
| Rebook returning client | Checkout confirmation → [Rebook Now] |
| Add a walk-in retail sale | [Quick Sale] → search products → pay |

---

### Stylist / Therapist View

Primary navigation renders as:

```
[HOME: My Day]   [MY SCHEDULE]   [MY CLIENTS]
```

The stylist sees:
- Their own appointment queue for today
- Client notes, colour formulas, treatment history for each of their clients
- Their own performance stats (earnings, rebooking rate) — configurable ON/OFF
- **Cannot see:** other staff revenue, financial reports, inventory, or settings

---

### Manager View

Full 7-module navigation plus:
- Staff vs target overlays on HOME analytics panels
- Payroll summary in TEAM
- Low stock alerts in HOME Action Feed
- Marketing campaign performance in CLIENTS > Marketing
- All financial reports in CASHIER

---

## First Impression Strategy — Dashboard for First-Time Users

### The "Empty State" Onboarding Flow

When a new account is created, the Dashboard does **not** show a wall of zeros. Instead:

```
┌─────────────────────────────────────────────────────────┐
│  Setting up Ollira — 5 steps to go live                 │
│                                                         │
│  [✓] Add your first branch                              │
│  [ ] Add your services         →  [Go to Services]      │
│  [ ] Add your team             →  [Go to Team]          │
│  [ ] Set your schedule         →  [Go to Schedule]      │
│  [ ] Take your first booking   →  [Go to Calendar]      │
│                                                         │
│  Progress: ██░░░░  1/5  —  [Dismiss after completion]   │
└─────────────────────────────────────────────────────────┘
```

Each [Go] button deep-links directly to the relevant screen. No hunting.

---

### The Dashboard Layout — Day One (First-Time User with Data)

```
┌──────────────────────────────────────────────────────────────────┐
│  Good morning, Sarah.   Tuesday 7 Apr  ·  Branch: Mayfair  [▼]  │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐        │
│  │    12    │  │  £1,840  │  │    2     │  │   87%    │        │
│  │ Appts    │  │ Revenue  │  │ No-shows │  │Occupancy │        │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘        │
│                                                                  │
│  ACTION FEED                         TODAY'S CALENDAR           │
│  ─────────────────────────           ──────────────────         │
│  ⚠  2 clients arriving in 10 min     [Mini calendar grid        │
│  🔔 Olaplex stock below reorder       showing next 3 hrs         │
│  💡 Emma's rebooking rate –12%        with staff lanes]          │
│  ✅ 3 online bookings need confirm                                │
│                                                                  │
│  QUICK ACTIONS                                                   │
│  [+ New Booking] [Check In] [Quick Sale] [+ New Client]          │
│                                                                  │
│  ── Analytics (Manager view — below fold) ──────────────        │
│  [Revenue Overview ▼]  [Utilisation ▼]  [Staff Performance ▼]  │
└──────────────────────────────────────────────────────────────────┘
```

**Why this layout works:**

| Element | Business purpose |
|---------|-----------------|
| 4 KPI cards | Answers the only 4 questions every manager has walking into work |
| Action Feed | Replaces the need to check 5 different screens |
| Mini calendar | Immediate visual of how full the day is without opening CALENDAR |
| Quick Actions | Receptionist never needs to look at the nav menu |
| Analytics below fold | Complex data is available but never overwhelming on first load |

---

## Business Logic Behind the Groupings

| Grouping Decision | Rationale |
|-------------------|-----------|
| **Marketing lives inside CLIENTS, not standalone** | In beauty, every campaign is client-segment-based. You cannot market without the CRM. Separating them creates a constant context-switch — you build a segment in Clients, then jump to Marketing to use it. |
| **CASHIER is separate from Finance/Reports** | The POS is used 30+ times a day by the receptionist. Financial reports are used once a week by the manager. Merging them punishes the daily user with complexity they never need. |
| **Online Booking settings live in SETTINGS, not CALENDAR** | The Calendar is for operations. Booking settings are configuration. A receptionist should never accidentally change deposit rules while scheduling. |
| **TEAM is not called "HR"** | HR implies contracts, compliance, and payroll administration. "Team" implies collaboration, scheduling, and daily delivery. The psychology of the label matters for adoption. |
| **STOCK has a Service-Product Linking section** | This is the key business insight most systems miss. Professional product costs must be linked to services to calculate true profitability per treatment. Without this, the P&L is fictitious. |
| **Consultation Forms span CLIENTS and SETTINGS** | The form builder is in SETTINGS (configured once by a manager). The filled forms are in the Client Profile (accessed daily by therapists). Same data — two contextually correct entry points. No duplication. |
| **Reports live inside HOME (analytics) and CASHIER (financial), not as a standalone module** | Reports are not a destination. They are answers to questions that arise inside operational contexts. Revenue questions arise in CASHIER. Performance questions arise in HOME. A standalone Reports module creates a dead-end nav item visited once a week. |
| **SETTINGS absorbs Catalog (Services & Pricing)** | Service definitions are configuration, not daily operations. A receptionist never needs to create a service. Surfacing it as a primary nav home creates confusion between "browsing what we offer" and "configuring what exists." |

---

## Industry-Critical Modules Added (Beyond Original 8)

The following modules were proactively added based on beauty industry best practices
and are not optional for a serious salon/spa/clinic system:

| Module | Why It Is Critical |
|--------|-------------------|
| **Waitlist Manager** | High-demand salons routinely fill every slot. A live waitlist that auto-notifies when a cancellation occurs is a direct, zero-effort revenue recovery tool. |
| **Treatment Notes / SOAP Notes** | Aesthetic clinics legally require documented treatment records for every procedure. Non-compliance is a liability exposure. |
| **Consultation & Consent Forms** | Patch tests, allergy declarations, medical contraindication forms — legally mandatory for many treatments. Wet signatures are unacceptable at scale. |
| **Package & Membership Management** | Recurring revenue model. The fastest-growing revenue stream in premium beauty. Prevents the "one-visit client" problem. |
| **Gift Vouchers** | Consistently the #1 retail upsell in salons, especially Christmas, Valentine's Day, Mother's Day. Must integrate with POS. |
| **Resource / Room Management** | Spas and clinics book treatment rooms, beds, and equipment — not just staff. The calendar engine must model both resources and people simultaneously. |
| **Cash Drawer / Shift Management** | End-of-day reconciliation is a daily operational pain point in any multi-staff location with cash transactions. |
| **Audit Log** | GDPR compliance + internal fraud prevention. "Who deleted that invoice?" must have a traceable answer. |
| **Professional Product Usage Tracking** | Tracks which products are consumed during services and in what quantity. Without this, cost-of-goods is guesswork and profitability per service is unknown. |
| **Before & After Photo Gallery** | Standard of care in aesthetic clinics and advanced hair salons. Client-owned photos, stored securely on their profile, linked to the treatment date. |
| **Commission Calculator with Payroll Export** | Commission structures in beauty are complex: tiered %, different rates for services vs products, adjustments. Automating this eliminates 4–8 hours of manual spreadsheet work per pay run. |

---

## Final Architecture Summary — The One-Page View

```
OLLIRA
│
├── 1. HOME
│         Dashboard · KPI Strip · Action Feed · Quick Actions · Analytics (Manager)
│
├── 2. CALENDAR
│         Day / Week / Month / Room View · New Booking Wizard · Waitlist · Recurring
│
├── 3. CLIENTS
│         Directory · Profile · Loyalty · Memberships · Vouchers · Marketing · Forms
│
├── 4. TEAM
│         Directory · Profile · Rota · Commissions · Performance
│
├── 5. CASHIER
│         POS Terminal · Invoices · Cash Drawer · Daily Takings · Financial Reports
│
├── 6. STOCK
│         Products · Stock Control · Purchase Orders · Suppliers · Alerts
│
└── 7. SETTINGS
          Business · Branches · Services & Pricing · Roles · Online Booking ·
          Notifications · Integrations · Forms · Loyalty Config · Audit Log
```

**7 top-level items.**
Every feature has exactly one logical home.
No feature is buried more than 3 clicks from the surface.
Daily users (receptionist, stylist) see 3–4 items.
Power users (manager, owner) see all 7.
The system grows with the business — it does not overwhelm it from day one.

> *"I've never seen a system this clean."* — The first impression this architecture is designed to create.
