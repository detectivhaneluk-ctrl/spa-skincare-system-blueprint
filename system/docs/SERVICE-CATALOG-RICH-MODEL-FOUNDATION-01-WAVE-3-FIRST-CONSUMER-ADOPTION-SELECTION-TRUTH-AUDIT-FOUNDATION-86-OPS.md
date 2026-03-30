# FOUNDATION-86 — Wave-3 first consumer adoption selection (read-only truth audit)

**Program:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-3-FIRST-CONSUMER-ADOPTION-SELECTION-TRUTH-AUDIT`  
**Mode:** Read-only. No PHP/JS/views/schema/migrations.  
**Baseline:** FOUNDATION-85 optional `description` on `ServiceListProvider` rows — see `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-OPTIONAL-DESCRIPTION-CONTRACT-EXTENSION-IMPLEMENTATION-FOUNDATION-85-OPS.md`.

**Supporting matrix:** `SERVICE-CATALOG-DESCRIPTION-CONSUMER-ADOPTION-MATRIX-FOUNDATION-86.md`.

---

## Verdict: **B — DEFER first UI adoption**

**Grep-complete** `ServiceListProvider` usage in `*.php` matches FOUNDATION-84/85: contract + impl + three bootstrap registrations + four runtime consumers. **No** current HTML/JS surface exposes an **unused** hook that can absorb catalog **`description`** without a **product decision** (invoice) or **new** view markup (appointments). The single recommendation is **STOP / defer** a wave-3 “first consumer” implementation until chartered; **do not** treat invoice `data-desc` or line-item text as interchangeable with catalog long text without an explicit rule.

---

## 1) Inventory: where `ServiceListProvider` rows are used and reach UI or serialization

| Location | Method | Rows passed to views / used |
|----------|--------|-----------------------------|
| `Modules\Appointments\Controllers\AppointmentController` | `list($branchId)` | **`create`**, **`edit`**, **`waitlistPage`**, **`waitlistCreate`**, **`renderCreateForm`**, **`renderEditForm`** → `$services` for appointment/waitlist views |
| `AppointmentController` | `find($serviceId)` | **`parseInput`** only — `duration_minutes` for `end_at` auto-calc; **no** view |
| `Modules\Sales\Controllers\InvoiceController` | `list($branchId)` | **`create`**, **`edit`**, **`renderCreateForm`**, **`renderEditForm`** → `$services` for invoice create/edit views |
| `Modules\Sales\Services\InvoiceService` | `find($sourceId)` | **`applyCanonicalTaxRatesForServiceLines`** — `vat_rate_id` only; **no** view |
| `Modules\Appointments\Providers\AppointmentCheckoutProviderImpl` | `find` | **`getCheckoutPrefill`** — returns JSON-shaped array for invoice prefill; **`price`** from provider; **`service_name`** from **`$apt['service_name']`**, not from provider |

**`system/modules/services-resources/views/**`:** No `ServiceListProvider` references (grep). Admin catalog screens use **`ServiceController`** / **`ServiceRepository`**, not the cross-module list contract.

---

## 2) “Unused slot” for optional catalog description — proof

- **Invoice create/edit:** Service `<option>` elements **use** `data-desc` — it is **not** unused. It is populated with **`$s['name']`** and read by inline JS into the **line item** `description` input on service pick (`opt.dataset.desc`). See `modules/sales/views/invoices/create.php` and `edit.php` (lines cited in matrix).
- **Appointments create/edit/waitlist:** Service options are a **single** visible label: `name` (+ duration where applicable). There is **no** `data-*`, `title`, or secondary line for catalog text today; any adoption **adds** attributes or markup.

**Conclusion:** There is **no** grep-proven **unused** slot already wired for catalog **`description`** on consumer surfaces.

---

## 3) Meaning separation (explicit)

| Concept | Source in tree (this audit) |
|--------|-----------------------------|
| **Service catalog `description`** | `services.description` (admin CRUD); exposed on **`ServiceListProvider`** row as **`description`** (FOUNDATION-85). |
| **Invoice line item `description`** | `items[*][description]` form fields — persisted invoice line text; **not** the same as catalog long text unless UX copies it. |
| **Appointment checkout `service_name`** | **`AppointmentCheckoutProviderImpl::getCheckoutPrefill`**: from **`$apt['service_name']`** (appointment row / join), **not** from `ServiceListProvider::find`. |
| **Invoice UI `data-desc` / `dataset.desc`** | Markup + JS: **`data-desc="<?= htmlspecialchars($s['name']) ?>"`**; change handler sets line **`description`** from **`opt.dataset.desc`**. Semantic is **service name**, not catalog **`description`** (name collision risk documented in FOUNDATION-84 **W-84-2**). |

---

## 4) Candidate surfaces — classification

See matrix. Summary:

- **Invoice service picker (`data-desc` + line description):** **NEEDS-PRODUCT-DECISION** (or **NOT-A-CANDIDATE** for “safe first” until rules exist) — **cannot** replace `data-desc` with catalog long text without changing line-item defaults and user expectation.
- **Appointments service `<select>`:** **NOT-A-CANDIDATE** for zero-markup adoption — no secondary slot; optional **future** display-only tooltip (`title`) would be a **separate** chartered implementation, not an existing slot.
- **Checkout prefill:** Uses **`service_name`** + **`price`**; catalog **`description`** not in path — **NOT-A-CANDIDATE** for description without product scope change.

---

## 5) Invoice create/edit: `data-desc` semantics vs risky adoption

**Proof — markup** (`modules/sales/views/invoices/create.php`):

- `data-desc="<?= htmlspecialchars($s['name']) ?>"` on each service option.
- Inline script: `row.querySelector('input[name*="[description]"]').value = opt.dataset.desc || '';`

**Proof — behavior:** Selecting a service copies **name** into the **invoice line description** field. Replacing **`data-desc`** with catalog **`description`** would prefill long marketing/copy text into **invoice line items**, changing stored invoice semantics and **VAT/tax application** only indirectly through user edits — **high product/UX risk** without a decision (e.g. keep name as default, put catalog text elsewhere, or add a new attribute).

---

## 6) Appointments: secondary text without changing booking logic

**Proof:** `parseInput()` uses **`ServiceListProvider::find`** only for **`duration_minutes`** (`AppointmentController.php`). Slot loading JS uses **`serviceEl.value`** (service id) and date/staff/branch — **not** option `title` or `dataset` (`create.php` script).

**Proof — no existing slot:** Options are built as single-line labels (`name` + duration); **waitlist** views use **`id`** + **`name`** only. There is **no** current secondary text slot; adding catalog **display** (e.g. `title` for accessibility/tooltip) would be **new** UI work, not a discovered unused slot.

---

## 7) `AvailabilityService` scope for “next program”

**Proof:** `Modules\Appointments\Services\AvailabilityService` queries **`services`** directly (e.g. `getServiceTiming` selects `id`, `duration_minutes`, `buffer_*`, `branch_id` with `is_active = 1`). It does **not** reference `ServiceListProvider` (grep: no symbol). **Out of scope** for wave-3 catalog-description adoption; extending it for **`description`** would be a **different** charter (and not required for staff UI adoption).

---

## 8) Single recommendation (only)

**DEFER / STOP** — Do **not** ship a narrow “first consumer” adoption of catalog **`description`** on invoice or appointment surfaces **as the next mandatory step** without:

1. **Product decision** for invoice: whether line **`description`** default on service pick remains **service name**, may include **catalog** long text, or uses **separate** `data-*` keys (e.g. preserve **`data-desc`** as name, add **`data-catalog-description`** for optional future use).
2. **Explicit charter** for any appointment UI that **adds** markup (e.g. tooltips) — **not** implied by this audit as “already safe” because **no** unused slot exists today.

**Optional** lowest-risk **future** implementation pattern (not mandated here): **additive** invoice attributes **without** changing **`data-desc`** or JS until a follow-on audit; **or** display-only **`title`** on appointment options **if** product accepts tooltip/length behavior.

---

## 9) Waivers

| ID | Note |
|----|------|
| **W-86-1** | Static grep completeness for `ServiceListProvider` in `*.php`; dynamic resolution paths outside this audit. |
| **W-86-2** | Browser `title` on `<option>` has inconsistent UX; not a substitute for product copy rules. |

---

## 10) Deliverables

- This OPS document.  
- Matrix: `system/docs/SERVICE-CATALOG-DESCRIPTION-CONSUMER-ADOPTION-MATRIX-FOUNDATION-86.md`.  
- Roadmap row: **`BOOKER-PARITY-MASTER-ROADMAP.md`** (FOUNDATION-86).  
- ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-86-WAVE-3-FIRST-CONSUMER-ADOPTION-SELECTION-CHECKPOINT.zip`.

---

## 11) Stop

FOUNDATION-86 ends here; no implementation wave is opened by this audit.
