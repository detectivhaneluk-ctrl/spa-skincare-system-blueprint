# Service catalog `description` — consumer adoption matrix (FOUNDATION-86)

Read-only; citations refer to tree state at FOUNDATION-86 (post-FOUNDATION-85 contract).

## Grep completeness: `ServiceListProvider` in `*.php`

| Match kind | Files |
|------------|--------|
| Contract + impl | `system/core/contracts/ServiceListProvider.php`, `system/modules/services-resources/providers/ServiceListProviderImpl.php` |
| Bootstrap | `system/modules/bootstrap/register_services_resources.php`, `register_appointments_online_contracts.php`, `register_sales_public_commerce_memberships_settings.php` |
| Runtime consumers | `AppointmentController.php`, `InvoiceController.php`, `InvoiceService.php`, `AppointmentCheckoutProviderImpl.php` |

**Non-consumer (dual path):** `AvailabilityService` — direct SQL on `services`, no `ServiceListProvider` symbol.

---

## A) Controller → view data flow (provider rows)

| Consumer | Passes `$services` to view | View file(s) |
|----------|----------------------------|--------------|
| `AppointmentController::create` | Yes | `modules/appointments/views/create.php` |
| `AppointmentController::edit` | Yes | `modules/appointments/views/edit.php` |
| `AppointmentController::waitlistPage` | Yes | `modules/appointments/views/waitlist.php` |
| `AppointmentController::waitlistCreate` | Yes | `modules/appointments/views/waitlist-create.php` |
| `AppointmentController::renderCreateForm` / `renderEditForm` | Yes | same as create/edit |
| `InvoiceController::create` / `renderCreateForm` | Yes | `modules/sales/views/invoices/create.php` |
| `InvoiceController::edit` / `renderEditForm` | Yes | `modules/sales/views/invoices/edit.php` |

**Non-view:** `InvoiceService::applyCanonicalTaxRatesForServiceLines` uses `find` for `vat_rate_id` only. **`AppointmentCheckoutProviderImpl::getCheckoutPrefill`** uses `find` for `price`; `service_name` from appointment row.

---

## B) Field usage on `$s` (ServiceListProvider row) in views

### Appointments — `create.php`

- Option label: `htmlspecialchars($s['name'])` + duration — lines 71–72.
- **Keys read:** `id`, `name`, `duration_minutes`.
- **No** `description`, `data-*`, or `title`.

### Appointments — `edit.php`

- Same pattern — lines 118–119.

### Appointments — `waitlist.php`

- `id`, `name` — lines 75–76.

### Appointments — `waitlist-create.php`

- `id`, `name` — lines 39–40.

### Sales — `invoices/create.php`

- Per-line service picker: `value="<?= (int)$s['id'] ?>"`, `data-desc="<?= htmlspecialchars($s['name']) ?>"`, `data-price="<?= (float)($s['price'] ?? 0) ?>"`, label `name` + formatted price — lines 70–71.
- Inline JS: on `.service-select` change, sets **`input[name*="[description]"]`** from **`opt.dataset.desc`** — lines 121–122.

### Sales — `invoices/edit.php`

- Same as create — lines 56–57, 107–108.

---

## C) Semantic separation

| Term | Role |
|------|------|
| Catalog **`description`** | Long text on `services`; on provider row as **`description`** (nullable). |
| Invoice **line** `description` | Form field `items[n][description]`; stored on invoice items; prefilled from **`data-desc`** today. |
| **`data-desc`** | Holds **`$s['name']`**, not catalog **`description`**. |
| Checkout **`service_name`** | From **`AppointmentCheckoutProviderImpl`** `$apt['service_name']` in prefill array — not from `ServiceListProvider`. |

**Proof — checkout prefill** (`AppointmentCheckoutProviderImpl.php`):

- `$serviceName = $apt['service_name'] ?? '';` (appointment row)
- `$svc = $this->serviceList->find(...)` → **`price`** only used from `$svc`.

---

## D) Candidate surfaces — classification

| Surface | Classification | Rationale (code-backed) |
|---------|----------------|-------------------------|
| Invoice service `<option>` + JS | **NEEDS-PRODUCT-DECISION** | `data-desc` is **bound** to **name** and drives **line description**; swapping in catalog **`description`** changes stored line text semantics. |
| Appointments service `<select>` (create/edit/waitlist) | **NOT-A-CANDIDATE** (for “existing unused slot”) | Only **name** (+ duration) in label; **no** secondary slot without **new** markup. |
| Appointments — hypothetical **`title` on `<option>`** | **SAFE-FIRST-ADOPTION** *if* chartered as display-only | Would **not** change POST (`service_id`), `parseInput`, or slot JS (`serviceEl.value` only in `create.php`). **Not** present today — requires implementation charter. |
| Checkout / invoice prefill | **NOT-A-CANDIDATE** for catalog **`description`** | Prefill uses **`service_name`** from appointment + **`price`** from `find`; no description field. |
| `services-resources` admin views | **NOT-A-CANDIDATE** (this matrix scope) | No `ServiceListProvider`; admin already has full catalog CRUD (FOUNDATION-81). |

---

## E) Invoice JS reliance on `data-desc` = name

| Fact | Location |
|------|----------|
| `data-desc` populated with **`$s['name']`** | `create.php` ~71, `edit.php` ~57 |
| Change handler writes **`opt.dataset.desc`** to line **`description`** | `create.php` ~121, `edit.php` ~107 |

**Conclusion:** Current semantics are **name → line description**. Catalog **`description`** adoption via **`data-desc`** replacement is **risky** without an explicit product rule.

---

## F) `AvailabilityService`

| Fact | Location |
|------|----------|
| Direct SQL `FROM services` with `is_active = 1` etc. | e.g. `getServiceTiming` ~117–120 in `AvailabilityService.php` |
| No `ServiceListProvider` | grep |

**Out of scope** for wave-3 first consumer adoption of provider **`description`**.

---

## G) Verdict alignment

Aligns with OPS **FOUNDATION-86**: **DEFER** first adoption until product/charter; **no** unused slot proven on invoice; appointments need **new** UI for visible catalog text beyond the option label.
