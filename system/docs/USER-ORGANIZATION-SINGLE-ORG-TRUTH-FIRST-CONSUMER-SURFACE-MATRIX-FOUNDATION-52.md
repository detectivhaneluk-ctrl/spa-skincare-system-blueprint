# USER-ORGANIZATION-SINGLE-ORG-TRUTH — FIRST CONSUMER SURFACE MATRIX (FOUNDATION-52)

Post-**FOUNDATION-51** closure matrix (read-only audit). Rows = in-scope surfaces; columns = F-52 proof targets.

| Surface | F-48 verifier checks present | `assert*(0)` negative test | Positive `assert*` + id match | Writes on verifier path | Referenced by HTTP resolver / F-25 |
|---------|------------------------------|----------------------------|--------------------------------|-------------------------|-------------------------------------|
| `audit_user_organization_membership_backfill_and_gate.php` | Yes | Yes | Yes (conditional) | No (`run(true)` only) | No |
| `UserOrganizationMembershipStrictGateService` | — | Defines `assert*` | Called from script only | No | No |
| `UserOrganizationMembershipBackfillService` | Used dry-run only | — | — | Verifier: no `run(false)` | No |
| `UserOrganizationMembershipReadService` | Indirect (gate/repo) | — | — | No | Yes (resolver only) |
| `UserOrganizationMembershipReadRepository` | Indirect | — | — | No (doc: read-only) | Via read service |
| `OrganizationContextResolver` | — | — | — | No | Yes (HTTP org context) |
| `StaffMultiOrgOrganizationResolutionGate` | — | — | — | No (403/exit only) | Yes (post-auth) |

**Cross-reference:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-FIRST-CONSUMER-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-52-OPS.md`.
