# Protected data-plane scope contract (WAVE-02)

Code-truth summary for **tenant-protected** vs **explicit global/platform** data access at the core scope layer.

## OrganizationRepositoryScope

| API | Contract |
|-----|----------|
| `branchColumnOwnedByResolvedOrganizationExistsClause()` | **Tenant-protected.** Requires positive `OrganizationContext` id and `MODE_BRANCH_DERIVED`. Returns non-empty EXISTS SQL + params. **Throws** `DomainException` with `EXCEPTION_DATA_PLANE_*` constants if not. |
| `marketingCampaignBranchOrgExistsClause()`, `payrollRunBranchOrgExistsClause()`, `payrollCompensationRuleBranchOrgExistsClause()` | Same as above (delegate). |
| `globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped()` | **Global / control-plane only.** If `resolvedOrganizationId()` is null → **`sql === ''`** (legacy deployment-wide behavior). If non-null → same EXISTS as tenant helper. Does **not** require branch-derived mode. |
| `resolvedOrganizationId()` | Introspection only: positive id or `null`. |

HTTP handling: tenant scope `DomainException` messages are listed in `HttpErrorHandler` and map to **403** (non-debug), same as resolver membership failures.

## BranchDirectory

| API | Contract |
|-----|----------|
| `getActiveBranchesForSelection()`, `listAllBranchesForAdmin()`, `getBranchByIdForAdmin()`, `createBranch()`, `updateBranch()`, `softDeleteBranch()` | **Tenant-internal.** Requires positive org id + `MODE_BRANCH_DERIVED`. **Throws** `DomainException` (`EXCEPTION_TENANT_BRANCH_CATALOG_CONTEXT`) otherwise. No global listing, no id-only admin fetch, no implicit lowest-org pin on create. |
| `listAllActiveBranchesUnscopedForTenantEntryResolver()` | **Tenant entry only** (`GET /tenant-entry` multi-branch chooser). Full active-branch list; caller filters to allowed ids. |
| `listAllBranchesIncludingDeletedGloballyForPlatformAdmin()` | **Platform / tooling.** No org filter (all branches). |
| `createBranchPinningLowestActiveOrganizationWhenContextUnresolved()` | **Bootstrap / repair.** Uses lowest active org when context is not branch-derived; if context is branch-derived, delegates to `createBranch()`. |
| `isActiveBranchId()` | Unchanged: existence check only (no org predicate). |

## Repository modules (minimal delta)

Marketing and payroll repositories no longer branch on `resolvedOrganizationId() === null` for legacy unscoped SQL; they always use tenant scope helpers (fail-closed via core).

## Intentionally unchanged this wave

- `OrganizationScopedBranchAssert` no-op when org unresolved (still used after tenant catalog gates).
- Clients / documents / sales repositories beyond existing `OrganizationRepositoryScope` usage (no broad rewrites).
- Schema, auth, UI.
