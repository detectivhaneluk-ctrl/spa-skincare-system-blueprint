# FOUNDATION-84 — ServiceListProvider consumer inventory and contract-extension risk (read-only truth audit)

**Program:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-EXTENSION-READ-ONLY-TRUTH-AUDIT`  
**Scope:** Read-only; no PHP/SQL/schema/routes/controllers/services/UI/pricing/payroll/reports/booking runtime/public booking edits.  
**Charter:** FOUNDATION-83 selected this wave before any `ServiceListProvider` contract change.

---

## Verdict: **A**

**A** — Grep over `*.php` yields a **complete** in-tree `ServiceListProvider` consumer set (four runtime consumers + impl + contract + three bootstrap bindings). Field-level assumptions are provable from cited call sites and views. Residual uncertainty is bounded and listed under waivers.

---

## 1. Exact current `ServiceListProvider` contract shape (`list` / `find`)

**Source:** `system/core/contracts/ServiceListProvider.php`

Both methods document the **same seven named keys** on each row:

| Key | Documented type |
|-----|-----------------|
| `id` | `int` |
| `name` | `string` |
| `duration_minutes` | `int` |
| `price` | `float` |
| `vat_rate_id` | `int\|null` |
| `category_id` | `int\|null` |
| `category_name` | `string\|null` |

- `list(?int $branchId = null): array` — PHPDoc: `list<array{ … }>` (the seven keys).  
- `find(int $id): ?array` — PHPDoc: `array{ … }\|null` (same seven keys) or `null`.

There is **no** documented optional eighth key (e.g. `description`) in the interface today.

---

## 2. Exact current `ServiceListProviderImpl` row mapping / exposed field set

**Source:** `system/modules/services-resources/providers/ServiceListProviderImpl.php`

- `list()` calls `ServiceRepository::list(null, $branchId)` and maps each DB row with `mapRow()`.
- `find()` calls `ServiceRepository::find($id)` and maps with `mapRow()`.

`mapRow()` returns **exactly** these seven keys (no other keys are added):

- `id`, `name`, `duration_minutes`, `price`, `vat_rate_id`, `category_id`, `category_name`.

**Important:** `ServiceRepository::find` / `list` use `SELECT s.*` (so the DB row **includes** `description` after FOUNDATION-81), but **`mapRow()` does not copy `description`** (or buffers, `is_active`, etc.) into the contract array.

---

## 3. DI wiring and every in-tree runtime consumer

### 3.1 Binding

| File | Fact |
|------|------|
| `system/modules/bootstrap/register_services_resources.php` | `Core\Contracts\ServiceListProvider` → `Modules\ServicesResources\Providers\ServiceListProviderImpl` (injects `ServiceRepository`). |

### 3.2 Injectors (constructor receives `ServiceListProvider`)

| File | Injected into |
|------|----------------|
| `system/modules/bootstrap/register_appointments_online_contracts.php` | `AppointmentController`, `AppointmentCheckoutProviderImpl` |
| `system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php` | `InvoiceService`, `InvoiceController` |

### 3.3 Grep-complete consumer list (`*.php`)

**Runtime consumers (call `list` or `find`):**

1. `Modules\Appointments\Controllers\AppointmentController`
2. `Modules\Sales\Controllers\InvoiceController`
3. `Modules\Sales\Services\InvoiceService`
4. `Modules\Appointments\Providers\AppointmentCheckoutProviderImpl`

**No other** `ServiceListProvider` references appear in application `*.php` outside the contract, impl, and the three bootstrap files above.

---

## 4. Per-consumer call sites: method, `list` vs `find`, fields read, optional-field risk

### 4.1 `AppointmentController`

| Location | `list` / `find` | Fields read / assumed | Optional new field |
|----------|-----------------|------------------------|---------------------|
| `create()` | `list($branchId)` | Passes `$services` to view only (see views). | Extra keys ignored by PHP unless views/JS use them. |
| `edit()` | `list($branchId)` | Same. | Same. |
| `waitlistPage()` | `list($branchId)` | Same. | Same. |
| `waitlistCreate()` | `list($branchId)` | Same. | Same. |
| `renderCreateForm()` | `list($branchId)` | Same. | Same. |
| `renderEditForm()` | `list($branchId)` | Same. | Same. |
| `parseInput()` | `find($serviceId)` | **`duration_minutes` only** (for `end_at` auto-calc when `end_time` empty). | New optional fields **harmless** if unused. |

**Views (field usage on each `$s` row):**

- `modules/appointments/views/create.php`, `edit.php`: `id`, `name`, `duration_minutes`.
- `modules/appointments/views/waitlist.php`, `waitlist-create.php`: `id`, `name` (waitlist list page also only needs id/name for filters UI).

**Risk:** Adding an optional **`description`** (or similar) changes **nothing** in current templates/JS unless a later edit **binds** it (currently no reads).

---

### 4.2 `InvoiceController`

| Location | `list` / `find` | Fields read / assumed |
|----------|-----------------|------------------------|
| `create()`, `edit()` (and related paths that load invoice form) | `list($branchId)` or `list($invoice['branch_id'])` | Passes `$services` to `modules/sales/views/invoices/create.php` / `edit.php`. |

**Views:**

- Each option uses: **`id`**, **`name`**, **`price`** (for `value`, `data-desc`, label text, `data-price`).
- Inline script on change: sets line **`description`** input from **`opt.dataset.desc`** (which is **`$s['name']`**, not DB `services.description`) and **`unit_price`** from **`dataset.price`**.

**Optional-field risk:**

- **`description` (catalog long text)** on the service row: **harmless** today (not read). **Human risk:** the HTML attribute is named `data-desc` but holds **name** — future devs might assume it maps to catalog `description`; document in implementation wave.
- Any new numeric field: **ignored** unless JS/HTML is updated.

---

### 4.3 `InvoiceService`

| Location | `list` / `find` | Fields read / assumed |
|----------|-----------------|------------------------|
| `applyCanonicalTaxRatesForServiceLines()` (private) | `find($sourceId)` | **`vat_rate_id` only** (then `VatRateService::getRatePercentById`). |

**Optional-field risk:** Additive optional keys **harmless** for this path.

---

### 4.4 `AppointmentCheckoutProviderImpl`

| Location | `list` / `find` | Fields read / assumed |
|----------|-----------------|------------------------|
| `getCheckoutPrefill()` | `find((int)$apt['service_id'])` | **`price` only** (prefill `service_price`). Appointment name comes from **`$apt['service_name']`**, not from `ServiceListProvider`. |

**Optional-field risk:** Additive optional keys **harmless**.

---

## 5. Strict array shape / order vs named keys

- All consumers use **associative** PHP arrays and **`$row['key']`** (or `??` defaults).  
- **No** evidence of numeric indexing (`$row[0]`) or reliance on **iteration order** of keys for correctness.  
- **List order** of services comes from `ServiceRepository::list` `ORDER BY` (category then name); consumers only **foreach** for UI options — order is **presentation**, not a contract guarantee consumers depend on for logic.

**Conclusion:** Contract is effectively **named-key**; adding new **optional** keys does not break positional assumptions.

---

## 6. Dual-path caveat: `AvailabilityService` vs `ServiceListProvider`

**Not a `ServiceListProvider` consumer:** `Modules\Appointments\Services\AvailabilityService` reads **`services`** via **direct SQL**.

**Proof — `getServiceTiming(int $serviceId)`:**

- SQL selects: `id`, `duration_minutes`, `buffer_before_minutes`, `buffer_after_minutes`, `branch_id`.
- `WHERE` includes **`is_active = 1`** and **`deleted_at IS NULL`**.
- Returns a **different** shape (duration + buffers + branch_id), not the seven-key provider shape.

**Proof — `getActiveService(int $serviceId)` (private):**

- SQL: `id`, `duration_minutes`, `branch_id` with **`is_active = 1`**, **`deleted_at IS NULL`**.

**Meaning for contract extension vs booking:**

- Extending **`ServiceListProvider`** (e.g. optional `description`) **does not** change **`AvailabilityService`** timing SQL or buffer behavior **unless** a **separate** change wires those concerns together.
- **`ServiceListProvider` list/find** uses `ServiceRepository`, whose **`list()`** does **not** filter `is_active` in SQL (inactive services can appear in staff UI dropdowns — see FOUNDATION-79 **W-79-3** class of issue). **`AvailabilityService`** **does** require `is_active = 1` for timing. That **semantic gap** is **pre-existing** and **not** fixed by adding optional contract fields.

---

## 7. Is `services.description` the safest first optional contract-enrichment candidate?

**Yes, with narrow prerequisites:**

- **DB + admin:** FOUNDATION-81 already persists `services.description`; repository rows include it on `find`/`list`.
- **Consumers:** None of the four consumers read a catalog **`description`** from `ServiceListProvider` today; all would **ignore** an optional key until UI/product opts in.
- **Caveat:** Invoice UI **`data-desc`** carries **service name**, not long description — naming/documentation should avoid implying **`description`** replaces line-item text without an explicit product decision.

**Prerequisite work:** Not a **schema** prerequisite. Optional **PHPDoc** update on the interface + **`mapRow`** mapping is sufficient for a **narrow** implementation wave; **UI** can remain unchanged in the first implementation if desired.

---

## 8–9. Next justified follow-up and exactly one recommended next program

| Option | Fit |
|--------|-----|
| No implementation yet | **Superseded** — this audit (FOUNDATION-84) is complete; FOUNDATION-83 already deferred implementation until after consumer matrix. |
| One narrow `ServiceListProvider` contract-extension implementation | **Recommended** — evidence shows optional **`description`** (and PHPDoc alignment) is low blast radius; all current consumers use named keys and ignore unknown keys. |
| Prerequisite cleanup/documentation only | **Lower value alone** — consumer matrix is now explicit; doc-only does not expose `description` on the contract. |
| Broader downstream alignment audit | **Defer** — `is_active` list parity vs `AvailabilityService` is a **separate** semantic/product decision (not required to ship optional `description` on the provider). |

**Exactly one recommended next program:**  
**`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-OPTIONAL-DESCRIPTION-CONTRACT-EXTENSION-IMPLEMENTATION`** (narrow: interface PHPDoc + `ServiceListProviderImpl::mapRow` + nullable `description` from `$row`, no mandatory UI change in the same commit unless chartered).

---

## 10. Waivers / risks (explicit)

| ID | Waiver / risk |
|----|----------------|
| **W-84-1** | **Static grep completeness:** No runtime plugin system was executed; if a future test harness or dynamic `container->get` path adds consumers, they would not appear in this audit. Current tree is complete under constructor DI + grep. |
| **W-84-2** | **Invoice `data-desc` semantics:** Attribute name suggests “description” but value is **`name`**; optional catalog **`description`** must not be confused without explicit UI/JS changes. |
| **W-84-3** | **`is_active` / inactive services:** `ServiceRepository::list` does not filter `is_active`; `AvailabilityService` does. Extending the contract with **`description`** does not resolve this; a later **alignment** wave would be separate. |
| **W-84-4** | **FOUNDATION-64 / 68 / 72 / 78 / 80 / 82 / 83:** No contradiction found; this audit does not reopen organization-context, HTTP 403, repository-scope, or wave-1 description schema work. |

---

## Deliverables

- This OPS document.  
- Matrix: `system/docs/SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-MATRIX-FOUNDATION-84.md`  
- Roadmap row: `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md` (FOUNDATION-84).  
- **FOUNDATION-85** — not opened here.
