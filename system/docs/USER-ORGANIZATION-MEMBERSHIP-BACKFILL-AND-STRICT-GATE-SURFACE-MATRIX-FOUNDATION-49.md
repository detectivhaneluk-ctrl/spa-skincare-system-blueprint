# USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE — SURFACE MATRIX (FOUNDATION-49)

Read-only matrix for **FOUNDATION-48** closure. Rows are **in-scope artifacts**; columns are **concerns** provable from this tree.

| Surface | Role | Reads pivot / `information_schema` | Writes `user_organization_memberships` | Registered in DI | CLI entry | HTTP / F-25 / middleware |
|--------|------|--------------------------------------|------------------------------------------|-------------------|-----------|-------------------------|
| `UserOrganizationMembershipReadRepository` | Active membership reads + table presence cache | Yes (`TABLES` probe; `SELECT` with live-org join) | **No** | Yes (`register_organizations.php`) | Via services | **No** |
| `UserOrganizationMembershipReadService` | Facade for resolver (F-46) | Via repository | **No** | Yes | Indirect (`audit_user_organization_membership_context_resolution.php`) | **No** (resolver wiring is F-46; unchanged by F-48 files) |
| `UserOrganizationMembershipStrictGateService` | `table_absent` / `none` / `single` / `multiple` + `assertSingleActiveMembershipForOrgTruth` | Via repository + read service | **No** | Yes | `audit_user_organization_membership_backfill_and_gate.php` | **No** |
| `UserOrganizationMembershipBackfillService` | Branch→org INSERT backfill | Yes (branch/org/membership `SELECT`s) | **INSERT only** when `!$dryRun` | Yes | `backfill_user_organization_memberships.php` (`run(false)`); verifier `run(true)` only | **No** |
| `register_organizations.php` | Singleton bindings | — | — | **Yes** (all four services above + registry) | — | **No** |
| `backfill_user_organization_memberships.php` | Operator backfill | Via app services | **INSERT** only through service live run | — | **Yes** | **No** |
| `audit_user_organization_membership_backfill_and_gate.php` | F-48 verifier | Yes | **No** (dry-run only + row count guard) | — | **Yes** | **No** |
| `audit_user_organization_membership_context_resolution.php` | F-46 verifier | Yes | **No** | — | **Yes** | **No** |

**Cross-reference:** consolidated proof and waivers — `USER-ORGANIZATION-MEMBERSHIP-BACKFILL-AND-STRICT-GATE-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-49-OPS.md`.
