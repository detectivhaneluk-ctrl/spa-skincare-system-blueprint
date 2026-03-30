# Sales line lifecycle consistency truth (read-only ops)

## Why this audit exists

**WAVE-01** classifies each `invoice_items` row into a **line domain** story (`line_domain_class`). **WAVE-02** adds **inventory impact** (`inventory_impact_class`) using only `stock_movements` where `reference_type = 'invoice_item'` and `reference_id` = the line id, with a **paid vs not-paid** settlement expectation model on the **invoice header** `invoices.status`.

**This audit (WAVE-03)** is the third cut: it assigns a single **`lifecycle_consistency_class`** per line by composing **accepted WAVE-02 inventory impact truth** with **`invoices.status`** and **`line_domain_class`**, so operators can see whether **header lifecycle**, **line domain**, and **linked ledger facts** reduce to one honest story.

**This tool is read-only, does not repair data, does not implement mixed-sales behavior, and does not implement service-consumption.**

---

## Scope and joins

- **Invoices / lines / movements:** Same effective scope as WAVE-02 (non-deleted `invoices`, current `invoice_items`; optional `--invoice-id=`; movements only via **`invoice_item`** reference contract).
- **Composition:** Calls `SalesLineInventoryImpactTruthAuditService::run` and reclassifies each returned line; **no additional SQL**.

---

## `lifecycle_consistency_class` (deterministic)

Classes are evaluated in a fixed order in `SalesLineLifecycleConsistencyTruthAuditService` (stronger problem classes before “consistent” buckets).

| Value | Meaning |
|--------|--------|
| `unsupported_lifecycle_contract` | WAVE-02 **`inventory_impact_class = unsupported_inventory_contract`** (non-settlement movement types or unsupported movement shape on the `invoice_item` reference). |
| `orphaned_lifecycle_story` | WAVE-02 **`inventory_impact_class = orphaned_inventory_impact_story`** (unusable domain line with linked ledger rows). |
| `paid_retail_line_missing_expected_inventory_effect` | WAVE-02 **`retail_line_missing_expected_inventory_impact`** (clear retail, **`status = paid`**, no linked settlement-shaped rows when deduction is expected). |
| `unpaid_line_with_unexpected_inventory_effect` | **Non-paid** header and either: WAVE-02 **`mixed_line_with_inventory_contradiction`** on **`clear_retail_product_line`**, or **`service_like_line_with_unexpected_inventory_impact`** (service-like line with `invoice_item`-linked movements). |
| `domain_inventory_lifecycle_contradiction` | Contradiction that is **not** mapped into the unpaid buckets above (e.g. mixed domain, paid header + wrong net/product, paid header + service line with ledger rows). |
| `reversal_heavy_lifecycle_story` | Linked shape is **`sale_reversal_only_movements`** and no stronger lifecycle class already applied (operators should expect a reversal-dominated ledger story for that line reference). |
| `lifecycle_consistent_retail_line` | **`clear_retail_product_line`** and WAVE-02 **`retail_line_with_expected_inventory_impact`** (ledger matches current paid / not-paid expectation model). |
| `lifecycle_consistent_service_like_line` | **`clear_service_line`** and WAVE-02 **`service_like_line_with_no_inventory_impact`**. |
| `ambiguous_lifecycle_story` | WAVE-02 **`ambiguous_inventory_story`**, or facts do not fit a stronger lifecycle bucket (fallback). |

### `reason_codes`

Each line’s **`reason_codes`** list merges WAVE-02 inventory reasons with additional **`lifecycle_*`** codes documenting which rule bucket applied (see service source for exact strings).

---

## Operator reading order

1. Run the CLI (default summary, or `--json` for full `lines`).
2. Read **`lifecycle_consistency_class_counts`** — anything other than **`lifecycle_consistent_retail_line`** + **`lifecycle_consistent_service_like_line`** is worth review.
3. Use **`examples_by_lifecycle_consistency_class`** (capped, deterministic scan order by `invoice_item_id`) for concrete **`invoice_item_id`** / **`invoice_id`**.
4. For a single invoice, rerun with **`--invoice-id=`** and cross-check **`invoice_status`**, **`line_domain_class`**, **`inventory_impact_class`**, and **`linked_stock_movement_net_quantity`** on each row.

---

## Limitations

- Uses only **`invoices.status`** as the payment lifecycle signal; does not reconcile **`paid_amount`**, partial business semantics, or timing.
- Does not read movements keyed other than **`invoice_item`** / line id.
- Does not prove branch correctness, physical returns, or refund workflows beyond what WAVE-02 already encodes.
- **“Expected”** remains the **current shipped** settlement model (`paid` ⇒ target net **−line quantity** on clear retail; otherwise target net **0**), not a future mixed-basket design.

---

## How this differs from other audits

| Audit | What it proves |
|--------|----------------|
| **WAVE-01** domain boundary (`SalesLineDomainBoundaryTruthAuditService`) | Service vs retail vs ambiguous **domain** from `item_type` + `source_id` vs catalog rows — **no stock ledger**. |
| **WAVE-02** inventory impact (`SalesLineInventoryImpactTruthAuditService`) | Domain + **`invoice_item`**-linked **`stock_movements`** vs settlement expectation model; **`inventory_impact_class`**. |
| **This audit (WAVE-03)** | One **lifecycle** label tying **header status**, **domain**, and **accepted inventory impact class** for operator narrative consistency. |
| **Inventory drilldown / stock-health audits** | Product-level ledger health, reference integrity, on-hand exposure, refund visibility — **not** per-invoice-line lifecycle classification. |

---

## Explicit non-goals

- **No** automatic repair, backfill, or settlement rewrite.
- **No** mixed-sales or service-consumption implementation.
- **No** schema or migration.
