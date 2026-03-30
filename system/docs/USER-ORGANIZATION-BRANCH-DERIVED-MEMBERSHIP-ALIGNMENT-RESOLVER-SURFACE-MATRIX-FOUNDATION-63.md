# USER-ORGANIZATION — BRANCH-DERIVED MEMBERSHIP ALIGNMENT RESOLVER SURFACE MATRIX (FOUNDATION-63)

Post–**FOUNDATION-62** closure matrix (read-only audit).

| Surface | Modified for F-62? | Branch alignment / `assert*` |
|---------|-------------------|------------------------------|
| `OrganizationContextResolver` | **Yes** | Branch path: **`enforceBranchDerivedMembershipAlignmentIfApplicable`**; **no** **`assert*`** there. Branch-null path: **`assert*`** only on membership-single success (F-57). |
| `audit_user_organization_membership_context_resolution.php` | **No** | Ctor + read parity + optional F-54 **`assert*`** — does not assert branch alignment. |
| `BranchContextMiddleware` | **No** | Branch selection only. |
| `OrganizationScopedBranchAssert` | **No** | Branch row vs context org. |
| `OrganizationRepositoryScope` | **No** | Mirrors context. |
| `StaffMultiOrgOrganizationResolutionGate` | **No** | Multi-org unresolved guard. |
| `UserOrganizationMembershipReadService` / repository | **No** (F-62) | Read-only inputs to alignment. |
| `UserOrganizationMembershipStrictGateService` | **No** (F-62) | **`assert*`** used only from branch-null F-57 block in resolver. |

**Cross-reference:** `USER-ORGANIZATION-BRANCH-DERIVED-MEMBERSHIP-ALIGNMENT-RESOLVER-ONLY-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-63-OPS.md`.
