# FOUNDATION-85 — ServiceListProvider optional catalog `description` contract extension (narrow implementation)

**Program:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-OPTIONAL-DESCRIPTION-CONTRACT-EXTENSION-IMPLEMENTATION`  
**Scope:** `ServiceListProvider` PHPDoc + `ServiceListProviderImpl::mapRow` only. No `ServiceRepository` query changes. No consumer, view, JS, route, schema, migration, pricing, VAT, booking, payroll, report, public-booking, or organization-context edits.

**Prerequisite audit:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-2-SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-EXTENSION-READ-ONLY-TRUTH-AUDIT-FOUNDATION-84-OPS.md` and `SERVICELIST-PROVIDER-CONSUMER-AND-CONTRACT-MATRIX-FOUNDATION-84.md`.

---

## Verdict: **A** (narrow, backend-safe)

Optional **`description`** is documented and populated from repository rows without altering the seven pre-existing keys’ types or meaning.

---

## 1. What changed

### 1.1 `Core\Contracts\ServiceListProvider`

- **`list()`** and **`find()`** PHPDoc `@return` row shapes now include an eighth key: **`description: string|null`** (after the existing seven keys, same order as before for those seven).

### 1.2 `Modules\ServicesResources\Providers\ServiceListProviderImpl`

- **`mapRow()`** adds:

  `'description' => isset($row['description']) && trim((string) $row['description']) !== '' ? (string) $row['description'] : null`

- The prior seven keys are unchanged in name, type coercion, and semantics.

### 1.3 `ServiceRepository`

- **No change.** **`list`** / **`find`** already use `SELECT s.*` (see `ServiceRepository.php`), so `services.description` is present on rows when the column exists (FOUNDATION-81).

---

## 2. Consumer behavior (explicit)

Per FOUNDATION-84’s grep-complete inventory, runtime consumers are: **`AppointmentController`**, **`InvoiceController`**, **`InvoiceService`**, **`AppointmentCheckoutProviderImpl`**.

- **All current consumers ignore the new optional key** — they only read the fields they already used (`id`, `name`, `duration_minutes`, `price`, `vat_rate_id`, etc.). No call site was modified; PHP associative arrays tolerate an extra key without behavior change.

---

## 3. Invoice `data-desc` vs catalog `description`

- **Invoice UI `data-desc` still maps to the service name, not the catalog long-text `description`.** FOUNDATION-84 documented that markup uses **`$s['name']`** for the attribute used by scripts; this wave does not change views or JS, so that semantic remains **name**, not **`ServiceListProvider`**’s new **`description`** field.

---

## 4. `AvailabilityService` (dual path)

- **`AvailabilityService` remains a separate direct-SQL path** on **`services`**, not a `ServiceListProvider` consumer. Extending the provider row shape does not change timing SQL, buffers, or `is_active` behavior there.

---

## 5. Deliverables

- Code: `system/core/contracts/ServiceListProvider.php`, `system/modules/services-resources/providers/ServiceListProviderImpl.php`.
- This OPS document.
- Roadmap: one FOUNDATION-85 row in `system/docs/BOOKER-PARITY-MASTER-ROADMAP.md`.
- Canonical audit ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-85-SERVICELIST-PROVIDER-OPTIONAL-DESCRIPTION-CONTRACT-EXTENSION-CHECKPOINT.zip` (built with `handoff/build-final-zip.ps1` rules).

---

## 6. Waivers / residual

| ID | Note |
|----|------|
| **W-85-1** | Same as FOUNDATION-84 **W-84-1**: future or dynamic consumers are outside static grep scope. |
| **W-85-2** | Optional UI adoption of catalog **`description`** is a **separate** product/charter decision; not part of FOUNDATION-85. |

---

## 7. Stop

FOUNDATION-85 stops here; no follow-on implementation is opened by this document.
