# Wave 1 `services.description` — surface matrix (FOUNDATION-82)

Consolidated closure: **FOUNDATION-81** + **FOUNDATION-81-REPAIR**.

---

## A. Schema

| Artifact | `description` |
|----------|----------------|
| `089_services_add_description.sql` | `TEXT NULL` after `name` |
| `full_project_schema.sql` `CREATE TABLE services` | `description TEXT NULL` after `name` |

---

## B. Write path

| Step | Component | Role |
|------|-----------|------|
| 1 | `ServiceController::parseInput` | `'description' => normalizeDescriptionInput($_POST['description'] ?? null)` |
| 2 | `ServiceController::validate` | Max 65535 bytes if non-null |
| 3 | `ServiceService::create` / `update` | Passes `$data` through |
| 4 | `ServiceRepository::normalize` | Allowlist + trim / blank → null |
| 5 | `ServiceRepository::create` / `update` | Persist |

---

## C. Read path (admin)

| View | Uses `description` / derived |
|------|------------------------------|
| create / edit | textarea |
| show | `<pre>` when non-empty |
| index | `description_excerpt` (80 chars) |

---

## D. Downstream — must stay unchanged (verified)

| Surface | `description` in file? |
|---------|-------------------------|
| `ServiceListProvider` | No |
| `ServiceListProviderImpl` | No |
| `AvailabilityService` | No |
| `AppointmentCheckoutProviderImpl` | No |
| `InvoiceService` | No |
| Payroll / online-booking / reports | No |

---

## E. Verdict

**A** — see **FOUNDATION-82-OPS** (waivers **W-82-1–W-82-2**).
