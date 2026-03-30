# SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01 — Fieldset and contract closure (FOUNDATION-80)

**Program:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-80`  
**Scope:** Read-only closure of the **first implementation wave** fieldset and **contract boundary** for **§5.C Phase 3.2** — **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01`** — **after** **FOUNDATION-79**.  
**Verdict:** **A** (waivers **W-80-1–W-80-3** below).  
**Contradiction check:** No statement below contradicts **FOUNDATION-64**, **FOUNDATION-68**, **FOUNDATION-72**, **FOUNDATION-78**, or **FOUNDATION-79**; this doc does not reopen organization-context SQL, HTTP error classification, or repository-scope enforcement — it only bounds catalog/schema/contract work.

---

## 1. FOUNDATION-79 truth remains valid; it does not freeze a first-wave fieldset

**Code/doc evidence:**

- **FOUNDATION-79** §**9** recommends a **narrow schema + model implementation program** with a **“product-approved field list”** and coordinated updates across **`ServiceRepository`**, forms, **`ServiceListProvider`**, **`AvailabilityService`**, **`InvoiceService`**, etc. — it **does not** enumerate a **single** frozen column set or wave-1 vs wave-2 split.
- **FOUNDATION-79** §**7** (“Thin / incomplete vs roadmap §5.C 3.2”) lists **categories of gaps** (visibility, content, pricing, linkage) — **not** a committed first-wave DDL list.

**Conclusion (item 1):** **FOUNDATION-79** remains the authoritative **inventory**; **first-wave field selection** is **out of scope** for F-79 and is the **subject of F-80** (this document).

---

## 2. Candidate rich-model additions — roadmap / product direction, grouped by risk

