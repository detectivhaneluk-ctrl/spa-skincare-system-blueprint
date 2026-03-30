# Product stock quality — consolidated audit (operations)

**Tasks:** `PRODUCTS-STOCK-QUALITY-CONSOLIDATED-AUDIT-01`, `PRODUCTS-STOCK-QUALITY-SEVERITY-RULES-01`, `PRODUCTS-STOCK-QUALITY-OPS-RUNBOOK-01`, `PRODUCTS-STOCK-QUALITY-CLI-EXIT-GATES-01`, `PRODUCTS-STOCK-QUALITY-OPERATOR-NEXT-STEPS-01`, `PRODUCTS-STOCK-QUALITY-PAYLOAD-STABILITY-01`, `PRODUCTS-STOCK-QUALITY-ISSUE-CATALOG-01`, `PRODUCTS-STOCK-QUALITY-ISSUE-INVENTORY-01`, `PRODUCTS-STOCK-QUALITY-CONTRACT-DOC-01`, `PRODUCTS-STOCK-QUALITY-FINGERPRINT-CONTRACT-01`, `PRODUCTS-STOCK-QUALITY-NORMALIZED-SUMMARY-01`, `PRODUCTS-STOCK-QUALITY-DIFFABILITY-DOC-01`, `PRODUCTS-STOCK-QUALITY-SNAPSHOT-COMPARISON-01` (**`PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`**), `PRODUCTS-STOCK-QUALITY-PREFLIGHT-ADVISORY-01` (**`PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`**)  
**Scope:** One read-only CLI + service that composes existing inventory audits. **No** writes, **no** schema change, **no** new stock behavior.

## Purpose

Give operators a **single** products/stock health verdict while preserving **full** underlying counts in the payload. Individual CLIs remain the place for deep dives and capped row examples. This audit is the **primary stock-health entry point** before deeper tools.

## When to run (runbook)

1. **Before any product or inventory write work** after restore, migration, import, or suspected ledger corruption — run the consolidated audit first.
2. **After incidents** involving `stock_movements`, `products.stock_quantity`, or settlement failures.
3. **Periodic hygiene** — same cadence you already use for ledger reconciliation; this adds reference and classification signals in one pass.

**Recommended order (first → deeper):**

| Step | Command (from `system/`) | What it answers |
|------|--------------------------|-----------------|
| 1 | `php scripts/audit_product_stock_quality_consolidated_readonly.php` | Overall **healthy / warn / critical** + **issue codes / issue_inventory** + component counts + **recommended next checks** |
| 2 | Same with `--json` | Machine-readable payload (`schema_version`, **`status_fingerprint`**, rollups, **`active_issue_codes`**, **`issue_inventory`**, gates optional) for tickets / logs / dashboards / diffs |
| 3 | `php scripts/compare_product_stock_quality_snapshots_readonly.php --left=<before.json> --right=<after.json>` | Deterministic delta between **two** saved JSON snapshots (**no DB**) — **`PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`** |
| 4 | `php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=<current.json> [--baseline=<checkpoint.json>]` | Conservative **`proceed` / `review` / `hold`** advisory (policy only) — **`PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`** |
| 5 | Individual tools below | Capped **example rows** per anomaly or origin |

**Individual CLIs (unchanged; still authoritative for examples and doc cross-links):**

- Ledger: `php scripts/audit_product_stock_ledger_reconciliation.php` — `PRODUCT-STOCK-LEDGER-RECONCILIATION-OPS.md`
- Global SKU branch attribution: `php scripts/audit_product_global_sku_branch_attribution_readonly.php` — `system/modules/inventory/README.md` (stock rules + CLI index) + ledger ops for caveat context
- Origin rollup: `php scripts/report_product_stock_movement_origin_classification_readonly.php` — `PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md`
- Reference integrity: `php scripts/audit_product_stock_movement_reference_integrity_readonly.php` — `PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md`
- Classification drift: `php scripts/audit_product_stock_movement_classification_drift_readonly.php` — `PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md`

