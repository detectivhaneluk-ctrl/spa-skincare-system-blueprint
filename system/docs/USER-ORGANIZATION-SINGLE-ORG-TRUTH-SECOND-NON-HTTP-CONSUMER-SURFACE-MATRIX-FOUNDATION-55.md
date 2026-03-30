# USER-ORGANIZATION-SINGLE-ORG-TRUTH — SECOND NON-HTTP CONSUMER SURFACE MATRIX (FOUNDATION-55)

Post-**FOUNDATION-54** closure matrix (read-only).

| Artifact | F-46 contract | F-48/F-51 contract | Calls `assert*` | Mutations | HTTP / resolver body / F-25 |
|----------|---------------|--------------------|-----------------|-----------|----------------------------|
| `audit_user_organization_membership_context_resolution.php` | Yes | — | Yes (F-54 positive) | No | No |
| `audit_user_organization_membership_backfill_and_gate.php` | — | Yes | Yes (F-51) | No (`run(true)` only) | No |
| `UserOrganizationMembershipStrictGateService` | — | Defines `assert*` | Defines | No | No |
| `UserOrganizationMembershipReadService` | Used by context script | — | No | No | Used by resolver (no `assert*`) |
| `UserOrganizationMembershipReadRepository` | Indirect | Used by backfill verifier | No | No (read-only repo) | No |
| `OrganizationContextResolver` | — | — | No | No | Yes (HTTP resolution) |
| `StaffMultiOrgOrganizationResolutionGate` | — | — | No | No (403 only) | Yes (post-auth) |

**Cross-reference:** `USER-ORGANIZATION-SINGLE-ORG-TRUTH-SECOND-NON-HTTP-CONSUMER-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-55-OPS.md`.
