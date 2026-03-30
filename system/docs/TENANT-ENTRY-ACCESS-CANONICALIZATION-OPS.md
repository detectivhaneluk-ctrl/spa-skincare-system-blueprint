# TENANT-ENTRY-ACCESS-CANONICALIZATION OPS

Date: 2026-03-23  
Status: DONE (seeded-login recovery + membership backfill tooling + platform/tenant home-path proof)

## Canonical access model

- Platform principal login truth:
  - `platform_founder` is a control-plane principal.
  - Authenticated home path resolves to `/platform-admin`.
  - Platform validity does not depend on tenant branch membership.
- Tenant single-branch truth:
  - With membership table present, tenant branch access is membership-aware.
  - A pinned branch is allowed only when its organization is in active memberships.
  - Resolver state is `single` and tenant entry routes to `/dashboard`.
- Tenant multi-branch truth:
  - Multiple active memberships across organizations resolve to `multiple`.
  - Tenant chooser must be used to select an allowed branch.
- Orphan blocked truth:
  - No pinned branch and no active membership resolves to `none`.
  - Blocked is expected fail-closed behavior, not a login failure.

## Seeded demo account truth

- `platform-smoke@example.com`: platform principal (control-plane path).
- `branchA@example.com`: single-branch tenant access.
- `branchB@example.com`: single-branch tenant access.
- `tenant-multi@example.com`: multi-branch tenant chooser access.
- `tenant-orphan@example.com`: intentionally blocked tenant orphan.

## Legacy drift/backfill truth

- Canonical tool: `php scripts/tenant_entry_access_drift_audit_repair.php`
- Optional safe apply: `--apply-safe`
- Optional JSON diagnostics: `--json`
- Classifications:
  - `ACCESS_OK`
  - `SAFE_MEMBERSHIP_BACKFILL`
  - `MANUAL_REVIEW_REQUIRED`
  - `INTENTIONAL_BLOCKED`
- Safe auto-backfill is allowed only for deterministic pinned-branch users with no conflicting membership state.
- Ambiguous or multi-org contradictions are left for manual review.