## Implementation

- Service: `Modules\Inventory\Services\ProductStockQualityConsolidatedAuditService::run()`
- CLI: `system/scripts/audit_product_stock_quality_consolidated_readonly.php` (`--json` supported)
- **Two-snapshot compare (read-only, file-only):** `Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService::compare()` + CLI `system/scripts/compare_product_stock_quality_snapshots_readonly.php` — **`PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`**
- **Preflight advisory (read-only, file-only):** `Modules\Inventory\Services\ProductStockQualityPreflightAdvisoryService::evaluate()` + CLI `system/scripts/evaluate_product_stock_quality_preflight_readonly.php` — **`PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`** (advisory policy; not a substitute for audit truth)

### Exit codes and optional preflight gates

| Exit | Meaning |
|------|---------|
| **0** | Audit completed successfully **and** gate passed (or **no** gate flag — default). |
| **1** | Bootstrap/runtime failure (exception). |
| **2** | Audit completed but an **optional** gate failed (see below). |

**Backward-safe default:** with **no** gate flags, **overall_health_status does not change the exit code** (still **0** on success, **1** on runtime failure only).

Optional flags (from `system/`):

- **`--fail-on-critical`** — exit **2** when `overall_health_status === critical`; **warn** and **healthy** exit **0**.
- **`--fail-on-warn`** — exit **2** when `overall_health_status` is **warn** or **critical**; **healthy** exits **0**.
- If **both** are passed, **`--fail-on-warn`** wins (stricter).

**Text mode** prints the active **exit gate policy** and, when a gate flag is set, **gate_result** (`pass` / `fail`).

**JSON mode** adds **`gate_policy`** (`none` | `fail_on_critical` | `fail_on_warn`) and **`gate_result`** (`pass` | `fail`) on the emitted object (CLI-only fields; not produced by the service). Exit code rules are the same as above.

**Text mode (issue summary):** after **overall** status lines, the CLI prints **`active_issue_count`**, **`active_issue_codes`**, and a compact **`issue_inventory`** block (code, severity, component, summary). Then a **diff / checkpoint** block: **`status_fingerprint`**, **`issue_counts_by_severity`**, **`issue_counts_by_component`**, **`component_status_summary`** (JSON lines). Per-component detailed sections include **`status_fingerprint`** and **`active_issue_codes`**. **`--json`** emits the full service payload plus CLI **`gate_*`** fields.

## Stable payload contract (`schema_version`)

**Current:** `ProductStockQualityConsolidatedAuditService::SCHEMA_VERSION` (e.g. **`1.2.0`**). Bump when envelope or required top-level keys change in a breaking way for downstream consumers; see service constant comment for additive vs breaking guidance.

**Automation rule of thumb:** Prefer **`schema_version`** compatibility, then **`status_fingerprint`** equality for “same status shape”, then **`active_issue_codes`**, **`issue_inventory`** (codes/severity/component), and normalized rollups. Prefer **`issue_inventory[].code`** and numeric fields inside **`report`** when drilling into counts. Treat **`generated_at`** as **observational only** — never part of semantic “did health change?” comparison. Treat **`severity_reasons`**, **`overall_summary`**, and **`issue_inventory[].summary`** as **secondary** human context — do not parse them as stable identifiers.

### Comparing two runs (operators / automation)

1. Confirm **`schema_version`** matches (or understand the delta from release notes) before comparing fingerprints.
2. Compare **`status_fingerprint`**. If equal, **overall_health_status**, **active_issue_codes**, **active_issue_count**, **issue_inventory** (code + severity + component), and per-component **severity** / **active_issue_codes** are the same **for fingerprint purposes** — underlying **`report`** counters may still differ if future contract changes ever widen the fingerprint; today they do not include raw counts.
3. If fingerprints differ, inspect **`active_issue_codes`**, **`issue_inventory`**, **`issue_counts_by_*`**, **`component_status_summary`**, then per-component **`report`** for numeric drift.
4. **`generated_at`** differences alone imply nothing about stock health.

