# Product stock health — contract coherence audit (operations)

**Wave:** `PRODUCTS-DOMAIN-HARDENING-WAVE-11`  
**Scope:** Read-only **proof** that consolidated stock-health outputs, snapshot comparison, and preflight advisory stay **mutually coherent** with the accepted issue catalog and fingerprint rules. **No** DB writes, **no** file writes, **no** schema change, **no** stock write-path change.

## Why this exists

ZIP-accepted tooling now includes a rich consolidated snapshot, comparison, and advisory layers. This audit answers: **do those layers still agree with each other on one live pass?** It does **not** prove business correctness of stock data — only **internal contract coherence** of the tooling outputs.

## How it differs from other tools

| Tool | Role |
|------|------|
| **Consolidated audit** | Live DB read; produces the canonical snapshot. |
| **Snapshot compare** | File-on-file delta between two saved snapshots. |
| **Preflight advisory** | Policy `proceed` / `review` / `hold` from snapshot ± baseline. |
| **Coherence audit (this)** | One run: reuses consolidated + compare + preflight in memory and **asserts cross-output invariants**. |

## Invariants checked (deterministic)

| Id | Checks |
|----|--------|
| **A** | Every issue code on consolidated surfaces (`active_issue_codes`, `component_results.*.active_issue_codes`, `issue_inventory.*.code`) ∈ `ProductStockQualityConsolidatedAuditService::ISSUE_CODE_ORDER`. |
| **B** | Every `issue_inventory[].code` ∈ canonical issue catalog. |
| **C** | `advisory_reason_codes` from preflight (no baseline) ∈ known advisory reason catalog (preflight service constants). |
| **D** | No orphan issue codes on consolidated snapshot surfaces (same catalog as A; explicit “no orphan” gate). |
| **E** | `active_issue_count` = `count(active_issue_codes)` = `count(issue_inventory)` rows. |
| **F** | `issue_counts_by_severity` (critical+warn) and sum of `issue_counts_by_component` = `count(issue_inventory)`; each `component_status_summary` `active_issue_count` matches length of `active_issue_codes`. |
| **G** | Overall `status_fingerprint` recomputed twice from snapshot fields (same algorithm as consolidated) matches each other and stored value; per-component fingerprints likewise. |
| **H** | `compare(snapshot, snapshot)` → `overall_change_status` = `unchanged`, `issue_codes_added` / `issue_codes_resolved` empty. |
| **I** | Preflight without baseline: decision and `recommended_manual_review` match derivable rules from `overall_health_status`; `comparison_summary` is `null`. |
| **J** | Invariant ids `A`–`J` are lexicographically orderable when `J` is included (diff-stable reporting). |

If an invariant cannot be proven from current payloads, it **fails** (no invented repair logic).

## JSON payload shape (service / CLI stdout)

Top-level keys (alphabetical after internal `ksort` for diff stability):

- `audit_scope` — fixed string `products_inventory_stock_health_contract_coherence`
- `failed_invariants_count` — int
- `failing_invariants` — list of failing invariant ids
- `fingerprint_inputs_summary` — compact JSON string of selected snapshot fields
- `generated_at_utc` — ISO-8601 UTC
- `invariant_results` — list of `{ id, label, status: pass\|fail\|warn, detail }`
- `notes` — read-only disclaimer
- `overall_status` — `pass` \| `fail`
- `passed_invariants_count` — int
- `products_scanned` — from ledger reconciliation report (`products_scanned`)
- `recommended_next_step` — operator pointer string
- `rows_scanned` — `max(origin total_movements, reference_integrity total_movements)` hint
- `warning_invariants` — list (reserved; typically empty)
- `warning_invariants_count` — int

## Exit codes (CLI)

| Code | Meaning |
|------|---------|
| **0** | Audit completed; **`overall_status` = `pass`** (all invariants passed). |
| **1** | Bootstrap/runtime exception. |
| **2** | Audit completed; **`overall_status` = `fail`** (one or more invariants failed). |

## Operator guide when coherence fails

1. Trust **failing invariant ids** and **`detail`** fields first — they name the broken contract link.  
2. Re-read **`PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md`** for snapshot truth; **`PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`** for compare semantics; **`PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`** for advisory rules.  
3. If **G** (fingerprint) fails, a deploy skew or payload shape drift is likely — align `schema_version` / code before trusting automation.  
4. This audit **does not repair** anything; fix **code or docs**, then re-run.

## Implementation

- Service: `Modules\Inventory\Services\ProductStockHealthContractCoherenceAuditService::run()`
- CLI: `system/scripts/audit_product_stock_health_contract_coherence.php`
- DI: `modules/bootstrap.php`

## See also

- `system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md`
- `system/docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`
- `system/docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`
- `system/modules/inventory/README.md`
