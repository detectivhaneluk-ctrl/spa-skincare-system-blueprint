# USER-ORGANIZATION — MEMBERSHIP & RUNTIME TRUTH LANE SURFACE MATRIX (FOUNDATION-64)

Consolidated **read-only** matrix through **F-63**.

| Component | Lane role | Writes? | `assert*` / alignment |
|-----------|-----------|---------|------------------------|
| `UserOrganizationMembershipReadRepository` | Safe reads; table-absent → empty | **No** | N/A |
| `UserOrganizationMembershipReadService` | Facade for resolver | **No** | N/A |
| `UserOrganizationMembershipStrictGateService` | State + **`assert*`** (read/throw) | **No** | Verifier + F-57 resolver |
| `UserOrganizationMembershipBackfillService` | CLI backfill | **INSERT** (non–dry-run) | N/A |
| `backfill_user_organization_memberships.php` | Invokes backfill | Via service | N/A |
| `audit_user_organization_membership_backfill_and_gate.php` | F-51 verifier + `assert*` | **No** | Yes (non-HTTP) |
| `audit_user_organization_membership_context_resolution.php` | F-46/F-54/F-57 ctor + parity + `assert*` | **No** | Yes (non-HTTP) |
| `register_organizations.php` | DI: read, gate, backfill | N/A | N/A |
| `modules/bootstrap.php` | **`OrganizationContextResolver`** wiring | N/A | N/A |
| `OrganizationContextResolver` | HTTP resolution + F-62 alignment | **No** | F-57 `assert*` branch-null only |
| `OrganizationContext` | Request-scoped org + modes | **No** | N/A |
| `OrganizationContextMiddleware` | Calls resolver | **No** | N/A |
| `BranchContextMiddleware` | Branch id | **No** | N/A |
| `StaffMultiOrgOrganizationResolutionGate` (F-25) | Multi-org unresolved guard | **No** | N/A |
| `OrganizationRepositoryScope` | Scope SQL from context | **No** | N/A |
| `OrganizationScopedBranchAssert` | Branch row vs context | **No** | N/A |

**Closure outcome:** **CLOSE LANE WITH WAIVERS** — see **`USER-ORGANIZATION-MEMBERSHIP-AND-RUNTIME-TRUTH-LANE-CONSOLIDATED-PROGRAM-CLOSURE-TRUTH-AUDIT-FOUNDATION-64-OPS.md`** §6–§8.
