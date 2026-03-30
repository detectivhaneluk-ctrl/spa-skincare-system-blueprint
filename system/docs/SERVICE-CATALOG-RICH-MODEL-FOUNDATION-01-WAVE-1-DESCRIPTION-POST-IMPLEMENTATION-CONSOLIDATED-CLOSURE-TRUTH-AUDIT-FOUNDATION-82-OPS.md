# SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01 — Wave 1 description post-implementation closure (FOUNDATION-82)

**Program:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-82`  
**Scope:** Read-only consolidated closure audit for **FOUNDATION-81** + **FOUNDATION-81-REPAIR** (`services.description` wave).  
**Verdict:** **A** (waivers **W-82-1–W-82-2**).  
**Contradiction check:** No code read contradicts **FOUNDATION-64**, **FOUNDATION-68**, **FOUNDATION-72**, **FOUNDATION-78**, **FOUNDATION-80**, or **FOUNDATION-81-REPAIR** intent; this audit does not reopen organization-context or HTTP-error programs.

---

## 1. Exactly one new `services` schema field

**Migration:** `system/data/migrations/089_services_add_description.sql`

```sql
ALTER TABLE services
    ADD COLUMN description TEXT NULL AFTER name;
```

**Repository search:** Under `system/data/migrations/*.sql`, the only **`ALTER TABLE services`** adding a column for this wave is **`089`** (`024_phase2a_integrity_rules.sql` adds an **index** only, not a new column).

**Conclusion:** **One** additive nullable **`TEXT`** column: **`description`**.

---

## 2. `full_project_schema.sql` truth

**`CREATE TABLE services`** (```442:464:system/data/full_project_schema.sql```) includes:

- **`name VARCHAR(200) NOT NULL`**
- **`description TEXT NULL`**
- **`duration_minutes`** … (unchanged semantics for prior columns)

Snapshot matches **089** placement (**`AFTER name`**).

---

## 3. `ServiceRepository` — persist, read, existing semantics

- **`find` / `list`:** `SELECT s.*` — **`description`** returned when present in DB.
- **`normalize()`** allowlist includes **`description`** (```97:108:system/modules/services-resources/repositories/ServiceRepository.php```); trim / blank → **`null`**; other columns unchanged in meaning.
- **`create` / `update`:** pass **`normalize($data)`** to insert/update as before.

**No** other `services` columns renamed or repurposed in this wave.

---

## 4. `ServiceController` — parse / validate path (81-REPAIR closed)

**`parseInput()`** includes (```280:294:system/modules/services-resources/controllers/ServiceController.php```):

```php
'description' => $this->normalizeDescriptionInput($_POST['description'] ?? null),
```

**`normalizeDescriptionInput()`** (```259:270```): **`null`** / non-string → **`null`**; trim; empty/whitespace → **`null`**.

**`validate()`** (```318:320```): optional max **65535 bytes** when **`description`** is non-null.

**Pipeline:** POST → **`parseInput`** → **`validate`** → **`ServiceService::create` / `update`** → **`ServiceRepository`**. The **FOUNDATION-81** gap ( **`description` omitted from `$data`** ) is **closed** by the line above (per **FOUNDATION-81-REPAIR**).

---

## 5. `ServiceService.php`

**Grep:** no **`description`** symbol — service layer does not special-case the field; **`$data`** flows to **`ServiceRepository`** unchanged aside from existing branch/VAT/group logic.

---

## 6. Admin views

| View | Evidence |
|------|----------|
| **create.php** | **textarea** `name="description"` + error slot (```29:33:system/modules/services-resources/views/services/create.php```). |
| **edit.php** | **textarea** + errors (```30:32:system/modules/services-resources/views/services/edit.php```). |
| **show.php** | **`$__desc`** trim + **`<pre>`** (```9:13:system/modules/services-resources/views/services/show.php```). |
| **index.php** | **`description_excerpt`** (~80 chars, **mb_** or fallback), **`—`** if empty (```20:42:system/modules/services-resources/views/services/index.php```). |

---

## 7. F-81 contradiction closure (persistence)

**Before repair:** **`parseInput`** could omit **`description`** from **`$data`**, so repository never received it.  
**After repair (current tree):** **`description`** is always set on **`$data`** via **`normalizeDescriptionInput`**, so **`ServiceRepository::normalize`** receives it on create/update.

---

## 8. No broader rich-model field

**Schema:** Only **`089`** adds **`services.description`**.  
**Repository allowlist:** Only **`description`** added among service scalar fields.  
**Controller:** No extra catalog flags, pricing, or linkage fields.

---

## 9. Downstream contract / runtime — no drift (grep proof)

| File | `description` / change? |
|------|-------------------------|
| `system/core/contracts/ServiceListProvider.php` | **No** matches |
| `system/modules/services-resources/providers/ServiceListProviderImpl.php` | **No** matches |
| `system/modules/appointments/services/AvailabilityService.php` | **No** matches |
| `system/modules/appointments/providers/AppointmentCheckoutProviderImpl.php` | **No** matches |
| `system/modules/sales/services/InvoiceService.php` | **No** matches |
| `system/modules/payroll/**/*.php` | **No** matches |
| `system/modules/online-booking/**/*.php` | **No** matches |
| `system/modules/reports/**/*.php` | **No** matches |

Contract PHPDoc shape for **`ServiceListProvider`** unchanged; timing SQL in **`AvailabilityService`** does not reference **`description`**.

---

## 10. Waivers / residual risks

| ID | Waiver / risk |
|----|----------------|
| W-82-1 | **Runtime verification** (browser POST + DB read) not executed in this read-only audit — logic path is code-proven only. |
| W-82-2 | **`CatalogSellableReadModelProviderImpl`** still maps **`ServiceRepository::list`** without exposing **`description`** to unified read model — **intentional** per **FOUNDATION-80**; future wave if public/API needs copy. |

---

## 11. Verdict rationale

**A:** Single migration + snapshot alignment; repository and controller paths are **code-complete**; **F-81-REPAIR** addresses the parse gap; downstream files have **zero** `description` edits; waivers are operational / product-follow-up, not schema drift.

**B/C:** Not selected.
