# Inventory Module

Phase 4 foundation for inventory, scoped to:
- products
- suppliers
- stock movements
- inventory counts

## Allowed values

### Product types
- `retail`
- `professional`
- `consumable`

### Movement types
- `purchase_in`
- `manual_adjustment`
- `internal_usage`
- `damaged`
- `count_adjustment`
- `sale` (invoice settlement; system-generated)
- `sale_reversal` (invoice settlement restore; system-generated)

## Stock update rules

- `products.stock_quantity` is the current on-hand quantity.
- `stock_movements` is the source of truth for quantity changes.
- Signed movement quantity is stored:
  - `purchase_in`: always positive
  - `manual_adjustment`: positive or negative as entered
  - `internal_usage`, `damaged`: always negative
  - `count_adjustment`: signed variance from count
  - `sale` / `sale_reversal`: invoice-linked settlement (see `InvoiceStockSettlementService`)
- **Invoice product lines (`invoice_items` type `product`):** which products may appear on which invoice branch is defined in `InvoiceProductStockBranchContract` (enforced in `InvoiceService` on create/update and again in settlement). Global catalog products may be sold on branch invoices; branch-scoped products only on matching branch invoices; global (HQ) invoices only allow global products. Settlement still sets `stock_movements.branch_id` to the invoice branch for branch invoices (attribution), while on-hand remains the product’s single `stock_quantity`. This is **not** the same rule as non-invoice movements: the strict “movement `branch_id` must match a branch-scoped product’s `branch_id`” check applies to manual and other non-invoice flows; invoice `sale` / `sale_reversal` follow this contract instead and may attribute the movement to the invoice branch when the SKU is global.
- **Non-negative policy** (see `ProductStockQuantityPolicy`): `sale`, `internal_usage`, and `damaged` cannot drive on-hand below zero; insufficient quantity throws before the movement is written. `manual_adjustment` and `count_adjustment` may result in negative on-hand (explicit correction / count truth). `purchase_in` and `sale_reversal` only increase on-hand.
- Product rows are locked (`SELECT … FOR UPDATE`) while a movement is applied so concurrent deductions serialize on the same SKU.

## Inventory count rules

- Count captures `expected_quantity`, `counted_quantity`, and computed `variance_quantity`.
- Optional adjustment creates a `count_adjustment` stock movement linked by:
  - `reference_type = inventory_count`
  - `reference_id = inventory_counts.id`
- Applying adjustment requires `inventory.adjust`.

## Temporary branch behavior

- All inventory entities support nullable `branch_id`.
- UI filters support:
  - specific branch
  - global only (`branch_id IS NULL`)
  - all branches (explicit mixed view)
- For **non-invoice** movements, when a product is branch-specific, the movement’s `branch_id` must equal the product’s `branch_id`. Invoice `sale` / `sale_reversal` rows may use the **invoice** branch for attribution even when the SKU is global (see `InvoiceProductStockBranchContract` and the bullet above).

## Stock ledger reconciliation (read-only)

**Consolidated stock quality audit (primary stock-health entry point — start here):** `php scripts/audit_product_stock_quality_consolidated_readonly.php` (`--json`) — one read-only pass composing ledger reconciliation, global SKU branch attribution, origin classification, reference integrity, and classification drift with **healthy / warn / critical** rules; payload includes **`schema_version`**, **`status_fingerprint`** (and per-component fingerprints) for cross-run diffing, canonical **`active_issue_codes`** / **`issue_inventory`**, normalized rollups (**`issue_counts_by_*`**, **`component_status_summary`**), **`recommended_next_steps`**, and per-component **`recommended_next_checks`** when not healthy. Optional preflight gates: **`--fail-on-critical`**, **`--fail-on-warn`** (exit **2** when the gate fails; default exit code ignores health). **Compare two saved JSON snapshots (no DB):** `php scripts/compare_product_stock_quality_snapshots_readonly.php --left=<before.json> --right=<after.json> [--json]` — `system/docs/PRODUCT-STOCK-QUALITY-SNAPSHOT-COMPARISON-OPS.md`. **Preflight advisory (policy-only `proceed` / `review` / `hold`, no DB):** `php scripts/evaluate_product_stock_quality_preflight_readonly.php --current=<file.json> [--baseline=<checkpoint.json>] [--json]` — `system/docs/PRODUCT-STOCK-QUALITY-PREFLIGHT-ADVISORY-OPS.md`. **Contract coherence proof (read-only; exit 0 only if all invariants pass):** `php scripts/audit_product_stock_health_contract_coherence.php` — `system/docs/PRODUCT-STOCK-HEALTH-CONTRACT-COHERENCE-OPS.md`. **Runbook, fingerprint contract, issue codes, and severity table:** `system/docs/PRODUCT-STOCK-QUALITY-CONSOLIDATED-OPS.md`.

**Operational depth (read-only; invoice settlement + negative on-hand):** `system/docs/INVENTORY-OPERATIONAL-DEPTH-READONLY-OPS.md` — **`php scripts/report_product_invoice_stock_settlement_drilldown_readonly.php`** (`--invoice-id=`, `--json`) for per–invoice-item settlement target vs `sale`/`sale_reversal` net; **`php scripts/report_product_negative_on_hand_exposure_readonly.php`** (`--json`) for `stock_quantity < 0` exposure classes. No writes; does not replace consolidated stock-health audits.