**Before inventory/product write work:** if **`status_fingerprint`** (or **`overall_health_status`**) **worsens** vs your last accepted checkpoint (e.g. new codes, critical appearing, or fingerprint change after a green baseline), treat as **manual review required** even when free-text summaries look similar. Fingerprint **improvement** (e.g. issues cleared) should still be confirmed against **`report`** if you rely on quantity thresholds for go-live.

### `status_fingerprint` (top-level) and per-component `status_fingerprint`

| Field | Description |
|-------|-------------|
| **`status_fingerprint`** (service root) | **SHA-256** (lowercase hex) of canonical JSON built **only** from: **`overall_health_status`**, **`active_issue_codes`**, **`active_issue_count`**, **`issue_inventory`** entries reduced to **`code`**, **`severity`**, **`component`** (list order = existing inventory order), and **`components`** map (keys = component ids) each with **`severity`** + **`active_issue_codes`**. **Excludes:** **`generated_at`**, **`schema_version`**, all free-text summaries, **`report`**, **`recommended_*`**, **`severity_reasons`**. Nested associative arrays are **key-sorted recursively** for stable `json_encode`; list order for **`active_issue_codes`**, **`issue_inventory`**, and component key ordering follows **`COMPONENT_IDS`** then **`ksort`** on the components map for deterministic object key order. |
| **`component_results[<id>].status_fingerprint`** | **SHA-256** hex of `json_encode` after **`ksort`** on `{"active_issue_codes":[…],"severity":"…"}` (codes already in **`ISSUE_CODE_ORDER`**). |

Semantically identical status inputs yield **identical** fingerprints. Changing only **`generated_at`** or narrative text does **not** change fingerprints.

**Limit (by design):** the same active issue **codes** with **different magnitudes** in **`report`** (e.g. `mismatched_count` **1** vs **99**) still produce the **same** **`status_fingerprint`**. Use **`report`** when you must detect counter drift while issue flags stay on.

### Canonical issue codes (stable contract)

Defined as **`ProductStockQualityConsolidatedAuditService` public constants** and ordered by **`ISSUE_CODE_ORDER`** (deterministic sort for **`active_issue_codes`** and **`issue_inventory`**).

| Code | Severity (for this issue row) | Derived from (same thresholds as severity tables below) |
|------|------------------------------|--------------------------------------------------------|
| `LEDGER_MISMATCH_PRESENT` | **critical** | `ledger_reconciliation.report.mismatched_count > 0` |
| `DELETED_OR_MISSING_PRODUCT_MOVEMENTS_PRESENT` | **critical** | `origin_classification.report.movements_on_deleted_or_missing_product > 0` |
| `REFERENCE_INTEGRITY_ANOMALIES_PRESENT` | **critical** | Any `reference_integrity.report.counts_by_anomaly[*] > 0` (keys from reference audit service) |
| `GLOBAL_SKU_BRANCH_ATTRIBUTION_PRESENT` | **warn** | `global_sku_branch_attribution.report.affected_movements_count > 0` |
| `ORIGIN_OTHER_UNCATEGORIZED_PRESENT` | **warn** | `origin_classification.report.counts_by_origin['other_uncategorized'] > 0` |
| `CLASSIFICATION_DRIFT_PRESENT` | **warn** | `classification_drift.report.other_uncategorized_total > 0` |
| `MANUAL_OPERATOR_UNEXPECTED_MOVEMENT_TYPE_PRESENT` | **warn** | `classification_drift.report.manual_operator_unexpected_movement_type_count > 0` |

New codes require a **contract bump** and ops update; do not infer undocumented codes from free text.

### Top-level keys (service output)

