# Product stock quality — preflight advisory (operations)

**Tasks:** `PRODUCTS-STOCK-QUALITY-PREFLIGHT-ADVISORY-01`, `PRODUCTS-STOCK-QUALITY-PREFLIGHT-RULES-01`, `PRODUCTS-STOCK-QUALITY-PREFLIGHT-RUNBOOK-01`  
**Scope:** Read-only **advisory policy** over consolidated snapshot JSON (and optional baseline). **No** database access, **no** writes, **no** schema change, **no** stock writer behavior.

## What this is (and is not)

- **Is:** A deterministic **ops safety policy layer** — `proceed` / `review` / `hold` plus stable **`advisory_reason_codes`** for automation and runbooks.  
- **Is not:** Proof that stock is correct, a substitute for human judgment on risky changes, or repair guidance. **Consolidated audit truth** remains authoritative for facts; **comparison** remains authoritative for baseline deltas (see **`PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`**).

## Inputs

| Input | Required | Description |
|-------|----------|-------------|
| **Current snapshot** | Yes | JSON from `audit_product_stock_quality_consolidated_readonly.php --json` |
| **Baseline snapshot** | No | Same format; typically an earlier checkpoint (`--baseline=…`) |

Optional **`gate_*`** keys on snapshots are ignored by underlying validation/compare (same as snapshot comparator).

## CLI (from `system/`)

```bash
php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=snapshots/current.json
php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=current.json --baseline=checkpoint.json --json
```

**Exit 0:** advisory payload produced. **Exit 1:** usage, unreadable path, invalid JSON, or invalid snapshot shape.

## Output contract (`advisory_schema_version`)

**Current:** `ProductStockQualityPreflightAdvisoryService::ADVISORY_SCHEMA_VERSION` (**`1.0.0`**).

| Field | Type | Description |
|-------|------|-------------|
| `advisory_schema_version` | string | Version of **this** advisory payload. |
| `baseline_present` | bool | Whether a baseline file was supplied. |
| `advisory_decision` | string | `proceed` \| `review` \| `hold` |
| `advisory_reason_codes` | string[] | Stable machine codes (see below). |
| `current_overall_health_status` | string | From current snapshot. |
| `current_status_fingerprint` | string | From current snapshot. |
| `comparison_summary` | object \| null | Subset of comparison output when baseline present; else **`null`**. |
| `recommended_manual_review` | bool | **`true`** when decision is **`review`** or **`hold`**; **`false`** when **`proceed`**. |

### `comparison_summary` (when baseline present)

Mirrors key fields from `ProductStockQualitySnapshotComparisonService::compare()` (no per-component map):  
`comparison_schema_version`, `overall_change_status`, `contract_compatible`, `fingerprint_changed`, `health_status_changed`, `issue_codes_added`, `issue_codes_resolved`, `persistent_issue_codes`.

## Deterministic advisory rules (conservative)

Evaluation order:

1. **Current `overall_health_status` = `critical`** → **`hold`**, reason **`CURRENT_HEALTH_CRITICAL`** (always; baseline does not override).

2. **No baseline**  
   - **`healthy`** → **`proceed`**, **`CURRENT_HEALTH_HEALTHY_NO_BASELINE`**  
   - **`warn`** → **`review`**, **`CURRENT_HEALTH_WARN_NO_BASELINE`**

3. **Baseline present** (and current is not `critical`, per step 1 — i.e. current is `healthy` or `warn`): reuse **`compare(baseline, current)`** (`left` = baseline, `right` = current). Then:  
   - **`overall_change_status` = `contract_changed`** → **`hold`**, **`BASELINE_CONTRACT_CHANGED`**  
   - **`worsened`** → **`hold`**, **`BASELINE_WORSENED`**  
   - **`changed_same_severity`** → **`review`**, **`BASELINE_CHANGED_SAME_SEVERITY`**  
   - **`improved`** → **`review`**, **`BASELINE_IMPROVED`** (state changed — still verify before writes)  
   - **`unchanged`** and current **`healthy`** → **`proceed`**, **`BASELINE_UNCHANGED_HEALTHY`**  
   - **`unchanged`** and current **`warn`** → **`review`**, **`BASELINE_UNCHANGED_WARN`**

**`advisory_reason_codes`:** exactly one primary code today (deterministic list of one element).

### Same fingerprint / magnitude caveat

As in **`PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md`**, identical **`status_fingerprint`** does not guarantee identical **`report`** magnitudes. **`proceed`** here means “policy allows continuation under this conservative ruleset,” not “counters unchanged.” Inspect snapshots or deep CLIs when magnitude matters.

## Operator workflow (before inventory / product write work)

1. Generate **current** JSON: `php scripts/audit_product_stock_quality_consolidated_readonly.php --json > snapshots/current.json`  
2. Optionally keep a **baseline** (last green checkpoint, pre-import, pre-release).  
3. Run: `php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=snapshots/current.json`  
   or with baseline: `--current=… --baseline=…`  
4. If **`hold`** or **`review`**, follow consolidated/comparison ops and local policy before writes.  
5. Optionally also run **`compare_product_stock_quality_snapshots_readonly.php`** for full comparison detail (includes **`component_changes`**).

## Implementation

- Service: `Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService::evaluate()`  
- Reuses: `ProductStockQualitySnapshotComparisonService::compare()` / `validateConsolidatedSnapshot()`  
- CLI: `system/scripts/evaluate_product_stock_quality_preflight_readonly.php`  
- DI: `modules/bootstrap.php`

## See also

- `system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md` — proves preflight + snapshot + consolidated coherence in one pass  
- `system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md` — consolidated audit + fingerprints  
- `system/docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md` — two-snapshot compare  
- `system/modules/inventory/README.md` — CLI index  
- `system/README.md`  
- `BOOKER-PARITY-MASTER-ROADMAP.md` — `PRODUCTS-DOMAIN-HARDENING-WAVE-11`
