# Post–wave-1 next program — surface matrix (FOUNDATION-83)

---

## A. `ServiceListProvider` — grep-backed injectors / types

| Location | Role |
|----------|------|
| `system/modules/bootstrap/register_services_resources.php` | Binds **`ServiceListProvider` → `ServiceListProviderImpl`** |
| `register_sales_public_commerce_memberships_settings.php` | **`InvoiceService`**, **`InvoiceController`** receive **`ServiceListProvider`** |

---

## B. Direct `list` / `find` consumers (PHP grep)

| Module | File | Usage |
|--------|------|--------|
| Appointments | `AppointmentController.php` | **`serviceList->list`**, **`serviceList->find`** |
| Sales | `InvoiceController.php` | **`serviceList->list`** |
| Sales | `InvoiceService.php` | **`serviceList->find`** (service-line VAT path) |
| Appointments | `AppointmentCheckoutProviderImpl.php` | **`serviceList->find`** (prefill price) |

**Payroll / reports / online-booking:** no **`ServiceListProvider`** match in scoped grep — **not** contract consumers for this interface.

---

## C. Recommended next program (one)

| ID | Name | Type |
|----|------|------|
| **F-83 pick** | **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-EXTENSION-READ-ONLY-TRUTH-AUDIT`** | Read-only audit |

---

## D. Verdict

**A** — see **FOUNDATION-83-OPS** (waivers **W-83-1–W-83-2**).
