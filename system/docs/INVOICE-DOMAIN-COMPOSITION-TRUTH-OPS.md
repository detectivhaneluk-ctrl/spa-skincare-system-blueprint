# Invoice domain composition truth (read-only ops)

## Why this audit exists

**WAVE-01** through **WAVE-03** classify each `invoice_items` row (domain, inventory impact, lifecycle vs header). Operators still need a **single invoice-level** label: whether an invoice is cleanly service-only, retail-only, mixed, or structurally inconsistent.

**This audit (WAVE-04)** groups accepted WAVE-03 line payloads by `invoice_id` and assigns **`invoice_domain_shape`** plus **`invoice_domain_composition_class`** using **only** those stored line-level classes — no new business rules beyond aggregation.

**This tool is read-only, does not repair data, and does not implement mixed-sales behavior.**

---

## Scope

- **Source rows:** Output of `SalesLineLifecycleConsistencyTruthAuditService::run` (same scope as WAVE-01: non-deleted `invoices` with at least one `invoice_items` row; optional `--invoice-id=`).
- **Omission:** Invoices with **zero** lines never appear in the line audit and are **not** listed here.
- **No extra SQL** in this service: one composed line pass only.

---

## `invoice_domain_shape` (deterministic)

Based on `line_domain_class` across all lines on the invoice (see `InvoiceDomainCompositionTruthAuditService` for ordering):

| Value | Meaning |
|--------|--------|
| `service_only_lines` | Every line is **`clear_service_line`** (and `line_count ≥ 1`). |
| `retail_only_lines` | Every line is **`clear_retail_product_line`**. |
| `mixed_service_and_retail_lines` | At least one **`clear_service_line`** and at least one **`clear_retail_product_line`**, and **no** non-clear domain line. |
| `no_clear_domain_lines` | No line is clear service or clear retail (all mixed / orphaned / unsupported contract / ambiguous domain). |
| `unsupported_invoice_shape` | `line_count === 0`, or a non-clear line appears together with a pattern that is not exactly the four cases above (e.g. clear service plus ambiguous domain on the same invoice). |

---

## `invoice_domain_composition_class` (deterministic)

Evaluated in fixed order (first match wins):

| Value | Meaning |
|--------|--------|
| `orphaned_or_unsupported_invoice_story` | Any line has **orphaned/unsupported** truth: `line_domain_class` ∈ {`orphaned_domain_reference`, `unsupported_line_contract`} **or** `inventory_impact_class` ∈ {`orphaned_inventory_impact_story`, `unsupported_inventory_contract`} **or** `lifecycle_consistency_class` ∈ {`orphaned_lifecycle_story`, `unsupported_lifecycle_contract`}. |
| `mixed_invoice_with_inventory_contradictions` | Any line’s `inventory_impact_class` is **not** one of {`retail_line_with_expected_inventory_impact`, `service_like_line_with_no_inventory_impact`}. |
| `mixed_invoice_with_lifecycle_anomalies` | Any line’s `lifecycle_consistency_class` is **not** one of {`lifecycle_consistent_retail_line`, `lifecycle_consistent_service_like_line`}. |
| `clean_mixed_domain_invoice` | **`invoice_domain_shape = mixed_service_and_retail_lines`** and every line passes the two “clean” checks above. |
| `clean_service_only_invoice` | **`invoice_domain_shape = service_only_lines`** and every line clean. |
| `clean_retail_only_invoice` | **`invoice_domain_shape = retail_only_lines`** and every line clean. |
| `ambiguous_invoice_domain_story` | Fallback (e.g. `no_clear_domain_lines` or `unsupported_invoice_shape` while line-level inventory/lifecycle happen to look clean, or other edge not matching clean buckets). |

### Counts on each invoice row

- **`inventory_affecting_line_count`:** Lines where `inventory_impact_class ≠ service_like_line_with_no_inventory_impact`.
- **`lifecycle_anomaly_line_count`:** Lines where `lifecycle_consistency_class` is **not** one of the two `lifecycle_consistent_*` values.

### `reason_codes`

Short stable strings on each invoice row documenting which bucket fired (see service implementation).

---

## Operator reading order

1. Run the CLI (summary) or `--json` for full `invoices`.
2. Read **`invoice_domain_composition_class_counts`** — non-clean classes need review.
3. Use **`examples_by_invoice_domain_composition_class`** (capped, **ascending `invoice_id`**) for concrete invoices.
4. Drill into line detail with WAVE-03 CLI or JSON on the same **`--invoice-id=`**.

---

## Limitations

- No invoices without lines; no payments, appointments, or non–`invoice_item` stock references.
- **Clean** means WAVE-02/WAVE-03 **accepted** clean classes only — not a future mixed-basket policy.
- Header fields (`invoice_status`, `invoice_branch_id`) are taken from the first line row per invoice (identical for all lines on that invoice in the WAVE-01 query).

---

## How this differs from line-level audits

| Audit | Granularity |
|--------|-------------|
| **WAVE-01** domain boundary | Per **line**: `line_domain_class`. |
| **WAVE-02** inventory impact | Per **line**: `inventory_impact_class` + linked movements. |
| **WAVE-03** lifecycle consistency | Per **line**: `lifecycle_consistency_class` vs header status. |
| **This audit (WAVE-04)** | Per **invoice**: composition shape + invoice-level class from line truth only. |

---

## Explicit non-goals

- No schema, migration, invoice/item/stock writes, settlement changes, storefront, or mixed-sales implementation.
