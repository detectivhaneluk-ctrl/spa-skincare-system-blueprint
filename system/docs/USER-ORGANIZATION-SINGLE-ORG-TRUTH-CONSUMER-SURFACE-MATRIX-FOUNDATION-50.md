# USER-ORGANIZATION-SINGLE-ORG-TRUTH — CONSUMER SURFACE MATRIX (FOUNDATION-50)

Read-only matrix: **where organization truth is produced or consumed**, and **suitability for first `UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth` adoption**.

| Surface | Role in org truth | Uses membership pivot? | Tolerates null / ambiguous `OrganizationContext`? | First `assert*` adoption? |
|--------|-------------------|------------------------|---------------------------------------------------|---------------------------|
| `BranchContextMiddleware` | Sets `BranchContext` (branch before org) | No | N/A (not org id) | **No** — wrong layer / no membership semantics |
| `OrganizationContextMiddleware` | Calls `OrganizationContextResolver::resolveForHttpRequest` | Indirect (resolver, only when branch null) | Resolver sets unresolved modes | **No** — would fight branch-first + legacy fallback |
| `OrganizationContextResolver` | Canonical HTTP org resolution + modes | Yes on branch-null path only | Yes (`MODE_UNRESOLVED_*`) | **No** — see F-50 ops §5 |
| `OrganizationContext` | Holds `currentOrganizationId` + `resolutionMode` | No (storage only) | Yes (null id + mode) | **No** — not an execution choke for assert |
| `StaffMultiOrgOrganizationResolutionGate` + `AuthMiddleware` | Multi-org unresolved block | No | Blocks only when `countActiveOrganizations() > 1` and org null | **No** — uses deployment count + context id, not membership assert |
| `OrganizationRepositoryScope` | `resolvedOrganizationId()` + SQL fragments | No | **Yes** — empty fragment when unresolved | **No** — wide replication; null = legacy scope |
| `OrganizationScopedBranchAssert` | Branch row vs resolved org | No | No-op when org unresolved | **No** — asserts branch↔context, not membership-single |
| `BranchDirectory` | Branch lists/mutations vs `getCurrentOrganizationId()` | No | Uses null org as “legacy global” | **No** — high fan-out; branch-derived org without membership |
| Marketing / payroll / client repos | Org-scoped SQL when `resolvedOrganizationId()` non-null | No | **Yes** when null | **No** — wrong contract for first assert |
| `UserOrganizationMembershipReadRepository` / `ReadService` | Membership counts/lists for resolver | Yes | Empty when 087 absent | **No** — read API, not assert consumer |
| `UserOrganizationMembershipStrictGateService` | Strict state + `assert*` | Yes | `table_absent` / `none` / `multiple` throw | **Defines** assert; not “adopter” |
| `audit_user_organization_membership_context_resolution.php` | F-46 contract vs raw SQL | Yes (via service) | Table absent path | **No** for assert — focuses on read service, not `assert*` success path |
| `audit_user_organization_membership_backfill_and_gate.php` | F-48 gate + dry-run backfill | Yes | Count integrity + `assert(0)` throws | **Yes (recommended)** — extend with `assert*` when gate state is `single` (future wave) |
| `verify_organization_context_resolution_readonly.php` | Informational F-09 DB summary | N/A | Stale text vs F-46 membership | **No** |

**Single recommended first adopter row:** **`audit_user_organization_membership_backfill_and_gate.php`** (verifier extension).

**Cross-reference:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-CONSUMER-SELECTION-AND-FIRST-ADOPTION-TRUTH-AUDIT-FOUNDATION-50-OPS.md`.
