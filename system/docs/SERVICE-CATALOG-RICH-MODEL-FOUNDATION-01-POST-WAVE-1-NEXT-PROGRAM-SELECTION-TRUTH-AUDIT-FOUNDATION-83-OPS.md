# SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01 — Post–wave-1 next program selection (FOUNDATION-83)

**Program:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-83`  
**Scope:** Read-only selection of the **single safest next program** in the **§5.C 3.2** / **SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01** lane **after** **FOUNDATION-82**.  
**Verdict:** **A** (waivers **W-83-1–W-83-2**).  
**Contradiction check:** Does not reopen **FOUNDATION-64**, **FOUNDATION-68**, **FOUNDATION-72**, **FOUNDATION-78**, **FOUNDATION-80**, or **FOUNDATION-82** — those remain baselines; this doc only sequences **catalog** work.

---

## 1. FOUNDATION-82 does not choose the next program

**FOUNDATION-82** (`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-1-DESCRIPTION-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-82-OPS.md`) **closes** wave 1: **`089`**, **`full_project_schema`**, **`ServiceRepository`**, **`ServiceController::parseInput`**, views, **no** **`ServiceListProvider`** / downstream **`description`** usage. It **proves closure**; it **does not** charter wave 2 or pick a contract strategy.

---

## 2. Realistic next-program candidates (grouped by risk)

| Group | Examples | Risk (code-backed reason) |
|-------|----------|-----------------------------|
| **A. `ServiceListProvider` contract enrichment** | Add optional **`description`**, **`is_active`**, buffers to PHPDoc + **`ServiceListProviderImpl::mapRow`** | **Medium–high** — contract is consumed by **`AppointmentController`** (many **`list`**), **`InvoiceController`** (**`list`**), **`InvoiceService::applyCanonicalTaxRatesForServiceLines`** (**`find`**), **`AppointmentCheckoutProviderImpl`** (**`find`**). Any shape change needs a **consumer matrix** first (**F-79** §8.1–8.2 dual-path note). |
| **B. Admin-only additional content/meta column** | Second **`TEXT`**, slug, internal code | **Low–medium** for schema/admin **if** same pattern as wave 1 — **but** repeats **admin-vs-contract** split unless paired with **A** or an explicit “admin forever” rule. |
| **C. Visibility / status** | Online-only, bookable flags | **High** — **`AvailabilityService`**, **`PublicBookingService`**, **`ServiceListProvider`** list semantics (**F-79** **W-79-3** inactive rows in lists). |
| **D. Pricing / duration structure** | Deposits, tiers, overrides | **High** — **`InvoiceService`**, **`AppointmentCheckoutProviderImpl`**, line totals (**§5.C Phase 4** mixed sales still downstream). |
| **E. Branch / staff applicability refinements** | New matrices beyond **`service_staff`** / staff groups | **High** — **`AvailabilityService`** eligibility, **`AppointmentService`**. |
| **F. Product linkage groundwork** | FK to **products** | **Blocked** by roadmap **§5.C** ordering **3.2 → 3.3 → 3.4** (`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C dependency row). |
| **G. No implementation yet** | Pause catalog lane | **Low risk**, **zero** progress — acceptable only if product defers (**W-83-1**). |

---

## 3. Exact single recommended next program (safest)

**`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-EXTENSION-READ-ONLY-TRUTH-AUDIT`**

**Nature:** **Read-only truth audit** (docs + grep + matrix **only**). **No** schema, **no** PHP behavior change in the audit wave.

**Purpose:** Before **any** **`Core\Contracts\ServiceListProvider`** / **`ServiceListProviderImpl`** change, produce a **complete callsite inventory** and **risk classification** for extending the contract (e.g. optional **`description`**, or exposing **`is_active`** for dropdown parity). Include explicit **dual-path** note: **`AvailabilityService`** reads **`services`** via **direct SQL** (**F-79** / **F-80**) — contract-only changes **do not** replace that path; the audit should state what must stay aligned if booking semantics later depend on new fields.

---

## 4. Why rejected candidates are riskier or premature

| Candidate | Why wait |
|-----------|----------|
| **Narrow implementation** of **`ServiceListProvider`** + **`description`** first | **Premature** without **documented** consumer impact; **InvoiceService** / **AppointmentController** / checkout paths differ. |
| **Admin-only meta field** (second column) | **Lower** urgency than **closing the contract boundary** for wave-1 **`description`** if product wants cross-module visibility; otherwise duplicates **F-81** pattern without strategy. |
| **Visibility / status program** | Touches **booking runtime** + possibly **public booking** — exceeds **narrow** lane without charter. |
| **Pricing / duration** | Touches **sales** integrity — **Phase 1.5** gate exists; mixed sales **Phase 4** still **open** per roadmap. |
| **Branch/staff refinements** | **Availability** + **appointments** — large blast radius. |
| **Product linkage** | Roadmap **3.3** / **3.4** — **sequential** dependency. |
| **Schema-only groundwork** with no audit | **Wastes** a migration without a **consumer** story. |
| **Documentation-only cleanup** (generic) | Does not reduce **contract** risk specific to **ServiceListProvider**. |

---

## 5. How the next program should begin

**Answer:** **`read-only truth audit`** — the recommended **next program** **is** that audit (**§3**). It is **not** an implementation wave, **not** schema-only, **not** generic doc cleanup.

**“No action yet”** remains valid if product pauses the lane (**W-83-1**).

---

## 6. Minimal boundary the next program must obey

1. **Read-only** — **no** edits to **`system/modules/**`**, **`system/data/migrations/**`**, **`system/routes/**`** except **new docs** under **`system/docs/`** as deliverables.  
2. **Scope:** **`ServiceListProvider`** contract file, **`ServiceListProviderImpl`**, and **every** runtime **`list` / `find`** use (grep-backed). **Include** **`AppointmentController`**, **`InvoiceController`**, **`InvoiceService`**, **`AppointmentCheckoutProviderImpl`**; **reference** **`AvailabilityService`** only for **dual-path** / non-contract reads.  
3. **Output:** OPS + matrix + verdict; optional **append-only** roadmap row for the audit wave id when executed.  
4. **Out of scope for that audit:** payroll/report/public-booking **unless** a callsite references **`ServiceListProvider`** (grep today: **none** in those modules for this contract).

---

## 7. Surfaces that must remain untouched **during** that audit program

All application code, schema, routes — **read-only audit** implies **no** touches. Specifically **must not** change in the audit wave: **`ServiceListProvider`**, **`ServiceListProviderImpl`**, **`AvailabilityService`**, **`InvoiceService`**, **`AppointmentCheckoutProviderImpl`**, **`ServiceRepository`** (except **reading** for doc citations), organization-context programs.

---

## 8. Waivers / risks

| ID | Waiver / risk |
|----|----------------|
| W-83-1 | **Product** may **defer** the **F-83** recommendation and keep **wave 1** as the only shipped slice — **no** contradiction with this audit. |
| W-83-2 | **Dynamic** / indirect **`ServiceListProvider`** resolution is **unlikely** in this codebase (constructor DI only); grep could miss **future** test harnesses — bounded **waiver**. |

---

## 9. Verdict rationale

**A:** **F-82** closure is accepted; **safest** next step is **evidence-first** contract consumer mapping **before** implementation; **rejected** alternatives are **code-justified** by cross-module **`ServiceListProvider`** usage and roadmap **3.3–3.4** ordering.

**Do not open FOUNDATION-84** here (per brief).
