# USER-ORGANIZATION-SINGLE-ORG-TRUTH — SECOND RUNTIME CONSUMER SURFACE MATRIX (FOUNDATION-59)

Read-only matrix after **FOUNDATION-57/58** (resolver = **only** HTTP `assert*` consumer).

| Surface | In audit scope? | Calls `assert*` at HTTP runtime? | Role vs org truth |
|---------|-----------------|--------------------------------|-------------------|
| `OrganizationContextResolver` | Yes | **Yes** (membership-single path only) | **Producer** of context + strict membership id (F-57) |
| `OrganizationContextMiddleware` | Pipeline ref. | No | Invokes resolver once per request |
| `StaffMultiOrgOrganizationResolutionGate` | Yes | No | Multi-org **403** when context unresolved; uses `countActiveOrganizations` + `getCurrentOrganizationId` |
| `AuthMiddleware` | Pipeline ref. | No | Invokes F-25 gate after auth |
| `OrganizationRepositoryScope` | Yes | No | **Consumer**: `resolvedOrganizationId()` ← context |
| `UserOrganizationMembershipStrictGateService` | Yes | N/A (defines `assert*`) | Membership pivot state / assert |
| `UserOrganizationMembershipReadService` | Yes | No | Resolver input reads |
| `UserOrganizationMembershipReadRepository` | Yes | No | DB read for membership |
| `OrganizationScopedBranchAssert` | Consumer ref. | No | Branch row vs resolved org |
| `BranchDirectory` | Consumer ref. | No | Branch listings / lookups vs resolved org |
| Module repos using `OrganizationRepositoryScope` | Consumer ref. | No | SQL scoping when org resolved |

**Second runtime `assert*` recommendation:** **None yet** (see ops doc §5–§7).

**Cross-reference:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-SECOND-RUNTIME-CONSUMER-SELECTION-TRUTH-AUDIT-FOUNDATION-59-OPS.md`.
