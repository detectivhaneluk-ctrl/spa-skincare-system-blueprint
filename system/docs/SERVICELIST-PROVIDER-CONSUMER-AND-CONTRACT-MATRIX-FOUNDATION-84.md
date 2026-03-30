# ServiceListProvider consumer and contract matrix — FOUNDATION-84

Read-only; code citations refer to tree state at FOUNDATION-84.

## Contract + implementation (source of truth)

| Artifact | Role |
|----------|------|
| `system/core/contracts/ServiceListProvider.php` | Declares `list()` / `find()`; PHPDoc **seven keys** per row. |
| `system/modules/services-resources/providers/ServiceListProviderImpl.php` | Maps `ServiceRepository` rows via `mapRow()` → **same seven keys** only. |
| `system/modules/services-resources/repositories/ServiceRepository.php` | `find`/`list` use `s.*` (DB includes `description` post-F-81); not all columns exposed by provider. |

## DI bindings

| Bootstrap file | Registration |
|----------------|--------------|
| `system/modules/bootstrap/register_services_resources.php` | `ServiceListProvider` → `ServiceListProviderImpl` |
| `system/modules/bootstrap/register_appointments_online_contracts.php` | `AppointmentController`, `AppointmentCheckoutProviderImpl` |
| `system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php` | `InvoiceService`, `InvoiceController` |

## Complete in-tree `ServiceListProvider` consumers (`*.php` grep)

| # | Class | `list()` | `find()` |
|---|-------|----------|----------|
| 1 | `Modules\Appointments\Controllers\AppointmentController` | Yes (multiple actions + render helpers) | Yes (`parseInput` only) |
| 2 | `Modules\Sales\Controllers\InvoiceController` | Yes (invoice create/edit flows) | No |
| 3 | `Modules\Sales\Services\InvoiceService` | No | Yes (`applyCanonicalTaxRatesForServiceLines`) |
| 4 | `Modules\Appointments\Providers\AppointmentCheckoutProviderImpl` | No | Yes (`getCheckoutPrefill`) |

**Non-consumers (dual-path note):** `AvailabilityService` — direct SQL on `services` (see OPS §6).

## Field assumption matrix (what each layer reads)

| Consumer | Method | Keys used from row |
|----------|--------|-------------------|
| `AppointmentController` + appointment views | `list` | `id`, `name`, `duration_minutes` (views); waitlist views also `id`, `name` |
| `AppointmentController` | `find` | `duration_minutes` |
| `InvoiceController` + invoice views | `list` | `id`, `name`, `price` (+ `data-desc` = **name** in markup) |
| `InvoiceService` | `find` | `vat_rate_id` |
| `AppointmentCheckoutProviderImpl` | `find` | `price` |

## Optional contract field risk (additive keys)

| Hypothetical field | Harmless / ignored / risky (today) |
|--------------------|--------------------------------------|
| `description` (nullable string, catalog) | **Ignored** by all consumers; **low** runtime risk. **W-84-2:** invoice `data-desc` naming vs semantics. |
| `is_active` | **Risky** if consumers start filtering without product rules; not same as `AvailabilityService` gate. |
| `buffer_*` | **Risky** if confused with `AvailabilityService` timing (different path). |

## Array shape

| Topic | Result |
|-------|--------|
| Positional / numeric index | **Not used** — named keys only. |
| Key order | **Not relied on** for logic (list order is SQL presentation). |

## Verdict

**A** — See `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-EXTENSION-READ-ONLY-TRUTH-AUDIT-FOUNDATION-84-OPS.md`.

## Recommended next program (one)

Narrow **`ServiceListProvider`** optional **`description`** contract extension (PHPDoc + `mapRow`), chartered separately from UI.
