# FOUNDATION-TENANT-REPOSITORY-CLOSURE-10 — audit

**Scope:** Single hotspot — **`ClientRepository::lockActiveByEmailBranch`** (anonymous public client resolution email match). **Wave ID:** **FND-TNT-16** (tenant repository closure series). **Date:** 2026-03-29.

## Risk addressed

**Branch-only predicate** (`branch_id <=> ?`) could match rows whose **`branch_id` FK** pointed at a **soft-deleted branch** or **dead organization** — same email key without proving the branch is a **live tenant slice**. Non-positive **`branch_id`** was not rejected at the repository boundary.

## Closure

- **`OrganizationRepositoryScope::publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause`:** live **`branches`** + live **`organizations`** **`EXISTS`** on the client row’s **`branch_id`**, **without** **`OrganizationContext`** (explicit anonymous-public contract).
- **`ClientRepository::lockActiveByEmailBranch`:** returns **`null`** when **`$branchId <= 0`**; both normalized and legacy SQL paths use alias **`c`** and append the scope fragment.

## Proof

- `system/scripts/read-only/verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`).

## Out of scope (unchanged at wave 10)

- Phone parity — closed in **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-11`** (**FND-TNT-17**).
- Staff **`clientProfileOrgMembershipExistsClause`** surfaces — unchanged.
