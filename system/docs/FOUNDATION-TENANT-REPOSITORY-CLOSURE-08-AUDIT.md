# FOUNDATION-TENANT-REPOSITORY-CLOSURE-08 — audit

**Scope:** **`MembershipLifecycleService::runExpiryPass`** (memberships cron / `MembershipService::markExpired`). **Wave ID:** **FND-TNT-14**. **Date:** 2026-03-29.

## Risk addressed

Prior path: wide **`SELECT id, branch_id, … FROM client_memberships WHERE …`** without org/branch anchor; **`findForUpdate($id)`** when **`branch_id`** was **NULL** — ID-guessing / unscoped listing surface for control-plane code.

## Closure

- **`OrganizationRepositoryScope::clientMembershipRowAnchoredToLiveOrganizationSql`:** parenthetical predicate — branch-pinned row’s branch exists and joins non-deleted **organizations**; **NULL** **`branch_id`** arm requires client home branch + org.
- **`ClientMembershipRepository::listExpiryTerminalCandidatesForGlobalCron`:** operational definition join + anchor + **`lock_branch_id`** = **`COALESCE(NULLIF(cm.branch_id,0), c.branch_id)`**; optional **`cm.branch_id = ?`** for per-branch cron.
- **`runExpiryPass`:** uses repository list; **`findForUpdateInTenantScope($id, $lockBranch)`** only; post-update read **`findInTenantScope($id, $lockBranch)`**.

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_14_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (unchanged this wave)

- **`syncLifecycleFromCanonicalTruth`** repair **`findForUpdate`** when no branch context — separate promotion if needed.
