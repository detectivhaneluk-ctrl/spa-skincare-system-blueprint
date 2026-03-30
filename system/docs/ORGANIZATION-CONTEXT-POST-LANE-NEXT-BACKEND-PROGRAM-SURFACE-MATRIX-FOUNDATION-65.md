# ORGANIZATION CONTEXT — POST-LANE NEXT BACKEND PROGRAM SURFACE MATRIX (FOUNDATION-65)

**Baseline:** F-64 **CLOSE LANE WITH WAIVERS** — see **`USER-ORGANIZATION-MEMBERSHIP-AND-RUNTIME-TRUTH-LANE-CONSOLIDATED-PROGRAM-CLOSURE-TRUTH-AUDIT-FOUNDATION-64-OPS.md`**.

| Candidate bucket | Relative risk | Selected as **next** program? |
|------------------|----------------|------------------------------|
| F-25 **`StaffMultiOrgOrganizationResolutionGate`** semantics change | Medium–high | **No** (implementation) — **Yes** as **audit subject** inside recommended program |
| **`OrganizationRepositoryScope`** semantics change | High | **No** — wait |
| **`OrganizationScopedBranchAssert`** hardening | Medium | **No** — wait (needs coverage map first) |
| Downstream consumer cluster (clients / payroll / marketing / sales) | High | **No** — wait |
| **None yet** | — | **No** — W-64-1 defers real follow-up |

## Recommended single next program

| Field | Value |
|-------|--------|
| **Name** | **F-25 vs resolver `DomainException` error-surface read-only truth audit** |
| **Addresses** | **W-64-1** (HTTP / error middleware vs **F-57** / **F-62** / branch unlink `DomainException`s) |
| **Starts with** | **Read-only truth audit** only |
| **First-wave boundary** | No edits to resolver, F-25, scope, assert, branch middleware, routes, schema |
| **Optional later** | Narrow implementation task **after** audit matrix |

**Cross-reference:** `ORGANIZATION-CONTEXT-POST-LANE-NEXT-BACKEND-PROGRAM-SELECTION-TRUTH-AUDIT-FOUNDATION-65-OPS.md`.
