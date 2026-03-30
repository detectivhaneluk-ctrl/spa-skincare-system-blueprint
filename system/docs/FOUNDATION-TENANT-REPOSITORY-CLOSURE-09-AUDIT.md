# FOUNDATION-TENANT-REPOSITORY-CLOSURE-09 — audit

**Scope:** Anonymous **`/api/public/commerce/*`** invoice satellite reads + fulfillment reconcile bootstrap. **Wave ID:** **FND-TNT-15**. **Date:** 2026-03-29.

## Risk addressed

**`InvoiceRepository::find`** applies **`SalesTenantScope::invoiceClause`**, which requires **`OrganizationContext::MODE_BRANCH_DERIVED`**. Anonymous public JSON requests typically lack that mode → **`AccessDeniedException`** or inconsistent behavior vs token/purchase branch truth.

## Closure

- **`InvoiceRepository::findForPublicCommerceCorrelatedBranch($invoiceId, $branchId)`:** `i.id` + **`i.branch_id = $branchId`** + live **`branches`/`organizations`** **`EXISTS`** (no session-derived org clause).
- **`PublicCommercePurchaseRepository::findBranchIdPinByInvoiceId`:** reconcile/recovery bootstrap when tenant **`find`** is denied.
- **`PublicCommerceService`:** **`initiatePurchase`** response, **`finalizePurchase`**, **`getPurchaseStatus`** use correlated read with purchase/initiate **`branch_id`**.
- **`PublicCommerceFulfillmentReconciler`** / **`PublicCommerceFulfillmentReconcileRecoveryService`:** try tenant **`find`**; on **`AccessDeniedException`** only, pin + correlated read (no fallback when **`find`** returns null without throw).

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_15_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (unchanged)

- **`PublicCommerceService::staffTrustedFulfillmentSync`** remains **`invoiceRepo->find`** (authenticated tenant session).
