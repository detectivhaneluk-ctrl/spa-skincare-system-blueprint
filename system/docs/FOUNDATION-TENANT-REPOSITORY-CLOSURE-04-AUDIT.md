# FOUNDATION-TENANT-REPOSITORY-CLOSURE-04 — Audit (F-12 membership remainder)

**Scope:** `MembershipDefinitionRepository` unscoped find/list/count (and adjacent list helpers), `MembershipBillingCycleRepository` id-only find/findForUpdate, service-layer `definitions->find` pairing. **Out of scope (defer with proof):** `MembershipBillingCycleRepository::findByMembershipAndPeriod` (period + membership id, not this wave’s id-only cycle PK surface); public-commerce definition reads (`findBranchOwnedPublicPurchasable`, `listPublicPurchasableForBranch`) — intentional branch-bound guest contract per ORG-SCOPED audit. **`ClientMembershipRepository` id-read/lock:** see **CLOSURE-05**.

## 1. `MembershipDefinitionRepository::find` / `list` / `count` / `listActiveForBranch`

| # | Question | Before | After (wave) |
|---|----------|--------|----------------|
| 1 | Intrinsic SQL scope | **None** — raw `SELECT * … WHERE id = ?`; list/count global | **`find` / `findBranchOwnedInResolvedOrganization`:** `md.branch_id IS NOT NULL` + `branchColumnOwnedByResolvedOrganizationExistsClause('md')`. **`list` / `count`:** same org EXISTS on `md`; `branch_scope=global` → empty (NULL-branch rows not org-anchored). **`listActiveForBranch`:** branch + org EXISTS |
| 2 | Caller-dependent safety | HTTP used `*InTenantScope`; repo still allowed cross-tenant id guess | Repo **fail-closed** without branch-derived org (`AccessDeniedException` → null / [] / 0) |
| 3 | Id-only read | **Yes** on `find` | **No** for branch-owned path; cross-org id returns null |
| 4 | Intentional global | **`list` branch_scope global** listed NULL-branch rows — **not** provably org-scoped | **Closed:** returns `[]` / `0` under tenant context |
| 5 | Tier | **A — fix** | **Closed** |
| 6 | Proof | — | `verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php` |

## 2. `MembershipDefinitionRepository::findForClientMembershipContext` (new)

| # | Question | Answer |
|---|----------|--------|
| 1 | Scope | `cm.branch_id > 0` → `findInTenantScope`; `cm.branch_id` NULL → `INNER JOIN clients c` + `clientProfileOrgMembershipExistsClause('c')` |
| 2 | Caller | **MembershipBillingService** replaces raw `definitions->find(defId)` after loading `cm` |
| 3 | Tier | **A** |

## 3. `MembershipBillingCycleRepository::find` / `findForUpdate`

| # | Question | Before | After |
|---|----------|--------|-------|
| 1 | Intrinsic scope | **None** | **`findInInvoicePlane` / `findForUpdateInInvoicePlane`:** `INNER JOIN invoices i` + `invoicePlaneExistsClauseForMembershipReconcileQueries('i')` |
| 2 | Correlation | N/A | **`findForInvoice` / `findForUpdateForInvoice`:** `mbc.invoice_id` + `i.id` match (used in settlement) |
| 3 | Pending cycles (no invoice) | Raw find returned row | **Join returns null** — acceptable for settlement paths (invoiced cycles only) |
| 4 | Tier | **A** | **Closed** |

## 4. Services

| Path | Before | After |
|------|--------|-------|
| `MembershipSaleService` activation | `definitions->find($defId)` | `findInTenantScope($defId, $resBranch)` (after snapshot/branch proof) |
| `MembershipService::assignToClientAuthoritative` | Early unscoped `find`; null-issuance branch used `find` for denial | Snapshot: `findInTenantScope` with snap branch; denial: `findBranchOwnedInResolvedOrganization` |
| `MembershipBillingService` | `definitions->find` ×3 | `findForClientMembershipContext` ×3; cycle reads use `findForInvoice` / `findForUpdateForInvoice` where invoice id known |

## 5. Tier B — defer (documented)

- **`ClientMembershipRepository::find` / `findForUpdate` / `lockWithDefinition*`** — **CLOSURE-05** (`FOUNDATION-TENANT-REPOSITORY-CLOSURE-05-AUDIT.md`, `verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php`).
- **`MembershipBillingCycleRepository::findByMembershipAndPeriod`** — idempotent renewal insert guard; consider invoice/cm org binding in a follow-up if treated as tenant-hot.

**Verifier:** `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php`