**Primary roadmap text (§5.C row **3.2**):** evolve **`services`** with **visibility/status**, **pricing/duration/buffer** as needed, **branch/staff applicability hooks**, **richer content fields**, **future product linkage points** — **one coherent model pass** (`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C table).

**§5.A “Planned expansion”** (same file): hierarchical service categories, **richer service catalog fields**, brand/product taxonomy, **unified native catalog domain**, mixed invoices, storefront — **not** WooCommerce as authority.

| Risk group | Direction implied by roadmap / F-79 | Risk note (from current code shape) |
|------------|----------------------------------------|-------------------------------------|
| **Content / display / meta** | “Richer content fields” | **Low** for **additive nullable** columns **not** referenced by **`AvailabilityService`** SQL (see §5.2). |
| **Visibility / status flags** | “Visibility/status”, F-79 **W-79-3** (`is_active` not on **`ServiceListProvider`**) | **High** — any new “bookable / online / channel” flag eventually needs **public booking**, **`AvailabilityService`**, and/or **list/filter** parity. |
| **Pricing structure** | “Pricing … as needed”, mixed sales **Phase 4** | **High / blocked** — **`InvoiceService`** line math, **`AppointmentCheckoutProviderImpl`** use **`price`** from **`ServiceListProvider::find`**; tiered/deposit pricing touches **sales** domain. |
| **Branch / staff applicability refinements** | “Hooks”, existing **`service_staff`** / **`service_staff_groups`** | **Medium** — junction + eligibility already exist; **refinements** (new rules, matrices) expand **appointments** + **availability** behavior. |
| **Product linkage hooks** | “Future product linkage”, §5.C **3.3 → 3.4** sequencing | **Blocked for early waves** — roadmap sequences **product** taxonomy / unified catalog **after** **3.2**; FK or cross-sell hooks are **cross-domain**. |

---

## 3. Exact single first-wave fieldset recommended (safest now)

**Wave 1 fieldset (exactly one additive catalog column + admin persistence):**

| Column | Type | Semantics |
|--------|------|-----------|
| **`description`** | **`TEXT NULL`** | Staff-facing **long description** for the service (internal catalog copy). **Not** used by booking duration, slot math, invoice line pricing, or VAT recomputation in current code. |

**Rationale:** **`AvailabilityService::getServiceTiming`** selects only  
`id, duration_minutes, buffer_before_minutes, buffer_after_minutes, branch_id`  
(```117:121:system/modules/appointments/services/AvailabilityService.php```).  
**`getActiveService`** selects `id, duration_minutes, branch_id` only (F-79). Adding **`description`** does not alter those **SELECT** lists unless a future wave **explicitly** extends them — so **wave 1** can stay **out of booking runtime**.

**One recommended mode for wave 1:** **schema + admin CRUD only** — migration + **`ServiceRepository::normalize`** + **`ServiceController` `parseInput` / `validate`** + **create / edit / show** views — **without** changing **`Core\Contracts\ServiceListProvider`**, **`ServiceListProviderImpl`**, **`AvailabilityService`**, **`InvoiceService`**, or **`AppointmentCheckoutProviderImpl`** (see §6–§8).

---

## 4. Candidate fields / changes — classification

| Candidate | Wave 1 | Deferred | Blocked by downstream contract risk |
|-----------|--------|----------|-------------------------------------|
| **`description` TEXT NULL** (above) | **✓ in** | — | — |
| Public / marketing title, slug, SEO meta | — | ✓ (wave 2+) | Partially **blocked** until public/storefront consumers exist |
| Extra **visibility** flags (e.g. online-only, staff-only) | — | ✓ | **Blocked** — **`PublicBookingService`**, **`AvailabilityService`**, **`ServiceListProvider`** alignment (F-79 §8) |
| **Pricing** tiers, deposits, branch price overrides | — | ✓ | **Blocked** — **`InvoiceService`**, checkout prefill, line totals |
| **Duration / buffer** semantics change | — | ✓ | **Blocked** — **`AvailabilityService`**, **`AppointmentService`** |
| **`vat_rate_id`** / tax behavior change | — | ✓ | **Blocked** — **`InvoiceService::applyCanonicalTaxRatesForServiceLines`**, **`VatRateService`** |
| **Product FK** / bundle linkage | — | ✓ | **Blocked** — **§5.C 3.3–3.4**, products domain |
| **`is_active` / bookable** exposed on **`ServiceListProvider`** | — | ✓ | **Medium/high** — **appointments** dropdown vs **runtime** gate (F-79 **W-79-3**) |
| Payroll/report-only dimensions | — | ✓ | N/A — **payroll** uses **`service_id`** + **`category_id`** join today; new columns optional later |

---

## 5. Contract surfaces — what wave 1 touches vs what it must not (per recommended fieldset)

### 5.1 **`ServiceRepository`**

- **`normalize()`** whitelist today (```95:98:system/modules/services-resources/repositories/ServiceRepository.php```):  
  `category_id`, `name`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `price`, `vat_rate_id`, `is_active`, `branch_id`, `created_by`, `updated_by`.  
- **Wave 1 (recommended):** add **`description`** to whitelist; **`find`/`list`** already use **`s.*`** — new column **flows** to arrays **without** changing SQL shape beyond migration.

### 5.2 **`ServiceController` / views**

- **`parseInput` / `validate`** must accept optional body text (length bound in validate).  
- **create / edit / show** — add field; **index** table optional (not required for closure).

### 5.3 **`ServiceListProvider` (interface + impl)**

- **Wave 1 (recommended):** **no file changes** — PHPDoc shape stays  
  `id`, `name`, `duration_minutes`, `price`, `vat_rate_id`, `category_id`, `category_name`  
  (```14:39:system/core/Contracts/ServiceListProvider.php```).  
- **Deferred:** optional **`description`** (or excerpt) on contract when **appointments / invoices / unified catalog** need it.

### 5.4 **`AvailabilityService`**

- **Wave 1 (recommended):** **no changes** — timing SQL unchanged (§3).

### 5.5 **`AppointmentCheckoutProviderImpl`**

- **Wave 1 (recommended):** **no changes** — uses **`serviceList->find`** for **`price`** only (```30:32:system/modules/appointments/providers/AppointmentCheckoutProviderImpl.php```).

### 5.6 **`InvoiceService`**

- **Wave 1 (recommended):** **no changes** — service lines use **`ServiceListProvider::find`** for **`vat_rate_id`** / tax path (F-79); **`description`** does not participate.

### 5.7 **Payroll / reports / documents (confirm risk)**

- **`PayrollService`:** join uses **`services.category_id`** for rules — **unchanged** by **`description`**.  
- **`ReportRepository`:** no direct **`services`** dependency in F-79 sample — **unchanged**.  
- **Documents / `service_required_consents`:** keyed by **`service_id`** — **unchanged**.

---

## 6. Wave 1 shape: schema + admin CRUD only vs alternatives

| Option | Fits recommended **`description`** wave? |
|--------|------------------------------------------|
| **Schema + admin CRUD only** | **Yes** — minimal blast radius. |
| **Schema + provider contract updates** | **No** for **wave 1** (optional **wave 2** when consumers need **`description`** on **`ServiceListProvider`**). |
| **Schema-only groundwork** (column added, **no** admin UI) | **Possible** but **rejects** “rich catalog” user value; **not** recommended as **closure** default. |

**Answer (item 6):** For the **closed** first wave: **schema + admin CRUD only** (plus **`full_project_schema.sql`** / migration hygiene in the **implementation** task, not in this audit).

---

## 7. Minimal implementation boundary the next wave must obey

1. **One** additive nullable **`TEXT`** column (**`description`**) on **`services`**, plus **`024`-style** index only if product requires full-text later — **not** required for closure.  
2. **`ServiceRepository::normalize`** includes **`description`**; **`create`/`update`** persist it.  
3. **`ServiceController`** + **views** expose it; validation prevents pathological size if product sets a max length in implementation.  
4. **No** changes to **`ServiceListProvider`**, **`AvailabilityService`**, **`AppointmentCheckoutProviderImpl`**, **`InvoiceService`**, **payroll SQL**, **public booking**, **reports**, **organization-context** programs — unless a **separate** chartered task reopens them.  
5. **FOUNDATION-79** downstream list remains the checklist for **later** waves that **do** touch contracts or booking.

---

## 8. Surfaces that must remain untouched in that implementation wave

- **`system/core/Contracts/ServiceListProvider.php`**
- **`system/modules/services-resources/providers/ServiceListProviderImpl.php`** (for **wave 1** as closed here)
- **`system/modules/appointments/services/AvailabilityService.php`**
- **`system/modules/appointments/providers/AppointmentCheckoutProviderImpl.php`**
- **`system/modules/sales/services/InvoiceService.php`** (service-line VAT / price behavior)
- **Payroll** commission calculation SQL, **report** queries, **public booking** services/controllers, **organization** registry / scope / assert programs — **unless** a future task explicitly includes them.

**Note:** **`CatalogSellableReadModelProviderImpl`** reads **`ServiceRepository::list`** — if **`description`** is in **`s.*`**, the merged read model could **ignore** it until a later UX/API wave (**W-80-2**).

---

## 9. Waivers / risks

| ID | Waiver / risk |
|----|----------------|
| W-80-1 | **Product naming:** **`description`** vs split **`internal_notes` / **`client_facing_description`** is **product** choice; **boundary class** (nullable **TEXT**, no booking math) is unchanged. |
| W-80-2 | **Unified catalog / sellable slice:** **`CatalogSellableReadModelProviderImpl`** may **omit** **`description`** until a **later** wave — no obligation to expose in wave 1. |
| W-80-3 | **FOUNDATION-79** §**9** suggested a **single** implementation program **could** touch **`ServiceListProvider`** and **`AvailabilityService`** — **F-80** **narrows** the **first** wave to **avoid** those files for **`description`**; **not** a contradiction — **phased** execution. |

---

## 10. Recommended next step (implementation, not executed here)

Execute **one** implementation task: migration + **`services-resources`** CRUD for **`description`** per §§3–8; then **separate** task for **`ServiceListProvider`** / consumer propagation if product needs **`description`** outside admin.

**Do not open FOUNDATION-81** here (per brief).

---

## 11. Verdict rationale

**A:** F-79 does not freeze a fieldset (§1); roadmap candidates are grouped (§2); **one** minimal **TEXT** column + **admin-only** wave is **code-consistent** with **`AvailabilityService`** SELECT lists; contract **non-touch** list is explicit (§5–§8); waivers are **non-blocking** for closure.
