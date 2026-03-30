# FOUNDATION-TENANT-REPOSITORY-CLOSURE-05 — Audit (ClientMembershipRepository id-read/lock)

**Scope:** `ClientMembershipRepository` id-only **`find`**, **`findForUpdate`**, **`lockWithDefinition`**, **`lockWithDefinitionForBilling`** and tightly related service call sites. **Out of scope (defer with proof):** unscoped **`list` / `count`** (no `*InTenantScope` closure in this wave); **`findBlockingIssuanceRow`** (client_id + definition + branch scope, not PK id-only); **`listActiveNonExpiredForRenewalScan`** (cron-wide scan); **`MembershipBillingCycleRepository::findByMembershipAndPeriod`** (period composite key).

## 1. `ClientMembershipRepository::find` / `findForUpdate`

| # | Item | Before | After (wave) |
|---|------|--------|----------------|
| 1 | SQL scope | **None** — `WHERE cm.id = ?` (+ joins on find); `SELECT * … FOR UPDATE` on findForUpdate | **Branch-derived org:** `getAnyLiveBranchIdForResolvedTenantOrganization()` → **`findInTenantScope` / `findForUpdateInTenantScope`**. **Repair/cron:** legacy read/lock when org anchor unavailable |
| 2 | Caller risk | Full cross-tenant id guess | Tenant HTTP paths use **explicit branch** + **`findInTenantScope`** in services |
| 3 | Tier | **A** | **Closed** (intrinsic org gate when org resolves) |

## 2. `ClientMembershipRepository::lockWithDefinition` / `lockWithDefinitionInTenantScope`

| # | Item | Before | After |
|---|------|--------|-------|
| 1 | SQL | Id-only `FOR UPDATE` | **`lockWithDefinitionInTenantScope`:** same predicate family as **`findForUpdateInTenantScope`**. **`lockWithDefinition`:** org anchor when resolved, else repair lock |
| 2 | Service | **`consumeBenefitForAppointment`** used raw lock | **`lockWithDefinitionInTenantScope`** with appointment or context branch pin |
| 3 | Tier | **A** | **Closed** |

## 3. `ClientMembershipRepository::lockWithDefinitionForBilling`

| # | Item | Before | After |
|---|------|--------|-------|
| 1 | SQL | Id-only `FOR UPDATE` | **`lockWithDefinitionForBillingInTenantScope(id, branchContextId)`** + **`lockWithDefinitionForBilling(id, ?membershipBranchId)`** — membership branch pin from **`listDueClientMembershipIds`**, else org any-branch, else repair |
| 2 | Caller | **`processDueRenewalSingle`** | Passes **`branch_id`** from due list row |
| 3 | Tier | **A** | **Closed** |

## 4. `MembershipBillingCycleRepository::listDueClientMembershipIds`

| # | Item | Before | After |
|---|------|--------|-------|
| 1 | Return | `list<int>` ids only | **`list<array{id, branch_id}>`** so renewal lock uses membership branch pin |
| 2 | Tier | **Related** | **Closed** |

## 5. Services (tight)

| Service | Change |
|---------|--------|
| **`MembershipBillingService`** | **`BranchContext`** + **`clientMembershipReadForSettlement`** (invoice branch → context → repair **`find`**); **`initializeAfterAssign(id, branchContextId)`**; **`patchPrimaryMembershipAfterVoid`** / **`branchIdForClientMembership`** / **`maybeLogSettlementSynced`** invoice hints; **`settleFullyPaidRenewal` / `applyRefundReviewState`** scoped reads |
| **`MembershipService`** | Post-create **`findInTenantScope`**; **`findClientMembership(id, ?branch)`**; benefit **`lockWithDefinitionInTenantScope`** |
| **`MembershipSaleService`** | **`findClientMembership($cmId, $resBranch)`** after activation |
| **`MembershipLifecycleService`** | Post-mutation **`findInTenantScope`**; **`initializeAfterAssign`**, branch-pinned **`findForUpdateInTenantScope`** in expiry/sync when possible |

**Proof:** `verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php`

**Follow-on (CLOSURE-06 / FND-TNT-12):** unscoped **`list` / `count` removed**; **`findBlockingIssuanceRowInTenantScope`** added — see `FOUNDATION-TENANT-REPOSITORY-CLOSURE-06-AUDIT.md`.

**Follow-on (CLOSURE-07 / FND-TNT-13):** scoped **`updateInTenantScope`** + **`updateRepairOrUnscopedById`**; **`listActiveNonExpiredForRenewalScanGlobalOps`** — see `FOUNDATION-TENANT-REPOSITORY-CLOSURE-07-AUDIT.md`.
