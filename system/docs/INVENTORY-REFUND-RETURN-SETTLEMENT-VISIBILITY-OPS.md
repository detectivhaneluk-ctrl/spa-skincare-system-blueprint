# Inventory — refund / return settlement visibility (read-only audit)

## Why this audit exists

**INVENTORY-OPERATIONAL-DEPTH-WAVE-01** added:

- Invoice ↔ stock **settlement drilldown** (target net vs `sale` / `sale_reversal` per line).
- **Negative on-hand exposure** from movement history.

Those tools do not **isolate** the operator question: *when stock was restored or partially restored relative to a product line, was that plausibly due to **payment/refund state**, **settlement reversals**, or is the story **not derivable** from current data?*

This audit scopes to **product invoice lines** where **either** the invoice is **not fully paid** **or** the line already has at least one **`sale_reversal`** movement. It classifies each line **conservatively** and **does not** claim physical return unless the data model explicitly supported it (it does not today).

**Non-goals:** no repair, no new return workflow, no line-level refund feature, no settlement rewrite. **Read-only.**

---

## Scope and limitations

**Included rows**

- Non-deleted **`invoices`**.
- **`invoice_items.item_type = 'product'`**.
- And **either**:
  - `invoices.status <> 'paid'`, **or**
  - at least one **`stock_movements`** row exists with `reference_type = 'invoice_item'`, `reference_id = invoice_item.id`, `movement_type = 'sale_reversal'`.

**Explicit limitation**

- The system **does not** persist a dedicated “physical return received” fact per line.  
- **`sale_reversal`** rows reflect **settlement reconciliation** toward the **target net** implied by invoice payment state (see `InvoiceStockSettlementService`), **not** proof that goods were returned.
- **Partial refund vs physical return** cannot be distinguished from **`stock_movements` + invoice status** alone when the only signal is financial state changing.

Payload field **`notes`** in JSON repeats these limits for automation-friendly bundles.

---

## Per-line fields (summary)

| Field | Meaning |
|--------|---------|
| `target_net_quantity` | **`-line_quantity`** if `invoice_status = paid` and `line_quantity > 0`; else **`0`** (same rule as settlement sync). |
| `current_net_quantity_from_movements` | Σ `quantity` for `reference_type = invoice_item`, `movement_type IN (sale, sale_reversal)`. |
| `sale_*` / `sale_reversal_*` counts and quantity totals | Aggregates **only** from those settlement movement types; quantities are **as stored** (sales negative, reversals positive). |
| `first_sale_at` | Minimum `created_at` among **`sale`** rows for the line (null if none). |
| `latest_sale_reversal_at` | Maximum `created_at` among **`sale_reversal`** rows (null if none). |
| `visibility_class` | Deterministic bucket (below). |
| `reason_codes` | Short codes; prefer **honest** when cause is not provable. |

---

## `visibility_class` definitions

Classification order is **fixed** (first match wins). Stronger anomaly labels require **evidence in current rows**.

| Class | Meaning |
|--------|---------|
| `missing_product` | Invalid/missing `product_id` or no live product row. |
| `inactive_product` | Product exists but inactive. |
| `branch_contract_risk` | Fails `InvoiceProductStockBranchContract` vs invoice branch. |
| `reversal_without_prior_sale_history` | At least one **`sale_reversal`** row and **zero** **`sale`** rows for the line — unusual for normal settlement sequencing. |
| `expected_status_restore` | Invoice **not** `paid`, and net movements **match** target zero (ε = `1e-6`). |
| `reversal_history_present_but_aligned` | At least one **`sale_reversal`**, and net **matches** current target (paid or not). |
| `reversal_history_misaligned` | At least one **`sale_reversal`**, but net **does not** match target. |
| `ambiguous_refund_return_story` | **Fallback:** e.g. not paid, not aligned, **no** reversal trail — or other cases where refund vs return cannot be inferred from facts on hand. |

**Critical:** `ambiguous_refund_return_story` is **not** an accusation; it means **the ledger + header state do not tell a complete story** without more context (payments UI, notes, external POS, manual SQL, etc.).

---

## Operator reading order

1. Run **settlement drilldown** when you need **every** product line vs target net:  
   `php scripts/report_product_invoice_stock_settlement_drilldown_readonly.php`  
   (`system/docs/INVENTORY-OPERATIONAL-DEPTH-READONLY-OPS.md`).
2. Run **this audit** when the question is specifically **refund / non-paid / reversal visibility** on **scoped** lines:  
   `php scripts/audit_product_invoice_refund_return_settlement_visibility_readonly.php`  
   Use `--invoice-id=` to narrow.
3. Use **consolidated stock-health** / coherence audits when the question is **ledger integrity, classification, or health contracts** — not refund narrative.

---

## How this differs from other tools

| Tool | Focus |
|------|--------|
| **Settlement drilldown (WAVE-01)** | All product lines (optional invoice filter); **settlement_status** vs target; not scoped to refund/reversal scenarios. |
| **Stock-health / coherence audits** | Cross-cutting quality, fingerprints, issue catalog — not line-level refund/restore narrative. |
| **This audit (WAVE-02)** | **Subset** of lines tied to **non-paid** or **`sale_reversal`** presence; **visibility_class** for restore/reversal **explainability** vs **ambiguity**. |

---

## Read-only guarantee

The service performs **SELECT** / aggregate queries only. It does **not** call `InvoiceStockSettlementService`, `InvoiceService`, or `StockMovementService`, and does **not** repair data.
