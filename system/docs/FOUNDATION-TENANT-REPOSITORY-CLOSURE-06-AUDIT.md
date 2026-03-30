# FOUNDATION-TENANT-REPOSITORY-CLOSURE-06 — Audit (ClientMembershipRepository catalog + issuance overlap + billing cycle period read)

**Scope:** Remove unscoped **`ClientMembershipRepository::list` / `count`**; add **`findBlockingIssuanceRowInTenantScope`**; tighten **`MembershipBillingCycleRepository::findByMembershipAndPeriod`** when a branch/org pin resolves. **Out of scope (defer with proof):** repair **`find` / `findForUpdate`** when org anchor unset. **Follow-on:** **`update` / renewal scan** — **FOUNDATION-TENANT-REPOSITORY-CLOSURE-07** (`FOUNDATION-TENANT-REPOSITORY-CLOSURE-07-AUDIT.md`).

## 1. `ClientMembershipRepository` list / count

| # | Item | Before | After (wave) |
|---|------|--------|----------------|
| 1 | SQL | `WHERE 1=1` + optional filters only | **Removed** — no public unscoped catalog |
| 2 | HTTP index | Already **`listInTenantScope` / `countInTenantScope`** | Unchanged |
| 3 | Tier | **A footgun** | **Closed** — only scoped list/count APIs remain |

## 2. `findBlockingIssuanceRow` → `findBlockingIssuanceRowInTenantScope`

| # | Item | Before | After |
|---|------|--------|--------|
| 1 | SQL | `FROM client_memberships` overlap only | Same overlap + **org EXISTS** predicate on **`cm`** (branch pin + null-branch client org anchor) |
| 2 | Caller | **`MembershipService::assignToClientAuthoritative`** | Resolves **`$overlapBranchCtx`** (context → issuance → client branch); **throws** if unset |
| 3 | Tier | **A** | **Closed** |

## 3. `MembershipBillingCycleRepository::findByMembershipAndPeriod`

| # | Item | Before | After |
|---|------|--------|--------|
| 1 | SQL | `SELECT * FROM membership_billing_cycles WHERE …` | When **`$branchContextId`** or org any-branch resolves: **`INNER JOIN client_memberships cm`** + same **`cm`** org predicate; else **repair** id-only row |
| 2 | Caller | **`MembershipBillingService::processDueRenewalSingle`** | Passes due-list pin → row **`branch_id`** → **`BranchContext`** |
| 3 | Tier | **A** (id + period key) | **Closed** on hot path |

**Proof:** `verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

**Deferred:** **`ClientMembershipRepository::update`**, renewal scan list, repair id-only reads — see `TENANT-SAFETY-INVENTORY-CHARTER-01.md`.
