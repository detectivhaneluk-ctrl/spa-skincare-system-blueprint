# Appointment print — supported section settings (implementation 01)

## Task

`APPOINTMENT-PRINT-SETTINGS-SUPPORTED-SECTIONS-IMPLEMENTATION-01`

## Scope

Adds branch-aware booleans that gate optional blocks on **`GET /appointments/{id}/print`** only: **three** default **true** (preserve early print layout) plus **one** default **false** (opt-in product lines).

| Setting key | Default | Maps to |
| --- | --- | --- |
| `appointments.print_show_staff_appointment_list` | true | Staff day schedule section |
| `appointments.print_show_client_service_history` | true | Recent client appointments section |
| `appointments.print_show_package_detail` | true | Packages section (usage + recent client packages) |
| `appointments.print_show_client_product_purchase_history` | false | Client product purchase history (`ClientSalesProfileProvider::listRecentProductInvoiceLines`) |

**Product semantics:** **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01-OPS.md`**.

## Implementation touchpoints

- `SettingsService::APPOINTMENT_KEYS`, `getAppointmentSettings`, `setAppointmentSettings`, `patchAppointmentSettings`
- `SettingsController` allowlists + POST → `appointmentPatch`
- `modules/settings/views/index.php` — “Appointment print summary” subsection (four checkboxes)
- `AppointmentPrintSummaryService` — reads `getAppointmentSettings` for current branch context; skips queries when off; returns `section_visibility`
- `modules/appointments/views/print.php` — renders optional sections only when flags true

## Verification

```bash
php scripts/verify_appointment_print_settings_supported_sections_implementation_01.php
php scripts/verify_appointment_print_product_purchase_history_foundation_01.php
```

## Related docs

- **`APPOINTMENT-PRINT-CONSUMER-FOUNDATION-01-OPS.md`** — route + data sources
- **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01-OPS.md`** — product line truth + refunds note
- **`APPOINTMENT-SETTINGS-BACKEND-CONTRACT-FOUNDATION-01-OPS.md`** — key table + Foundation 10
