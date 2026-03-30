# USER-ORGANIZATION-SINGLE-ORG-TRUTH — NEXT NON-HTTP CONSUMER SURFACE MATRIX (FOUNDATION-53)

Read-only matrix: **membership / org-truth-related scripts** and **strict `assert*` adoption** status after F-52.

| Script / surface | Non-HTTP | Read-only today | Uses `assert*` today | F-53 next-consumer role |
|------------------|----------|-----------------|----------------------|-------------------------|
| `audit_user_organization_membership_backfill_and_gate.php` | Yes | Yes (`run(true)` only) | **Yes** (F-51) | **Closed** first consumer |
| `audit_user_organization_membership_context_resolution.php` | Yes | Yes | **No** | **Recommended next** (extend) |
| `backfill_user_organization_memberships.php` | Yes | No (mutates when not `--dry-run`) | No | **Defer** (mutation coupling) |
| `verify_organization_registry_schema.php` | Yes | Yes | No | Schema only — **not** assert consumer candidate |
| `OrganizationContextResolver` (compatibility) | HTTP | n/a | No | **Do not** adopt `assert*` next |
| `StaffMultiOrgOrganizationResolutionGate` (compatibility) | HTTP | n/a | No | **Do not** adopt `assert*` next |

**Single recommended next target:** extend **`audit_user_organization_membership_context_resolution.php`** per **`USER-ORGANIZATION-SINGLE-ORG-TRUTH-NEXT-NON-HTTP-CONSUMER-SELECTION-TRUTH-AUDIT-FOUNDATION-53-OPS.md` §4–§6.

**Optional later (not F-53 primary):** new read-only multi-user `STATE_SINGLE` sweep script for **R-52-2**-class coverage.
