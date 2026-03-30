# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST RUNTIME CONSUMER SURFACE MATRIX (FOUNDATION-56)

Read-only matrix: **runtime org truth** vs **`assertSingleActiveMembershipForOrgTruth`** suitability for **first** HTTP-coupled adoption.

| Surface | Authors / reads `OrganizationContext` | Uses `UserOrganizationMembershipReadService` | Has `userId` for `assert*` | F-56 first runtime `assert*`? |
|---------|--------------------------------------|-----------------------------------------------|----------------------------|------------------------------|
| `OrganizationContextResolver` | **Yes** (authoritative) | Yes (branch-null path) | Yes (`AuthService`) | **Recommended** — **only** on `MODE_MEMBERSHIP_SINGLE_ACTIVE` success path |
| `StaffMultiOrgOrganizationResolutionGate` | Reads context | No | Could pull auth — **wrong contract** | **No** — would break branch/fallback-resolved staff |
| `OrganizationRepositoryScope` | Reads context | No | **No** | **No** — needs ctor/DI explosion to obtain user |
| `BranchDirectory` | Reads context | No | No | **No** |
| Marketing / payroll / client repos | Indirect via scope | No | No | **No** — downstream; not first |
| `OrganizationScopedBranchAssert` | Reads context | No | No | **No** |

**Closed layers (F-55):** `assert*` **only** in **two CLI verifiers** + strict gate class definition.

**Cross-reference:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-RUNTIME-CONSUMER-SELECTION-TRUTH-AUDIT-FOUNDATION-56-OPS.md`.
