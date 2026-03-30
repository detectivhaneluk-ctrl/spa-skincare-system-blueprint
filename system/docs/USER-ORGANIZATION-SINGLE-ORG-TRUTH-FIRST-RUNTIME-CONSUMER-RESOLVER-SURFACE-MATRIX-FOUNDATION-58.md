# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST RUNTIME CONSUMER RESOLVER SURFACE MATRIX (FOUNDATION-58)

Post-**FOUNDATION-57** closure matrix (read-only).

| Surface | Modified in F-57? | Calls `assert*` at runtime? | Precedence / behavior vs F-46 baseline |
|---------|-------------------|----------------------------|----------------------------------------|
| `OrganizationContextResolver` | **Yes** | **Yes** (membership-single path only) | Branch → membership (with assert) → ambiguous → legacy fallback — **unchanged order** |
| `modules/bootstrap.php` | **Yes** (resolver DI) | No | N/A |
| `register_organizations.php` | **No** (pre-existing gate binding) | No | N/A |
| `audit_user_organization_membership_context_resolution.php` | **Yes** (ctor ≥4) | Yes (F-54 verifier only) | Verifier truth only |
| `UserOrganizationMembershipStrictGateService` | **No** | Defines `assert*` | Read/throw only |
| `UserOrganizationMembershipReadService` | **No** | No | Unchanged |
| `UserOrganizationMembershipReadRepository` | **No** | No | Read-only |
| `StaffMultiOrgOrganizationResolutionGate` | **No** | No | Unchanged |
| `OrganizationRepositoryScope` | **No** | No | Unchanged |

**Cross-reference:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-RUNTIME-CONSUMER-RESOLVER-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-58-OPS.md`.
