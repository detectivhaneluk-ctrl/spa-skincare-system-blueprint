# SCALABILITY-HOT-TABLE-QUERY-AND-INDEX-AUDIT-01

Compact scale memo: hot operational paths, index coverage, gaps, wave-01 hardening, and remaining blockers.

## Top hot tables / query families (ranked)

1. **`invoices`** — `InvoiceRepository::list` / `count`: `WHERE i.deleted_at IS NULL` + `SalesTenantScope::invoiceClause` (`branch_id IS NOT NULL` + org EXISTS) + optional `branch_id`, `status`, `client_id`, `LIKE` filters + **`ORDER BY i.created_at DESC`**. **Gap (pre-wave):** no composite aligning `branch_id + deleted_at + created_at` or `client_id + deleted_at + created_at` with sort.
2. **`payments`** — `PaymentRepository::getByInvoiceId` (`invoice_id` + tenant EXISTS + **`ORDER BY p.created_at`**); `getCompletedCashTotalsByCurrencyForRegisterSession` (`register_session_id`, `payment_method`, `status`, aggregate); refund paths on `parent_payment_id` + `entry_type` + `status`. **Gaps:** invoice-only index did not include `created_at`; register query had only `register_session_id`; parent refund sum had only `parent_payment_id`.
3. **`appointments`** — `AppointmentRepository::list` / `count` (`deleted_at IS NULL`, optional `branch_id`, date range on `start_at`, `ORDER BY start_at DESC`); `AvailabilityService::listDayAppointmentsGroupedByStaff` (branch + overlap on day + `ORDER BY staff_id, start_at`); `getStaffAppointmentSlotsForDate` (`staff_id`, `deleted_at`, status set, overlap, optional branch). **Partial prior coverage:** `idx_appointments_branch_staff_range` / `room` / `staff_start`. **Gap:** no `(branch_id, deleted_at, start_at)` for branch calendar slices; no `(staff_id, deleted_at, start_at)` for staff-day probes without relying solely on `(staff_id, start_at)`.
4. **`invoice_items`** — `InvoiceItemRepository::getByInvoiceId`: `invoice_id` + tenant EXISTS + **`ORDER BY sort_order, id`**. **Gap:** single-column `invoice_id` index does not match sort key.
5. **`clients`** — `ClientRepository::list` / `count`: `deleted_at IS NULL` + org branch EXISTS + optional `branch_id` + **`ORDER BY last_name, first_name`**. **Gap:** no composite tying branch + soft-delete + name ordering (branch-filtered lists).

## Paths / files audited (repo truth)

| Domain | Entry | File |
|--------|--------|------|
| Appointments list/count/find | `AppointmentRepository` | `system/modules/appointments/repositories/AppointmentRepository.php` |
| Calendar / day / staff slots | `AvailabilityService` | `system/modules/appointments/services/AvailabilityService.php` |
| Invoice list/count | `InvoiceRepository` | `system/modules/sales/repositories/InvoiceRepository.php` |
| Tenant scope on sales | `SalesTenantScope` | `system/modules/sales/services/SalesTenantScope.php` |
| Invoice lines | `InvoiceItemRepository` | `system/modules/sales/repositories/InvoiceItemRepository.php` |
| Payments | `PaymentRepository` | `system/modules/sales/repositories/PaymentRepository.php` |
| Client list | `ClientRepository` | `system/modules/clients/repositories/ClientRepository.php` |
| Client sales profile reads | `ClientSalesProfileProviderImpl` | `system/modules/sales/providers/ClientSalesProfileProviderImpl.php` |
| Org branch EXISTS | `OrganizationRepositoryScope` | `system/core/Organization/OrganizationRepositoryScope.php` |

## Index / query gaps identified (explicit)

- **Invoices (resolved):** list/count date filters hardened in **INVOICE-LIST-DATE-FILTER-SARGABILITY-HARDENING-01** (OR-split + migration `115_invoice_list_date_filter_sargability_indexes.sql`); see `verify_invoice_list_date_filter_sargability_readonly_01.php`.
- **Invoices:** `invoice_number` / `client_name` / `client_phone` `LIKE` predicates — **no safe btree-only fix** without trigram/FTS or invasive changes; documented.
- **Appointments (buffered conflict, resolved):** `hasBufferedAppointmentConflict` rewritten in **APPOINTMENT-CONFLICT-BUFFER-SARGABILITY-HARDENING-01**; see `verify_appointment_conflict_buffer_sargability_readonly_01.php`.
- **Clients:** free-text `search` across many columns — btree indexes do not cover; documented.
- **Invoice sequence hotspot** — **independent** of this index wave; remains on `invoice_number_sequences` / allocation contract (see `INVOICE-SEQUENCE-HOTSPOT-CONTRACT-AND-HARDENING-PLAN-01`).

## Wave 01 — additive hardening applied

Migration: `system/data/migrations/114_scalability_hot_operational_indexes_wave_01.sql` (mirrored in `system/data/full_project_schema.sql`).

| Index | Table | Intent |
|-------|--------|--------|
| `idx_invoices_branch_deleted_created` | `invoices` | Branch-scoped lists + soft-delete + `created_at` sort |
| `idx_invoices_client_deleted_created` | `invoices` | Client history / list filters + sort |
| `idx_payments_invoice_created` | `payments` | Per-invoice timeline order |
| `idx_payments_register_session_method_status` | `payments` | Register session cash totals |
| `idx_payments_parent_entry_status` | `payments` | Refund aggregates by parent payment |
| `idx_invoice_items_invoice_sort` | `invoice_items` | Line fetch ordered by `sort_order`, `id` |
| `idx_appointments_branch_deleted_start` | `appointments` | Branch calendar / `start_at` range |
| `idx_appointments_staff_deleted_start` | `appointments` | Staff day + conflict-adjacent probes |
| `idx_clients_branch_deleted_name` | `clients` | Branch client directory sort |

**No indexes dropped or renamed.** No query or business semantics changed.

## Remaining for later (non-index or follow-up)

- ~~Invoice list: sargable date filter~~ — done (see wave **INVOICE-LIST-DATE-FILTER-SARGABILITY-HARDENING-01**).
- Full-text / normalized search for clients and invoice header search.
- Buffered appointment conflict: possible schema or query redesign if profiling shows it hot at scale.
- **Invoice numbering:** scoped sequences / contract switch — **separate** from this audit; does not block these indexes.

## Static verifier

`system/scripts/read-only/verify_scalability_hot_table_indexes_readonly_01.php` — ensures migration 114 and `full_project_schema.sql` both declare the index names above.
