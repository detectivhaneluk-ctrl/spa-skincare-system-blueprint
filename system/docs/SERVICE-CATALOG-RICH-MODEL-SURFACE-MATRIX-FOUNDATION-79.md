# Service catalog surface matrix — FOUNDATION-79

Read-only inventory. Paths are repo-relative under `system/` unless noted.

---

## A. `services` columns — schema sources

| Column | `022_create_services_table.sql` | `024_phase2a_integrity_rules.sql` | `full_project_schema.sql` |
|--------|----------------------------------|-----------------------------------|---------------------------|
| `id` | ✓ | — | ✓ |
| `category_id` | ✓ | — | ✓ |
| `name` | ✓ | — | ✓ |
| `duration_minutes` | ✓ | — | ✓ |
| `buffer_before_minutes` | ✓ | — | ✓ |
| `buffer_after_minutes` | ✓ | — | ✓ |
| `price` | ✓ | — | ✓ |
| `vat_rate_id` | ✓ | — | ✓ |
| `is_active` | ✓ | — | ✓ |
| `branch_id` | ✓ | — | ✓ |
| `created_by` / `updated_by` | ✓ | — | ✓ |
| `created_at` / `updated_at` | ✓ | — | ✓ |
| `deleted_at` | ✓ | index `idx_services_deleted` | ✓ + index |

**Later column-altering migrations:** none found beyond **`024`** index.

---

## B. Column usage — `ServiceRepository::normalize` whitelist

| Column | Create | Update | Notes |
|--------|--------|--------|-------|
| `category_id` | ✓ | ✓ | |
| `name` | ✓ | ✓ | |
| `duration_minutes` | ✓ | ✓ | |
| `buffer_before_minutes` | ✓ | ✓ | |
| `buffer_after_minutes` | ✓ | ✓ | |
| `price` | ✓ | ✓ | |
| `vat_rate_id` | ✓ | ✓ | |
| `is_active` | ✓ | ✓ | |
| `branch_id` | ✓ | ✓ | |
| `created_by` / `updated_by` | ✓ (service layer) | ✓ (service layer) | Not from HTTP in audited controller |
| `created_at` / `updated_at` | DB | DB / partial update | Not in `normalize` |

---

## C. Admin UI (`views/services/*`) vs dead in UI

| Field | create | edit | index | show |
|-------|--------|------|-------|------|
| `name` | ✓ | ✓ | column | ✓ |
| `category_id` | ✓ | ✓ | filter + column | ✓ |
| `duration_minutes` | ✓ | ✓ | column | ✓ |
| `buffer_*` | ✓ | ✓ | — | ✓ |
| `price` | ✓ | ✓ | column | ✓ |
| `vat_rate_id` | ✓ | ✓ | — | — |
| `is_active` | ✓ | ✓ | — | ✓ |
| `staff_ids` / rooms / equipment / staff_group_ids | ✓ | ✓ | — | — |
| `branch_id` | — | — | — | — |

---

## D. `ServiceListProvider` contract vs raw repo row

| Field | In `ServiceListProvider` PHPDoc | In `ServiceListProviderImpl::mapRow` |
|-------|-----------------------------------|--------------------------------------|
| `id`, `name`, `duration_minutes`, `price` | ✓ | ✓ |
| `vat_rate_id`, `category_id`, `category_name` | ✓ | ✓ |
| `is_active`, `branch_id`, buffers | — | — |

---

## E. Methods — services-resources (primary write/read HTTP)

| Class | Method | Role |
|-------|--------|------|
| `ServiceRepository` | `find`, `list`, `create`, `update`, `softDelete` | CRUD + junction sync |
| `ServiceService` | `create`, `update`, `delete`, `validateStaffGroupIdsForService` | Branch, VAT, staff groups, audit, transactions |
| `ServiceController` | `index`, `create`, `store`, `show`, `edit`, `update`, `destroy` | HTTP, validation, branch access |

---

## F. Downstream consumers (matrix)

| Consumer | Touch | Key `services` fields / behavior |
|----------|--------|----------------------------------|
| `ServiceListProviderImpl` | `ServiceRepository` | Mapped subset for cross-module use |
| `AppointmentController` | `ServiceListProvider` | Dropdown list (no `is_active` in map) |
| `AvailabilityService` | Direct SQL | `duration_minutes`, buffers, `branch_id`, `is_active`, `deleted_at` |
| `AppointmentService` | `AvailabilityService` + SQL locks | Active service; `branch_id` resolution |
| `PublicBookingService` | `AvailabilityService` | Active service for branch scope |
| `AppointmentCheckoutProviderImpl` | `ServiceListProvider::find` | `price` prefill |
| `InvoiceService` | `ServiceListProvider::find` | `vat_rate_id` → line `tax_rate` for service lines |
| `CatalogSellableReadModelProviderImpl` | `ServiceRepository::list` | `is_active`, `price`, `name`, `branch_id` |
| `PayrollService` | SQL join | `services.category_id` (as `service_category_id`) |
| `ConsentService` / documents | `service_id` refs | Consent requirements per service |
| `SalesLineDomainBoundaryTruthAuditService` | — | `services` row existence for `source_id` |
| `verify_services_vat_rate_drift_readonly.php` | — | `vat_rate_id` drift |

---

## G. Verdict

**B** — see **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-READ-ONLY-TRUTH-AUDIT-FOUNDATION-79-OPS.md`** — waivers **W-79-1–W-79-7**.

**Next program (one):** **`SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01`** narrow schema + model implementation (Phase 3.2 wave 2), per OPS §9.
