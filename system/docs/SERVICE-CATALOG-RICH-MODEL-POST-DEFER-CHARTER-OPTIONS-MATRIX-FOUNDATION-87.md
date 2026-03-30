# Post-defer charter options — decision matrix (FOUNDATION-87)

Read-only; compares **A / B / C** only after FOUNDATION-86. Code cites are to current tree.

## Charter definitions

| ID | Charter | Intended first wave (conceptual) |
|----|---------|----------------------------------|
| **A** | Invoice additive metadata | Keep **`data-desc`** = **name**; optionally add **separate** attribute for `$s['description']`; **no** JS / **no** line-description behavior change in wave 1 |
| **B** | Appointment display-only | **Markup-only** on service `<option>` (e.g. `title`); **no** POST / controller / pricing / checkout / VAT / availability logic change |
| **C** | Full stop | Admin + contract only; **no** staff consumer UI for catalog **`description`** until re-chartered |

---

## Code-backed facts (shared)

| Fact | Evidence |
|------|------------|
| Invoice **`data-desc`** = **`$s['name']`** | `modules/sales/views/invoices/create.php` (~71), `edit.php` (~57) |
| JS copies **`dataset.desc`** → line **`description`** | `create.php` (~121), `edit.php` (~107) |
| `parseInput` uses **`find`** for **`duration_minutes`** only | `AppointmentController.php` (~819–824) |
| Slot JS uses **`serviceEl.value`** only | `modules/appointments/views/create.php` (~144–156) |
| Checkout prefill: **`service_name`** from appointment; **`price`** from **`find`** | `AppointmentCheckoutProviderImpl.php` (~27–39) |
| Contract exposes **`description`** on rows | `system/core/contracts/ServiceListProvider.php` |
| **`AvailabilityService`** not a `ServiceListProvider` consumer | Direct SQL in `AvailabilityService.php`; grep |

---

## Gate matrix (ranked for “safest next charter”)

Legend: **●** = favorable, **◐** = conditional, **○** = least favorable for “safest next” in that row.

| Gate | A — Invoice additive | B — Appointment display-only | C — Full stop |
|------|------------------------|------------------------------|---------------|
| **Blast radius** | **◐** 2 views (`invoices/create`, `invoices/edit`); sales domain | **◐** 4 views (appointment + waitlist); no money | **●** 0 consumer UI files |
| **Semantic safety** (stored invoice lines) | **◐** safe **if** wave 1 adds attr only, **no** JS | **●** no invoice line fields touched | **●** none touched |
| **Consumer coupling** | Invoice UI + eventual JS / product rules for new attr | Appointments views only | Contract + admin only |
| **Markup / JS changes** | Wave 1: markup-only possible; **0** JS = safest | Wave 1: markup-only | **●** none |
| **Risk: financial line text semantics** | **◐** low if charter locks wave 1; confusion near “Description” column | **●** none (booking UI) | **●** none |
| **Risk: user-facing ambiguity** (name vs catalog vs line text) | **○** same form as **line Description** | **●** separate module / mental model | **●** no new ambiguity |

---

## A — Smallest safe shape (proof)

| Requirement | Satisfied in principle |
|-------------|------------------------|
| Keep **`data-desc`** = service **name** | Current markup uses **`$s['name']`** — must remain for existing JS contract |
| Separate additive key for catalog **`description`** | No attribute today; additive `data-*` can carry `$s['description']` without replacing **`data-desc`** |
| No line-description behavior change in first wave | Current JS only reads **`dataset.desc`** and **`dataset.price`** — do not add writes from new attr without a new charter |

---

## B — Smallest safe shape + exact markup surfaces

| Requirement | Satisfied |
|-------------|-----------|
| Display-only | Option attributes / non-form supplementary text do not alter **`service_id`** POST |
| No booking logic change | **`parseInput`** does not read option metadata |
| No pricing / VAT / duration / checkout change | Checkout prefill and VAT paths unchanged by appointment view-only attributes |

**Surfaces requiring new markup (service `<option>` loops):**

1. `system/modules/appointments/views/create.php` — `foreach ($services as $s)` under `select#service_id`
2. `system/modules/appointments/views/edit.php` — same
3. `system/modules/appointments/views/waitlist.php` — `foreach (($services ?? []) as $s)` under waitlist filter select
4. `system/modules/appointments/views/waitlist-create.php` — `foreach ($services as $s)` under `select#service_id`

---

## C — Value of stopping the track

| Argument | Basis |
|----------|--------|
| Data + API readiness | **FOUNDATION-85** exposes **`description`**; **FOUNDATION-81** admin persists it |
| No correctness gap | F-86: absence of consumer UI does not break flows |
| Deferral is coherent | Avoids partial UX (e.g. tooltips only in some browsers) until product prioritizes |

---

## Ranking (this audit)

1. **B** — Best balance of **staff value** and **isolation** from invoice / financial copy semantics.  
2. **C** — Preferred if **no** staff-facing requirement.  
3. **A** — Valid **second** charter when invoice-side metadata plumbing is the priority and wave-1 **no-JS** rule is enforceable.

**Single OPS recommendation:** **B** (see primary OPS doc).
