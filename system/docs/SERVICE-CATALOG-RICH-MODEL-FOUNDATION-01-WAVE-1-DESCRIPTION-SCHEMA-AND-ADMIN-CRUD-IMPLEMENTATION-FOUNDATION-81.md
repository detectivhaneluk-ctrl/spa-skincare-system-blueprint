# SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01 — Wave 1 description (FOUNDATION-81)

**Program:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-81`  
**Boundary:** Matches **FOUNDATION-80** — single additive field **`services.description`** (`TEXT NULL`), **admin CRUD only**; **no** `ServiceListProvider`, **AvailabilityService**, checkout, **InvoiceService**, payroll, reports, public booking, or organization-context changes.

---

## 1. Migration added

| File | Purpose |
|------|---------|
| `system/data/migrations/089_services_add_description.sql` | `ALTER TABLE services ADD COLUMN description TEXT NULL AFTER name;` — additive, no backfill, existing rows get **NULL**. |

---

## 2. Consolidated schema snapshot

| File | Change |
|------|--------|
| `system/data/full_project_schema.sql` | `CREATE TABLE services` includes **`description TEXT NULL`** immediately after **`name`**. |

---

## 3. PHP files changed

| File | Change |
|------|--------|
| `system/modules/services-resources/repositories/ServiceRepository.php` | **`description`** in **`normalize()`** allowlist; trim + empty string → **`null`** in **`normalize()`** (defense in depth vs controller). |
| `system/modules/services-resources/controllers/ServiceController.php` | **`normalizeDescriptionInput()`**; **`parseInput`** adds **`description`**; **`validate`** max **65535 bytes** (MySQL **TEXT** limit); create default **`description` => null**. |
| `system/modules/services-resources/views/services/create.php` | Optional **textarea** `description`. |
| `system/modules/services-resources/views/services/edit.php` | Same. |
| `system/modules/services-resources/views/services/show.php` | Renders description in **`<pre>`** when non-empty after trim. |
| `system/modules/services-resources/views/services/index.php` | **`description_excerpt`** column (~80 chars, **mb_** or byte fallback), **—** when empty; **`link` => false** for that column. |

**Not changed:** `system/modules/services-resources/services/ServiceService.php` — pass-through; audit payloads already include submitted service arrays.

---

## 4. Empty string vs null

| Stage | Behavior |
|-------|----------|
| **Controller** | **`normalizeDescriptionInput`**: non-string → **`null`**; trim; **`''` → null**. |
| **Repository** | **`normalize()`**: if **`description`** key present, **`null`** or whitespace-only string → **`null`**; else trim string. |
| **DB** | **`NULL`** stored when cleared or omitted as empty. |

---

## 5. Why downstream surfaces are untouched

| Surface | Reason |
|---------|--------|
| **`Core\Contracts\ServiceListProvider`** | **FOUNDATION-80** explicitly excluded contract changes; **`ServiceListProviderImpl`** unchanged — consumers still receive the same shape (**`id`, `name`, `duration_minutes`, `price`, …**). |
| **`AvailabilityService`** | Timing queries **`SELECT`** fixed column lists without **`description`**; wave does not add columns to those lists. |
| **`AppointmentCheckoutProviderImpl`** | Uses **`ServiceListProvider::find`** for **`price`** only. |
| **`InvoiceService`** | Service-line VAT uses **`vat_rate_id`** from **`find`**; **`description`** not used. |
| **Payroll / reports / public booking** | No references required for this column in those modules for admin-only storage. |

---

## 6. Scope proof (only F-80 fieldset)

- **One** new column: **`description`**.
- **No** new visibility, pricing, duration, status, or product-linkage fields.
- **No** route file edits — existing **`/services-resources/services*`** forms unchanged in URL shape.
- **No** organization-context or branch-assert programs modified.

---

## 7. Operator note

Apply migration **`089`** on each environment (or run project migration runner) before relying on the column in production.
