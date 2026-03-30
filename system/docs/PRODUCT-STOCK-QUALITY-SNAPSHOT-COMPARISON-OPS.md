# Product stock quality — snapshot comparison (operations)

**Tasks:** `PRODUCTS-STOCK-QUALITY-SNAPSHOT-COMPARISON-01`, `PRODUCTS-STOCK-QUALITY-COMPARISON-RULES-01`, `PRODUCTS-STOCK-QUALITY-COMPARISON-OPS-RUNBOOK-01`  
**Scope:** Read-only comparison of **two JSON files** from the consolidated stock-health audit. **No** database access, **no** writes, **no** schema change, **no** stock behavior change.

## Purpose

Answer deterministically: **did stock-health status shape change between two saved snapshots?** Use for checkpoints, pre-write gates, post-incident review, and automation — without hand-diffing full payloads.

**Related:** canonical audit payload and fingerprints — `PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md`. **Preflight advisory** (machine `proceed` / `review` / `hold` over current + optional baseline) — `PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`.

## Producing snapshots (safe workflow)

1. From `system/`, run the consolidated audit with JSON output (no gate flags unless you intentionally want `gate_*` in the file — they are **ignored** by the comparator):
   ```bash
   php scripts/audit_product_stock_quality_consolidated_readonly.php --json > snapshots/stock_quality_before.json
   ```
2. Repeat after the event (restore, import, wave deploy, anomaly investigation):
   ```bash
   php scripts/audit_product_stock_quality_consolidated_readonly.php --json > snapshots/stock_quality_after.json
   ```
3. Store files immutably (copy to ticket, artifact store, or VCS path). **`generated_at`** is **observational** — use it to know **when** a snapshot was taken, **not** to infer semantic equality.

## Running the comparator

From `system/`:

```bash
php scripts/compare_product_stock_quality_snapshots_readonly.php --left=snapshots/stock_quality_before.json --right=snapshots/stock_quality_after.json
php scripts/compare_product_stock_quality_snapshots_readonly.php --left=before.json --right=after.json --json
```

- **`--left`**: earlier / baseline snapshot (e.g. pre-change checkpoint).  
- **`--right`**: later / candidate snapshot.  
- **`--json`**: machine-readable comparison payload only.

On some shells, **`--left=path`** / **`--right=path`** (equals form) parses more reliably than a separate argument.

**Exit code:** **0** if comparison output was produced; **1** on usage error, unreadable path, invalid JSON, or missing required consolidated fields.

## Comparison output contract (`comparison_schema_version`)

**Current:** `ProductStockQualitySnapshotComparisonService::COMPARISON_SCHEMA_VERSION` (**`1.0.0`**).

| Field | Meaning |
|-------|---------|
| `comparison_schema_version` | Version of **this** comparison payload shape. |
| `left_schema_version` / `right_schema_version` | `schema_version` from each consolidated snapshot. |
| `contract_compatible` | **`true`** only if both snapshots share the same **semver major** (first segment, e.g. `1.2.0` and `1.1.0` → compatible; `2.0.0` vs `1.9.9` → not). |
| `overall_change_status` | One of: `unchanged`, `improved`, `worsened`, `changed_same_severity`, `contract_changed` (see rules below). |
| `fingerprint_changed` | Top-level `status_fingerprint` differs. |
| `health_status_changed` | `overall_health_status` string differs. |
| `issue_codes_added` | Codes present on **right**, absent on **left** (catalog sort order). |
| `issue_codes_resolved` | Codes present on **left**, absent on **right**. |
| `persistent_issue_codes` | Intersection (catalog sort order). |
| `component_changes` | Map keyed by component id; each entry includes severity before/after, rank before/after, fingerprints before/after, `fingerprint_changed`, active issue counts, and per-component code added/resolved/persistent lists. |

## Deterministic rules (`overall_change_status`)

Evaluation order:

1. **Contract** — If **`contract_compatible`** is **`false`** → **`overall_change_status` = `contract_changed`**.  
   Do not treat other fields as a safe health regression signal across incompatible majors; re-run both sides on the same app version or compare manually.

2. **Overall health rank** — When contract is compatible, rank `overall_health_status` using consolidated ordering: **healthy (0) < warn (1) < critical (2)**.  
   - If **right rank > left rank** → **`worsened`**.  
   - If **right rank < left rank** → **`improved`**.

3. **Same rank** — If ranks are equal:  
   - If top-level **`status_fingerprint`** is **identical** → **`unchanged`**.  
   - If fingerprints **differ** → **`changed_same_severity`** (issue mix or component shape changed without crossing healthy/warn/critical at the overall rollup).

**`fingerprint_changed`** and **`health_status_changed`** are exposed explicitly for dashboards regardless of `overall_change_status`.

### Safe comparison order (automation)

1. Confirm **`comparison_schema_version`** is understood.  
2. Check **`contract_compatible`**. If false → stop or escalate; snapshot majors differ.  
3. Read **`overall_change_status`**, then **`fingerprint_changed`**, **`health_status_changed`**, issue code deltas, **`component_changes`**.  
4. Use consolidated **`active_issue_codes`**, **`issue_inventory`**, **`issue_counts_by_*`**, **`component_status_summary`** inside each snapshot when you need more detail.  
5. **`generated_at`** is **not** semantic — ignore for “did health change?”.

### Fingerprint vs magnitude (limit)

As in the consolidated ops doc: **identical `status_fingerprint` does not guarantee identical numeric magnitudes** in each component **`report`** (e.g. same issue code with different counts). When counters matter, diff **`report`** fields or re-run deep CLIs — the comparison tool does **not** interpret `report` and does not prescribe repairs.

## Operator workflows

| Scenario | Suggested use |
|----------|----------------|
| **Pre-write checkpoint** | Save `--json` snapshot before risky work; save after; compare. If **`worsened`** or **`contract_changed`**, block or escalate per local policy. |
| **Post-wave / deploy** | Baseline from last ZIP-accepted environment; compare after deploy. **`changed_same_severity`** still warrants review if **`fingerprint_changed`**. |
| **Before/after anomaly review** | Baseline before investigation; after any data touch, compare. Use **`issue_codes_added` / `resolved`** for ticket narrative. |

## Manual review triggers (before inventory / product **write** work)

Treat as **manual review required** (in addition to consolidated audit rules) when any of:

- **`overall_change_status`** is **`worsened`** or **`contract_changed`**.  
- **`contract_compatible`** is **`false`**.  
- **`fingerprint_changed`** is **`true`** while **`overall_change_status`** is **`changed_same_severity`** (status band unchanged but issue mix moved).  
- Your policy requires zero drift: even **`unchanged`** overall can mask **report** magnitude drift — use consolidated ops guidance.

This document does **not** define data repair steps — only investigation and escalation consistent with existing inventory ops.

## Implementation

- Service: `Modules\Inventory\Services\ProductStockQualitySnapshotComparisonService::compare()` and `validateConsolidatedSnapshot()` (single-file shape check; used by preflight when no baseline)  
- CLI: `system/scripts/compare_product_stock_quality_snapshots_readonly.php`  
- DI: `modules/bootstrap.php` registers the service (no DB).

## See also

- `system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md` — coherence audit (includes identical-snapshot compare invariant)  
- `system/docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md` — conservative `proceed` / `review` / `hold` advisory (reuses this compare when baseline is set)  
- `system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md` — primary audit + fingerprint contract  
- `system/modules/inventory/README.md` — inventory CLI index  
- `system/README.md` — system entry  
- `BOOKER-PARITY-MASTER-ROADMAP.md` — `PRODUCTS-DOMAIN-HARDENING-WAVE-11`
