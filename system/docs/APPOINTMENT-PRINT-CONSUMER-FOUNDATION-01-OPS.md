# Appointment print consumer — foundation 01

## Task

`APPOINTMENT-PRINT-CONSUMER-FOUNDATION-01`

## Route

- **GET** `/appointments/{id}/print`
- **Handler:** `AppointmentController::printSummaryPage` (named `printSummaryPage` because `print` is not a valid PHP method name).
- **Guards:** `AuthMiddleware`, `TenantProtectedRouteMiddleware`, `PermissionMiddleware::for('appointments.view')` — same permission as appointment show.
- **Branch:** `ensureBranchAccess($appointment)` after `AppointmentRepository::find` (identical to show).

## Data composition

Read-only service: `Modules\Appointments\Services\AppointmentPrintSummaryService::compose($appointment, $branchIdForSettings)`.

`$branchIdForSettings` is `AppointmentController::queryBranchId()` (current `BranchContext` when `> 0`, else org-effective settings read).

### Settings → section mapping (1:1)

| Setting key | Default | Rendered block on `print.php` |
| --- | --- | --- |
| `appointments.print_show_staff_appointment_list` | **true** | **Staff day schedule** |
| `appointments.print_show_client_service_history` | **true** | **Recent client appointments** |
| `appointments.print_show_package_detail` | **true** | **Packages** (usage + recent client packages) |
| `appointments.print_show_client_product_purchase_history` | **false** | **Client product purchase history** (invoice product lines only) |

When a flag is **false**, the service **skips** the underlying queries for that block and sets `section_visibility[…]` false; the view **omits** the entire `<section>`.

**Always shown (no toggles in this wave):** appointment header block + **Client** block (name + optional PII when branch-visible).

Implemented in **`APPOINTMENT-PRINT-SETTINGS-SUPPORTED-SECTIONS-IMPLEMENTATION-01`** and product lines in **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01`**.

| Section | Source | Notes |
| --- | --- | --- |
| Header | `AppointmentRepository::find` + `AppointmentService` display helpers | Id, summary, date/time presentation, service, staff, room, status, **appointment `notes`** |
| Client contact | `ClientProfileAccessService::resolveForProviderRead` | Phone, email, **client profile `notes`** only when the client is visible on the **current branch** (same as client-profile providers). Name always from appointment join as fallback. |
| Staff day list | `AppointmentRepository::list` | **One `staff_id` per appointment.** Filters: same calendar day as this appointment’s `start_at`, same `staff_id`, optional `branch_id` when the appointment has one, `deleted_at IS NULL`, org scope fragment. Sorted ascending by `start_at`. Empty when `staff_id` is empty. **Gated by** `print_show_staff_appointment_list`. |
| Recent client appointments | `ClientAppointmentProfileProvider::listRecent` | Bounded (10). Staff/space columns follow **`appointments.client_itinerary_show_*`** settings. **Gated by** `print_show_client_service_history`. |
| Package usage (this appointment) | `AppointmentPackageConsumptionProvider::listAppointmentConsumptions` | **Gated by** `print_show_package_detail`. |
| Recent client packages | `ClientPackageProfileProvider::listRecent` | Same implementation as client profile: requires client **home `branch_id` &gt; 0**; otherwise returns **[]**. **Gated by** `print_show_package_detail`. |
| Client product purchase history | `ClientSalesProfileProvider::listRecentProductInvoiceLines` | **Product** `invoice_items` only (`item_type = product`), tenant-scoped invoices, bounded list; **not** service lines. **Gated by** `print_show_client_product_purchase_history` (default **off**). See **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01-OPS.md`**. |

## Intentionally omitted

| Requested idea | Blocker |
| --- | --- |
| **PDF / export framework** | Out of charter. |

## UI

- View: `modules/appointments/views/print.php` (server-rendered inside `shared/layout/base.php` with **`$hideNav = true`**).
- Styles: `public/assets/css/appointment-print.css` — **screen + `@media print`**; toolbar uses **`no-print`** so the optional **Print** button hides when printing.
- Entry: **Printable summary** link on appointment show secondary nav (navigation only; no `window.print` on show).

## Verification

Static (no database):

```bash
php scripts/verify_appointment_print_consumer_foundation_01.php
php scripts/verify_appointment_print_settings_supported_sections_implementation_01.php
php scripts/verify_appointment_print_product_purchase_history_foundation_01.php
```

## Risks not addressed here

Booking semantics, check-in, package auto-detection, staff pricing/duration, concurrency settings, receipt printer, and unrelated screens.