**Refund / return settlement visibility (read-only; scoped lines):** `system/docs/INVENTORY-REFUND-RETURN-SETTLEMENT-VISIBILITY-OPS.md` — **`php scripts/audit_product_invoice_refund_return_settlement_visibility_readonly.php`** (`--invoice-id=`, `--json`): non-paid or `sale_reversal`-touching product lines, `visibility_class` + timestamps/totals; does **not** prove physical return.

**Internal usage vs service-consumption boundary (read-only):** `system/docs/INVENTORY-INTERNAL-USAGE-SERVICE-CONSUMPTION-BOUNDARY-OPS.md` — **`php scripts/audit_product_internal_usage_service_consumption_boundary_readonly.php`** (`--product-id=`, `--json`): classifies every `internal_usage` movement by reference shape vs current writer contracts; does **not** prove service-level product consumption.

**Product catalog reference coverage (read-only; normalized category + brand):** `system/docs/PRODUCT-CATALOG-REFERENCE-COVERAGE-OPS.md` — **`php scripts/audit_product_catalog_reference_coverage_readonly.php`** (`--product-id=`, `--json`): per live product, FK presence, soft-delete state, and `ProductTaxonomyAssignabilityService` branch pairing truth; ignores legacy `products.category` / `products.brand` strings.

**Legacy vs normalized taxonomy coherence (read-only):** `system/docs/PRODUCT-LEGACY-NORMALIZED-TAXONOMY-COHERENCE-OPS.md` — **`php scripts/audit_product_legacy_normalized_taxonomy_coherence_readonly.php`** (`--product-id=`, `--json`): compares legacy `category`/`brand` strings to normalized taxonomy names (trim + case-insensitive); does **not** repair or backfill.

**Active product domain readiness (read-only; `is_active = 1` only):** `system/docs/ACTIVE-PRODUCT-DOMAIN-READINESS-OPS.md` — **`php scripts/audit_active_product_domain_readiness_readonly.php`** (`--product-id=`, `--json`): composes catalog reference coverage + legacy/normalized coherence + SKU/name identity; **not** storefront or mixed-sales.

**Active product inventory readiness matrix (read-only; active products + on-hand + negative exposure):** `system/docs/ACTIVE-PRODUCT-INVENTORY-READINESS-MATRIX-OPS.md` — **`php scripts/audit_active_product_inventory_readiness_matrix_readonly.php`** (`--product-id=`, `--json`): composes domain readiness audit + negative on-hand exposure report + `stock_quantity`; **no** repairs, **no** storefront or mixed-sales.

**Active product operational gate (read-only; matrix + consolidated stock health + preflight):** `system/docs/ACTIVE-PRODUCT-OPERATIONAL-GATE-OPS.md` — **`php scripts/audit_active_product_operational_gate_readonly.php`** (`--product-id=`, `--json`): one per-product gate class from inventory readiness + attributed consolidated examples + hold-only preflight blocking; **no** publish or mixed-sales.

Operators and DBAs can verify **`products.stock_quantity` = Σ `stock_movements.quantity`** (per non-deleted product, ε = `1e-6`) without mutating data. **Purpose, formula, limits, and mismatch interpretation:** `system/docs/PRODUCT-STOCK-LEDGER-RECONCILIATION-OPS.md`. CLI: `php scripts/audit_product_stock_ledger_reconciliation.php` (from `system/`).

**Branch attribution audit (global SKUs):** `php scripts/audit_product_global_sku_branch_attribution_readonly.php` — lists non-deleted global products (`products.branch_id IS NULL`) that have any movement with `stock_movements.branch_id IS NOT NULL` (expected under invoice settlement and branch-scoped operator context; useful before any future per-branch stock work).

**Reference integrity (read-only):** `php scripts/audit_product_stock_movement_reference_integrity_readonly.php` — orphan `invoice_item` / `inventory_count` / `product` reference targets and malformed `reference_type` / `reference_id` pairs (`system/docs/PRODUCT-STOCK-MOVEMENT-REFERENCE-INTEGRITY-OPS.md`).

**Origin `other_uncategorized` drift (read-only):** `php scripts/audit_product_stock_movement_classification_drift_readonly.php` — mutually exclusive reasons within `other_uncategorized` plus `manual_operator_entry` rows whose `movement_type` is outside operator form allowance (`system/docs/PRODUCT-STOCK-MOVEMENT-CLASSIFICATION-DRIFT-OPS.md`).

**Movement origin classification (rollup):** `php scripts/report_product_stock_movement_origin_classification_readonly.php` — counts every movement row by practical origin using `movement_type` + `reference_type`/`reference_id` rules documented in `system/docs/PRODUCT-STOCK-MOVEMENT-ORIGIN-CLASSIFICATION-OPS.md`.

## Out of scope (next phases)

- purchase orders workflow
- lot/batch and expiry tracking
- barcode scanner logic
- warehouse transfer workflows
- advanced inventory analytics
