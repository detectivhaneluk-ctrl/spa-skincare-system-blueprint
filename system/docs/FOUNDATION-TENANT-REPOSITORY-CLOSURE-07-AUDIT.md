# FOUNDATION-TENANT-REPOSITORY-CLOSURE-07 — Audit (ClientMembershipRepository mutation + renewal scan contract)

**Scope:** **`ClientMembershipRepository`** `UPDATE` safety and renewal reminder **read** contract; **`MembershipBillingService`** / **`MembershipLifecycleService`** call sites. **Out of scope:** **`MembershipLifecycleService::runExpiryPass`** candidate listing SQL (still `SELECT … FROM client_memberships` without org — separate wave if promoted); other modules’ repositories.

## 1. `ClientMembershipRepository` mutation

| # | Item | Before | After (wave) |
|---|------|--------|----------------|
| 1 | API | **`update(int $id)`** — `WHERE id = ?` only | **`updateInTenantScope(id, data, branchContextId)`** — `UPDATE client_memberships cm … WHERE cm.id = ? AND` org predicate on **`cm`**; **`updateRepairOrUnscopedById(id, data)`** — explicit repair/cron |
| 2 | HTTP lifecycle | **`findForUpdateInTenantScope`** then **`update($id)`** mismatch | **`updateInTenantScope`** with same **`$branchCtx`** |
| 3 | Billing settlement | Id-only **`update`** after scoped reads | **`applyClientMembershipColumnPatch`** / **`applyClientMembershipPatchWithOptionalPin`** (invoice branch → row branch → `BranchContext` → org any-branch → else repair) |
| 4 | Expiry cron | Id-only **`update`** after lock | **`updateClientMembershipAfterExpiryLock`** — row branch pin or org any-branch, else repair |

## 2. Renewal reminder scan

| # | Item | Before | After |
|---|------|--------|--------|
| 1 | Method name | **`listActiveNonExpiredForRenewalScan`** (ambiguous) | **`listActiveNonExpiredForRenewalScanGlobalOps`** — docblock: cross-tenant cron only |
| 2 | Caller | **`MembershipService::dispatchRenewalReminders`** | Unchanged behavior; honest naming |

**Proof:** `verify_tenant_closure_wave_fnd_tnt_13_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

**Deferred (addressed in CLOSURE-08 / FND-TNT-14):** **`runExpiryPass`** candidate listing — see **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-08-AUDIT.md`**. **Still deferred:** broader F-12 `find($id)` surfaces outside memberships.
