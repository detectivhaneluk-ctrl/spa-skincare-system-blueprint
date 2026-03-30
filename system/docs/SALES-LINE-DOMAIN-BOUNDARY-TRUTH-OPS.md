# Sales line domain boundary truth (read-only ops)

## Why this audit exists

Before any **mixed basket / service + retail** work, operators need a **single read-only cut** of what `invoice_items` rows actually say today: `item_type`, `source_id`, and whether that id hits `products` and/or `services`. Inventory product audits (`stock_movements`, settlement nets) answer **ledger** questions; this audit answers **invoice line domain** questions only.

**This tool does not repair data, does not change writes, and does not implement mixed-sales behavior.**

---

## Stored shape (repo truth)

- **`invoice_items`**: `item_type`, `source_id` (single column). There is **no** `product_id`, `service_id`, or `staff_id` column on the line.
- **Payload fields `product_id` / `service_id`**: derived for reporting — `product_id` is set only when `item_type = product` and `source_id` is non-null; `service_id` only when `item_type = service` and `source_id` is non-null. Otherwise `null`.
- **`staff_id`**: always `null` in the payload; payroll commentary notes absence of authoritative staff on invoice lines (see migration `076`).

---

## `reference_shape` (deterministic)

Computed from **`source_id`** and **LEFT JOIN** hits on `products` / `services` **by the same numeric id** (independent of `item_type`):

| Value | Meaning |
|--------|--------|
| `no_domain_reference` | `source_id` is null or non-positive (treated as no catalog id). |
| `product_only_reference` | A `products` row exists for `source_id`; no `services` row for that id. |
| `service_only_reference` | A `services` row exists for `source_id`; no `products` row for that id. |
| `both_product_and_service_reference` | **Both** a `products` and a `services` row exist with that id (separate auto-increment sequences can collide). |
| `unsupported_reference_shape` | `source_id` is set but **neither** table has a row for that id. |

---

## `line_domain_class` (deterministic)

Ordered rules (first match wins):

| Value | When |
|--------|------|
| `unsupported_line_contract` | `item_type` is not one of `service`, `product`, `manual`; or `service` / `product` line is missing a required non-null `source_id`. |
| `mixed_domain_line` | `both_product_and_service_reference`; or `manual` with non-null `source_id`; or `service` line whose id resolves **only** to a product row; or `product` line whose id resolves **only** to a service row. |
| `orphaned_domain_reference` | Typed line expects a catalog row but the target is missing, soft-deleted, or inactive (`is_active` ≠ 1), including `unsupported_reference_shape` for typed lines with an id. |
| `ambiguous_domain_story` | `manual` with null `source_id` — no service vs retail attribution on the line. |
| `clear_service_line` | `item_type = service`, non-null `source_id`, `reference_shape = service_only_reference`, and the service row is present, not soft-deleted, and active. |
| `clear_retail_product_line` | `item_type = product`, non-null `source_id`, `reference_shape = product_only_reference`, and the product row is present, not soft-deleted, and active. |

Exact machine reasons are in each line’s `reason_codes` array.

---

## Operator reading order

1. Run CLI (see below); note `affected_lines_count` and `affected_invoice_ids_sample`.
2. Read `line_domain_class_counts` — focus on anything other than `clear_service_line` and `clear_retail_product_line`.
3. Open `examples_by_line_domain_class` (text mode: capped per class) for concrete `invoice_item_id` / `invoice_id` pointers.
4. For full detail, run with `--json` and inspect `lines` (sorted by `invoice_item_id`).

---

## CLI

From `system/`:

```bash
php scripts/audit_sales_line_domain_boundary_truth_readonly.php
php scripts/audit_sales_line_domain_boundary_truth_readonly.php --invoice-id=123
php scripts/audit_sales_line_domain_boundary_truth_readonly.php --json
```

- **Exit 0**: run finished successfully.
- **Exit 1**: uncaught exception (e.g. DB connectivity).
- **No** database writes; **no** file writes.

---

## Limitations

- Does not read `stock_movements`, appointments, packages, gift cards, memberships, or payroll commission lines.
- Does not prove **who** performed a service (no `staff_id` on `invoice_items`).
- Does not classify **membership / package / gift-card** sellable flows if they appear as other `item_type` values — unknown types surface as `unsupported_line_contract`.
- `clear_retail_product_line` follows the same “usable product” notion as settlement (active, not soft-deleted); it does **not** re-run branch pairing rules from `InvoiceProductStockBranchContract`.

---

## How this differs from product inventory audits

| Area | Inventory / stock audits | This audit |
|------|---------------------------|------------|
| Primary table | `stock_movements`, `products` | `invoice_items` + `invoices` |
| Question | Ledger origin, reference integrity, settlement nets | Per-line service vs product **domain** story from typed lines |
| Invoice lines | May join via `reference_type = invoice_item` | Direct line classification |

---

## Service / registration

- **Service**: `Modules\Sales\Services\SalesLineDomainBoundaryTruthAuditService`
- **DI**: `system/modules/bootstrap.php`
- **Audit schema version**: payload key `audit_schema_version` (integer).

---

## Explicit non-goals

- No mixed-sales implementation.
- No invoice, payment, inventory, or settlement write-path changes.
- No schema or migrations.
