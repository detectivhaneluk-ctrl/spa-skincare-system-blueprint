# Wave 1 fieldset + contract matrix — FOUNDATION-80

Read-only closure after **FOUNDATION-79**. **Recommended wave 1:** **`services.description` `TEXT NULL`** + **admin CRUD**; **no** **`ServiceListProvider`** / **booking** / **invoice** / **payroll** file changes in that wave.

---

## A. FOUNDATION-79 vs first-wave fieldset

| Question | Answer |
|----------|--------|
| Does F-79 enumerate a frozen DDL wave-1 list? | **No** — §**9** says **“product-approved field list”** without column names; §**7** lists gap **categories**. |
| Does F-80 contradict F-79? | **No** — F-80 **narrows** scope for **first** implementation wave. |

---

## B. Roadmap-implied candidates × risk

| Group | Examples | Typical risk |
|-------|----------|----------------|
| Content / meta | Long text, titles, slug | **Low** if not in **`AvailabilityService`** SELECT |
| Visibility / status | Online-only, extra flags | **High** — public booking + lists |
| Pricing | Deposits, tiers, overrides | **High** — **`InvoiceService`**, checkout |
| Branch / staff | New matrices beyond junctions | **Medium** — **availability** + **eligibility** |
| Product linkage | FK to **products** | **Blocked** until **§5.C 3.3–3.4** path |

---

## C. Single closed wave-1 fieldset

| Field | DDL | In `normalize()` | Admin UI | In `ServiceListProvider` | In `AvailabilityService` |
|-------|-----|------------------|----------|----------------------------|---------------------------|
| **`description`** | `TEXT NULL` | **Yes (wave 1 impl)** | **Yes** | **No (wave 1)** | **No** |

---

## D. Classification matrix (candidates)

| Item | Wave 1 | Deferred | Blocked |
|------|--------|----------|---------|
| **`description` TEXT** | ✓ | | |
| Slug / SEO / public title | | ✓ | |
| Online visibility flags | | | ✓ (consumers) |
| Price tiers / deposits | | | ✓ |
| **`ServiceListProvider` exposes `is_active`** | | ✓ | ✓ (F-79 W-79-3) |
| Product FK on **services** | | | ✓ |
| Duration/buffer change | | | ✓ |

---

## E. Contract / consumer touch table (wave 1 = recommended)

| Surface | Change in wave 1? |
|---------|-------------------|
| `022` / new migration / `full_project_schema.sql` | **Yes** (impl) |
| `ServiceRepository::normalize` | **Yes** (impl) |
| `ServiceController` + views | **Yes** (impl) |
| `ServiceService` | **Optional** (audit copy only) |
| `Core\Contracts\ServiceListProvider` | **No** |
| `ServiceListProviderImpl` | **No** |
| `AvailabilityService` | **No** |
| `AppointmentCheckoutProviderImpl` | **No** |
| `InvoiceService` | **No** |
| Payroll / reports / public booking | **No** |

---

## F. Wave shape

**Chosen:** **schema + admin CRUD only** (see OPS §6).

---

## G. Verdict

**A** — see **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-FIELDSET-AND-CONTRACT-CLOSURE-TRUTH-AUDIT-FOUNDATION-80-OPS.md`** — waivers **W-80-1–W-80-3**.
