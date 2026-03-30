# Tenant constitution (operational)

Platform-invariant reference for this repository. Controllers must not be the only place tenant rules live; repositories and proofs enforce naming and SQL shape.

## Context inputs and outputs

| Construct | Set by | Consumed by | Meaning |
|-----------|--------|-------------|---------|
| `BranchContext::getCurrentBranchId()` | `BranchContextMiddleware` (session + membership; **cleared** for platform principals) | Services, `AuditService` (default branch_id), branch asserts | Current **active** branch for tenant-plane requests; `null` = no resolved branch (HQ / platform / unscoped reads per policy). |
| `OrganizationContext` | `OrganizationContextMiddleware` after branch (or single-org fallback) | `OrganizationRepositoryScope`, org-scoped asserts | Resolved **organization** for tenant data plane; drives EXISTS / union fragments. |
| `OrganizationRepositoryScope` | Stateless helper (injected) | Repositories | Canonical SQL fragments for “row belongs to resolved tenant org” (branch-owned ∪ org-global-null catalog rules, client profile proofs, etc.). |

There is no separate `TenantContext` type: **tenant plane = OrganizationContext + BranchContext + scope fragments**.

## `branch_id` null / global semantics

- **Branch-scoped rows:** positive `branch_id` must match operator branch when context is set (`BranchContext::assertBranchMatchStrict` / service-level equivalents).
- **Org-global catalog rows:** `branch_id IS NULL` may be visible where explicitly allowed by a **named** `OrganizationRepositoryScope` union (e.g. product/intake/document catalog helpers). Never widen with a hand-rolled `(x.branch_id = ? OR x.branch_id IS NULL)` in protected modules without review + proof allowlist.
- **Public / token flows:** use explicitly named repository methods (`*ForPublicTokenFlow`, graph-cohesion predicates), not staff-scoped helpers.

## SQL ownership by layer

| Layer | Raw SQL |
|-------|---------|
| **Repositories** | Yes — all tenant predicates should live here (fragments, joins, WHERE). |
| **Services** | Prefer repository calls; avoid new ad-hoc SQL except tightly scoped internal transactions already in this codebase. |
| **Controllers** | No SQL. |

## Repository method naming (approved patterns)

Use names that state **who** and **scope**:

- Staff / tenant UI: `findInTenantScopeForStaff`, `listInTenantScopeForStaff`, `updateInTenantScopeForStaff`, `createInTenantScopeForStaff`, `softDeleteInTenantScopeForStaff`, `countInTenantScopeForStaff`.
- Resolved-org marketing/catalog (operation branch): `*InTenantScopeForStaff` with `OrganizationRepositoryScope` inside (same idea, org-derived).
- Public / anonymous: `*ForPublicTokenFlow`, `*WithPublicGraphOrgCohesion`, etc.
- **Deprecated / id-only:** suffix or docblock `@deprecated` + readonly gate must prevent tenant-module calls (see inventory/product closure proofs).

Ambiguous `find($id)`, `update($id)`, `delete($id)`, `listByX` without scope are **not** approved for tenant-facing modules.

## Release gates

- `run_mandatory_tenant_isolation_proof_release_gate_01.php` (Tier A includes tenant + founder barriers).
- `verify_null_branch_catalog_patterns.php` and foundation platform invariant scripts for protected trees.
