# USER-ORGANIZATION — BRANCH-TO-MEMBERSHIP TRUTH ALIGNMENT SURFACE MATRIX (FOUNDATION-60)

| Surface | Reads `user_organization_memberships` on branch-derived HTTP path? | Compares branch org to membership org set? | Notes |
|---------|---------------------------------------------------------------------|---------------------------------------------|--------|
| `OrganizationContextResolver` (branch block) | **No** — returns after `setFromResolution` (`MODE_BRANCH_DERIVED`) | **No** | **Only** place branch-derived org is chosen |
| `OrganizationContextResolver` (membership block) | **Yes** (via read service) | N/A — runs **only** when branch id **null** | F-57 `assert*` on single-membership success |
| `BranchContextMiddleware` | No | No | Sets `BranchContext` only |
| `BranchContext` | No | No | Holds current branch id |
| `OrganizationScopedBranchAssert` | No | No | Branch row org vs **context** org |
| `OrganizationContext::assertBranchBelongsToCurrentOrganization` | No | No | Same-org check for a branch row |
| `StaffMultiOrgOrganizationResolutionGate` | No | No | Org resolved vs multi-org deployment |
| `OrganizationRepositoryScope` | No | No | Mirrors `OrganizationContext` |
| `UserOrganizationMembershipReadService` / `UserOrganizationMembershipReadRepository` | Used by resolver **not** on branch path | N/A | F-46 empty when table absent |
| `UserOrganizationMembershipStrictGateService` | Indirect — resolver only on branch-null single path | N/A | `assert*` = single-membership only |
| `BranchDirectory` | No | No | Lists filtered by **resolved** org id |

**Future alignment (if tasked):** **Resolver-only** gate on branch success path — see ops doc §7.

**Cross-reference:** `USER-ORGANIZATION-BRANCH-TO-MEMBERSHIP-TRUTH-ALIGNMENT-NEED-AND-RESOLVER-BOUNDARY-TRUTH-AUDIT-FOUNDATION-60-OPS.md`.