| Key | Type | Description |
|-----|------|-------------|
| `schema_version` | string | Frozen contract version for this payload shape. |
| `generated_at` | string | ISO-8601 UTC (`gmdate('c')`). |
| `overall_health_status` | string | `healthy` \| `warn` \| `critical` (worst of components). |
| `overall_summary` | string | Short operator-facing sentence + optional clipped reason list (**not** a stable automation key). |
| `active_issue_codes` | string[] | Unique active codes, sorted by **`ISSUE_CODE_ORDER`**; empty when **healthy** overall. |
| `active_issue_count` | int | `count(active_issue_codes)`. |
| `status_fingerprint` | string | SHA-256 hex; canonical status snapshot (see above). |
| `issue_counts_by_severity` | object | Counts of **`issue_inventory`** rows: **`critical`**, **`warn`** (always present; **0** when none). |
| `issue_counts_by_component` | object | Counts of **`issue_inventory`** rows per component id (all five keys; **0** when none). |
| `component_status_summary` | object | Per component id: **`severity`**, **`active_issue_count`**, **`active_issue_codes`** (see below). |
| `issue_inventory` | array | One object per **active** issue (see below); empty list when no issues. |
| `recommended_next_steps` | array | Deduped list of investigation steps (see below); empty when all components healthy. |
| `component_results` | object/map | Five fixed keys (see below). |

### `component_status_summary[<id>]`

| Key | Description |
|-----|-------------|
| `severity` | Component rollup severity. |
| `active_issue_count` | `count(active_issue_codes)` for that component. |
| `active_issue_codes` | Sorted issue codes (same as **`component_results`**). |

### `issue_inventory[]` (deterministic active findings)

Each entry summarizes **one** active issue code. Derived only from existing component **`report`** payloads (no new SQL).

| Field | Description |
|-------|-------------|
| `code` | Stable issue identifier (see catalog above). |
| `severity` | **critical** or **warn** for this issue (matches catalog; not necessarily equal to parent component when multiple issues exist). |
| `component` | Source component id (`ledger_reconciliation`, …). |
| `summary` | Short deterministic sentence with key counts (**human-secondary**; prefer `code` + `report` for logic). |
| `recommended_next_checks` | Same shape as per-component **`recommended_next_checks`** for that component (investigation CLIs/docs). |

**Use:** dashboards, ticketing (`code` as label), preflight policies keyed by stable identifiers, and operator triage without scraping **`severity_reasons`**.

### Per-component value (`component_results[<id>]`)

| Key | Type | Description |
|-----|------|-------------|
| `severity` | string | `healthy` \| `warn` \| `critical` for this component. |
| `severity_reasons` | string[] | Human-readable reasons; may be empty (**not** stable keys — **codes** are canonical). |
| `active_issue_codes` | string[] | Active codes for this component only, sorted by **`ISSUE_CODE_ORDER`**; empty when component **healthy**. |
| `status_fingerprint` | string | Per-component SHA-256 hex (severity + **active_issue_codes** only). |
| `recommended_next_checks` | array | Same object shape as `recommended_next_steps` entries; **empty** when this component is **healthy**. |
| `report` | object | **Full** array from the underlying audit/report service (counts and nested structures preserved). |

Each entry in `recommended_next_steps` / `recommended_next_checks` is:

| Field | Description |
|-------|-------------|
| `readonly_cli` | Suggested command from `system/` (e.g. `php scripts/…`). |
| `ops_doc` | Path to the ops doc or module README (investigation / escalation context only — **no** automated repair). |
| `intent` | Short operator-facing note (read-only investigation). |

Component keys:

- `ledger_reconciliation` → `ProductStockLedgerReconciliationService`
- `global_sku_branch_attribution` → `ProductGlobalSkuBranchAttributionAuditService`
- `origin_classification` → `ProductStockMovementOriginClassificationReportService`
- `reference_integrity` → `ProductStockMovementReferenceIntegrityAuditService`
- `classification_drift` → `ProductStockMovementClassificationDriftAuditService`

