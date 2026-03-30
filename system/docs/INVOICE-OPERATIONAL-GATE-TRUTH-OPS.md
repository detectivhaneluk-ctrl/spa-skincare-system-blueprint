# Invoice operational gate truth (read-only ops)

## Why this audit exists

Accepted truth already exists **per line** (WAVE-01–03) and **per invoice domain composition** (WAVE-04). Operators still had to **mentally merge** those signals to decide whether an invoice is safe to treat as operationally straightforward, blocked on lifecycle, blocked on inventory contradiction, unusable, or genuinely ambiguous.

**This audit (WAVE-05)** emits one deterministic **`operational_gate_class` per invoice** by composing **only** the outputs of:

- `InvoiceDomainCompositionTruthAuditService` (WAVE-04)
- `SalesLineLifecycleConsistencyTruthAuditService` (WAVE-03)
- `SalesLineInventoryImpactTruthAuditService` (WAVE-02)

It adds **no new SQL** and **no future mixed-sales semantics** — only gate labels and reason codes derived from stored facts already classified by those services.

**This tool is read-only, does not repair data, and does not implement mixed-sales behavior.**

---

## Scope

- **Invoices:** Same as WAVE-01–04: non-deleted `invoices` with at least one `invoice_items` row; optional CLI `--invoice-id=`.
- **Omission:** Invoices with zero lines do not appear (they never enter the composed line audits).
- **Implementation note:** Each underlying audit’s `run()` is invoked; this is intentionally explicit for traceability (see aggregate `composed_*_audit_schema_version` fields).

---

## `operational_gate_class` (deterministic)

Evaluated in a **fixed order** inside `InvoiceOperationalGateTruthAuditService` (structural mismatch checks, then unusable, then multi-family ambiguity, then single-family blocks, then clear / manual review / fallback).

| Value | Meaning |
|--------|--------|
| `ambiguous_invoice_operational_story` | **First:** WAVE-04 **`invoice_domain_composition_class`** disagrees with recomputed line counters from grouped WAVE-03 rows (e.g. claims lifecycle anomalies but anomaly count is zero). **Or:** both **lifecycle anomaly** and **inventory contradiction** line families are present on the same invoice (coexisting failure families). **Or:** honest fallback when no stronger bucket applies. |
| `unusable_invoice_operational_state` | **`invoice_domain_composition_class = orphaned_or_unsupported_invoice_story`** **or** **`orphaned_or_unsupported_line_count > 0`** (no safe operational story under accepted orphan/unsupported definitions). |
| `blocked_by_inventory_contradictions` | At least one line with **`inventory_impact_class`** outside the WAVE-02 clean pair, **and** no lifecycle anomalies on that invoice, **and** not unusable / not multi-family ambiguous. |
| `blocked_by_lifecycle_anomalies` | At least one line whose **`lifecycle_consistency_class`** is not a `lifecycle_consistent_*` value, **and** no inventory contradiction lines, **and** not unusable / not multi-family ambiguous. |
| `operationally_clear_invoice` | **`invoice_domain_composition_class`** ∈ {`clean_service_only_invoice`, `clean_retail_only_invoice`, `clean_mixed_domain_invoice`} **and** `lifecycle_anomaly_line_count = 0` **and** `inventory_contradiction_line_count = 0` **and** `orphaned_or_unsupported_line_count = 0`. |
| `manual_review_required_invoice` | **`invoice_domain_composition_class = ambiguous_invoice_domain_story`** while line-level inventory/lifecycle/orphan families are **not** blocking (no anomalies, no inventory contradictions, no orphan/unsupported lines) — **domain geometry / story** is ambiguous only. |

### Per-invoice counters (from grouped WAVE-03 line rows)

- **`lifecycle_anomaly_line_count`:** Lines where `lifecycle_consistency_class` ∉ {`lifecycle_consistent_retail_line`, `lifecycle_consistent_service_like_line`}.
- **`inventory_contradiction_line_count`:** Lines where `inventory_impact_class` ∉ {`retail_line_with_expected_inventory_impact`, `service_like_line_with_no_inventory_impact`}.
- **`orphaned_or_unsupported_line_count`:** Lines matching WAVE-04 orphan predicate (orphaned/unsupported domain, inventory, or lifecycle class on that line).
- **`inventory_affecting_line_count`:** Lines where `inventory_impact_class ≠ service_like_line_with_no_inventory_impact` (same notion as WAVE-04).

The service **reconciles** `lifecycle_anomaly_line_count` and `inventory_affecting_line_count` against WAVE-04’s invoice row; a mismatch yields **`ambiguous_invoice_operational_story`** (honest inconsistency signal).

### `reason_codes`

Each invoice row merges WAVE-04 domain `reason_codes` with operational gate codes (`operational_gate_*`). See the service implementation for the exact strings.

### Aggregate fields

- **`blocked_invoices_count`:** Count of invoices with **`operational_gate_class`** ∈ {`blocked_by_lifecycle_anomalies`, `blocked_by_inventory_contradictions`} (excludes unusable and ambiguous).
- **`affected_invoices_count`:** Invoices where **`operational_gate_class` ≠ `operationally_clear_invoice`**.
- **`examples_by_operational_gate_class`:** Capped (`EXAMPLE_CAP`), deterministic **ascending `invoice_id`** order within each class.

---

## Operator reading order

1. Run this CLI (text summary) or `--json` for the full `invoices` array.
2. Read **`operational_gate_class_counts`** and **`blocked_invoices_count`** / **`affected_invoices_count`**.
3. Use **`examples_by_operational_gate_class`** for concrete invoice IDs.
4. For any invoice, if you need **why** at line level, use WAVE-03 / WAVE-02 / WAVE-01 CLIs or JSON with the same **`--invoice-id=`**.

---

## Limitations

- No invoices without lines; no payment timing proof; no non–`invoice_item` stock references.
- **Clear** and **blocked** mean **accepted audit classes only**, not a future POS/mixed-basket policy.
- **Coexisting** lifecycle and inventory problems collapse to **`ambiguous_invoice_operational_story`** so operators are not steered to a single false “primary” blocker.
- Underlying audits may each run a full pipeline; this is a reporting cost trade-off for explicit composition.

---

## How this differs from the underlying audits

| Audit | What it answers |
|--------|------------------|
| **Invoice domain composition (WAVE-04)** | Invoice-level **domain shape** and **composition class** from line classes; not a single operational gate. |
| **Sales line lifecycle consistency (WAVE-03)** | Per-line **header status vs inventory impact vs domain** story. |
| **Sales line inventory impact (WAVE-02)** | Per-line **ledger vs settlement expectation** story. |
| **This operational gate audit (WAVE-05)** | One **operator-facing gate** per invoice: clear, manual review (domain ambiguity only), blocked (single family), unusable (orphan/unsupported), or ambiguous (multi-family or inconsistent composed facts). |

---

## CLI

- **Path:** `system/scripts/audit_invoice_operational_gate_truth_readonly.php`
- **Options:** `--invoice-id=<int>`, `--json`
- **Exit:** `0` success, `1` uncaught exception
- **Writes:** none
