# FOUNDATION-87 — Post-defer next charter selection (service catalog `description` track)

**Program:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-POST-DEFER-NEXT-CHARTER-SELECTION-TRUTH-AUDIT`  
**Mode:** Read-only charter selection. No implementation.  
**Inputs:** FOUNDATION-86 defer verdict + matrix; code cites below.

**Decision matrix:** `SERVICE-CATALOG-RICH-MODEL-POST-DEFER-CHARTER-OPTIONS-MATRIX-FOUNDATION-87.md`.

---

## Verdict: **Charter B — appointment display-only path** (recommended next)

After FOUNDATION-86, the **single safest next charter** for continuing the **service-catalog `description` track** in staff-facing UI is **B: display-only appointment adoption** — optional non-submitting metadata on service `<option>` elements (e.g. `title` with catalog text when non-null), **without** changing booking POST fields, **`parseInput`**, slot-loading JS, checkout prefill, pricing, VAT, or `AvailabilityService`.

**No implementation is opened here** — this document selects the charter only.

**Not selected as next charter:** **A** (invoice additive metadata) is **semantically viable** as a *later* or *alternate* wave but carries **higher user-facing ambiguity** near the invoice **line `description`** field and existing **`data-desc`** naming; **C** (full stop) is **legitimate** if product accepts admin-only catalog text indefinitely.

---

## 1) Charter options evaluated (A / B / C only)

### A — Invoice additive metadata path

**Smallest safe future shape (proof from tree):**

- **Keep `data-desc` semantics exactly as service name** — today `data-desc="<?= htmlspecialchars($s['name']) ?>"` on service options in `modules/sales/views/invoices/create.php` and `edit.php`; inline JS sets line **`description`** from **`opt.dataset.desc`** only.
- **Optionally add a separate additive attribute** for catalog long text (e.g. `data-catalog-description="..."` with `htmlspecialchars`), populated from `$s['description']` when non-null — **not** read by current JS (no `dataset.catalogDescription` usage today).
- **First implementation wave:** markup-only addition + **no** change to the line-description behavior (no JS change that writes catalog text into `items[*][description]`).

**Why this is “safe” only under charter discipline:** Any later JS that maps catalog text into **line** fields requires an explicit product rule; F-86 proved **`data-desc` → line `description`** is **name** today.

### B — Appointment display-only path

**Smallest safe future shape (proof from tree):**

- **Display-only:** augment service `<option>` rows that already iterate `foreach ($services as $s)` — e.g. add `title="..."` when `$s['description']` is non-empty (escaped) — **no** new form fields, **no** change to submitted `service_id`.
- **No booking logic change:** `AppointmentController::parseInput()` uses `ServiceListProvider::find` only for **`duration_minutes`** when auto-calculating `end_at`; it does not read option attributes. Slot loading in `create.php` uses **`serviceEl.value`** (id) only.
- **No pricing / VAT / duration / checkout change:** `AppointmentCheckoutProviderImpl::getCheckoutPrefill` uses `find` for **`price`** and appointment row for **`service_name`** — unchanged by option `title`. `InvoiceService` VAT path uses `find` for **`vat_rate_id`** — appointments views do not participate.
- **Exact surfaces requiring new markup** (service list options only):

  | File | Control / context |
  |------|-------------------|
  | `modules/appointments/views/create.php` | `<select id="service_id" name="service_id">` — options in `foreach ($services as $s)` |
  | `modules/appointments/views/edit.php` | `<select id="service_id" name="service_id">` — same pattern |
  | `modules/appointments/views/waitlist.php` | Filter `<select id="waitlist-service" name="service_id">` — `foreach (($services ?? []) as $s)` |
  | `modules/appointments/views/waitlist-create.php` | `<select id="service_id" name="service_id">` — `foreach ($services as $s)` |

### C — Full stop / defer the track (admin-only)

**Proof of real repo value in stopping:**

- **Schema + admin:** `services.description` exists; **FOUNDATION-81** admin CRUD and **FOUNDATION-85** `ServiceListProvider` contract already expose nullable catalog **`description`** to PHP consumers without any staff booking/invoice UI adoption.
- **Operational closure:** Staff can maintain long copy in **Services** admin; invoices and appointments **function** without showing catalog **`description`** in cross-module UIs — F-86 established there is **no** bug from absence of consumer UI.
- **Value of “stop now”:** Avoids shipping **tooltip/attribute** UX that may be **inconsistent across browsers** for `<option title>` and avoids any perception of prioritizing “catalog marketing text” over higher roadmap items — **legitimate** when product does not need booking-context hints.

**Proof that “continuing the track” is not mandatory:** No runtime failure path depends on staff seeing catalog **`description`** outside admin; the track’s **data plane** is already complete for integration (contract row includes **`description`**).

---

## 2) Gate comparison (A vs B vs C)

See `SERVICE-CATALOG-RICH-MODEL-POST-DEFER-CHARTER-OPTIONS-MATRIX-FOUNDATION-87.md` for the scored table. Summary:

| Gate | Winner |
|------|--------|
| Blast radius (files / domains) | **C** smallest; **A** = 2 invoice views; **B** = 4 appointment views |
| Semantic safety (stored money text) | **B** and **C** maximal; **A** safe **iff** charter forbids JS changes in wave 1 |
| Consumer coupling | **B** = appointments module only for UI; **A** = sales invoice UI + future JS consumers of new attr |
| Markup / JS required | **C** none; **B** markup-only possible; **A** markup-only in safest wave 1 |
| Risk to stored financial line text | **Lowest** for **B**/**C**; **A** low only with strict “no line field change” wave |
| User-facing ambiguity | **Lowest** for **B** (booking context, separate from “invoice line description”); **A** higher (same screen as line **Description** column) |

---

## 3) Single recommendation (only)

**Recommend charter B — appointment display-only path** as the **safest next** charter for the description **track** when product wants **staff-facing** catalog hints without touching **sales** semantics.

**Explicitly not recommending implementation in FOUNDATION-87** — charter the wave under a future task; optional follow-up audit for `title` accessibility/length on `<option>` if needed.

**When to choose C instead:** If product confirms **no** staff booking UX need for catalog long text, **stop the track** at admin + contract — **no further service-description adoption work** until requirements change.

**When to choose A instead:** If the next priority is **invoice** integration (e.g. future automation reading catalog text from DOM/API) and product accepts **strict** wave-1 “additive attribute + no JS” rules — **A** is second-ranked after **B** on **ambiguity** grounds.

---

## 4) Waivers

| ID | Note |
|----|------|
| **W-87-1** | `<option title>` behavior varies by browser/OS; charter may prefer visible secondary copy — **product** choice, not settled here. |
| **W-87-2** | Same static grep limits as F-86 for future consumers. |

---

## 5) Deliverables

- This OPS document.  
- Matrix: `SERVICE-CATALOG-RICH-MODEL-POST-DEFER-CHARTER-OPTIONS-MATRIX-FOUNDATION-87.md`.  
- Roadmap row **FOUNDATION-87**.  
- ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-87-POST-DEFER-NEXT-CHARTER-SELECTION-CHECKPOINT.zip`.

---

## 6) Stop

FOUNDATION-87 ends here; no code or view edits.