## Severity rules (explicit)

Overall severity is the **worst** per-component severity using ordering: **healthy < warn < critical**.

### `ledger_reconciliation`

| Condition | Severity |
|-----------|----------|
| `mismatched_count == 0` | **healthy** |
| `mismatched_count > 0` | **critical** — `products.stock_quantity` does not match `SUM(stock_movements.quantity)` within ε (`1e-6`); see ledger ops for interpretation |

### `global_sku_branch_attribution`

| Condition | Severity |
|-----------|----------|
| `affected_movements_count == 0` | **healthy** |
| `affected_movements_count > 0` | **warn** — **caveat, not a defect by itself:** global catalog SKUs (`products.branch_id IS NULL`) may legitimately have `stock_movements.branch_id` set for invoice branch attribution or operator context; single on-hand column still applies (ledger + inventory README) |

### `origin_classification`

| Condition | Severity |
|-----------|----------|
| `movements_on_deleted_or_missing_product == 0` and `counts_by_origin['other_uncategorized'] == 0` | **healthy** |
| `movements_on_deleted_or_missing_product > 0` | **critical** — movements pointing at missing or soft-deleted products |
| Else if `counts_by_origin['other_uncategorized'] > 0` | **warn** — drill down with reference integrity + classification drift (reasons overlap; reference audit is referential truth) |

### `reference_integrity`

| Condition | Severity |
|-----------|----------|
| Every `counts_by_anomaly[*] == 0` | **healthy** |
| Any anomaly count `> 0` | **critical** — orphan targets, missing `product_id` row, or malformed `reference_type` / `reference_id` pairs (buckets may overlap; see reference integrity ops) |

### `classification_drift`

| Condition | Severity |
|-----------|----------|
| `other_uncategorized_total == 0` and `manual_operator_unexpected_movement_type_count == 0` | **healthy** |
| Otherwise | **warn** — non-canonical origin mix or null-reference rows with unexpected `movement_type` (legacy SQL / imports per drift ops); **not** upgraded to critical here when reference integrity already flags the same rows — operators use both reports |

## Caveats vs true anomalies (operator cheat sheet)

| Signal | Caveat (expected / contextual) | True anomaly |
|--------|------------------------------|--------------|
| Global SKU + non-null movement `branch_id` | **Warn** in consolidated audit; normal under current invoice/attribution model | N/A by itself |
| `other_uncategorized` / drift buckets | Often overlaps reference integrity; investigate | Orphan refs, wrong movement_type for reference type → **critical** when reference audit counts them |
| Ledger mismatch | Rare float edge beyond ε (documented) | Column vs movement sum drift → **critical** |
| `movements_on_deleted_or_missing_product` | — | **critical** |

## Escalation before product/inventory writes

Treat as **blockers** (resolve or explicitly accept risk **before** write work) when consolidated **`overall_health_status` is `critical`**, i.e. any of:

- Ledger mismatches
- Reference integrity anomaly counts
- Movements on deleted/missing products

When **`overall_health_status` is `warn`**, review runbook sections and individual CLIs; do not assume stock semantics are wrong solely from global SKU branch attribution or non-zero `other_uncategorized` without reading the breakdown.

## See also

- `system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md` — cross-tool invariant proof (`audit_product_stock_health_contract_coherence.php`)  
- `system/docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md` — machine advisory `proceed` / `review` / `hold` from current snapshot ± baseline  
- `system/docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md` — compare two consolidated JSON snapshots (checkpoints, pre-write review)
- `system/modules/inventory/README.md` — module-level stock rules and CLI index (**start with consolidated audit**)
- `system/README.md` — system entry pointer
- `BOOKER-PARITY-MASTER-ROADMAP.md` — wave log (`PRODUCTS-DOMAIN-HARDENING-WAVE-11`)
