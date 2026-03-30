# FOUNDATION-81-REPAIR — Description controller input persistence

**Program:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-81-REPAIR`  
**Scope:** Controller-only fix so **`description`** participates in **`parseInput()`** output and reaches **`ServiceService` → `ServiceRepository`** unchanged from **FOUNDATION-80/81** boundary.

---

## 1. Contradiction repaired (ZIP / code truth)

**Issue:** **`ServiceController::parseInput()`** built the `$data` array **without** a **`description`** key, while **`normalizeDescriptionInput()`** existed and **`validate()`** referenced **`$data['description']`** for length. Admin POST **`description`** therefore never reached **`$this->service->create()` / `update()`** — persistence depended on keys present in **`$data`** after parse.

**Repair:** Add one line to **`parseInput()`** so **`description`** is set from **`$_POST['description']`** via **`normalizeDescriptionInput()`** (same semantics as **FOUNDATION-81** intent).

---

## 2. Exact controller change

**File:** `system/modules/services-resources/controllers/ServiceController.php`  

**Method:** `parseInput(bool $forUpdate): array`  

**Change:** Inside the `$data = [ ... ]` array, immediately after **`'name'`**, add:

```php
'description' => $this->normalizeDescriptionInput($_POST['description'] ?? null),
```

No other methods or files modified for this repair.

---

## 3. Persistence path after repair

1. **`parseInput`** → **`$data['description']`** is **`string`** or **`null`** (trimmed; blank → **`null`**).  
2. **`validate()`** → optional max length when **`description`** is non-null.  
3. **`ServiceService::create` / `update`** → passes **`$data`** to **`ServiceRepository::create` / `update`**.  
4. **`ServiceRepository::normalize()`** → includes **`description`** in allowlist; redundant trim/null normalization remains.

---

## 4. Empty string vs null (unchanged semantics)

| Input | `normalizeDescriptionInput` result |
|-------|--------------------------------------|
| Omitted POST key | `null` (`$_POST['description'] ?? null`) |
| `null` | `null` |
| Non-string | `null` |
| `''` or whitespace-only | `null` after trim |
| Non-empty string | trimmed string |

---

## 5. What was not changed (boundary)

- **No** migration / **`full_project_schema.sql`**  
- **No** **`ServiceRepository`**, views, routes  
- **No** **`ServiceListProvider`**, **`AvailabilityService`**, checkout, **InvoiceService**, payroll, reports, public booking, organization-context  

---

## 6. Broader wave expansion

**None** — single-line repair inside **`parseInput()`** only.
