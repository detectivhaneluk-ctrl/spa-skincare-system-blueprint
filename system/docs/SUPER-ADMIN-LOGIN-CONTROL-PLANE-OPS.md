# Super-admin / founder control plane — login access ops

**Task:** SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01

## Founder vs tenant boundary

- **Founder** = user with role `platform_founder`. Home path is `/platform-admin`. No tenant branch context; must not use tenant operational modules.
- **Tenant user** = non-founder with at least one membership-authorized branch. Home path is `/dashboard` (single branch), `/tenant-entry` (multi or blocked), or `/tenant-entry` when org suspension applies.
- **Ambiguous** = founder role plus extra tenant roles and/or tenant memberships; `UserAccessShapeService` flags contradictions. Founder UI: “Canonicalize founder” strips non-`platform_founder` roles and clears memberships / `branch_id`.

## Smoke / dev fixtures (`scripts/dev-only/seed_branch_smoke_data.php`)

| Email | Role / intent | Password |
|-------|----------------|----------|
| `founder-smoke@example.test` | `platform_founder` | `FounderSmoke##2026` |
| `tenant-admin-a@example.test` | `admin`, branch A | `TenantAdminA##2026` |
| `tenant-reception-b@example.test` | `reception`, branch B | `TenantReceptionB##2026` |
| `tenant-multi-choice@example.test` | `admin`, multi-branch chooser | `TenantMultiChoice##2026` |
| `negative-orphan-access@example.test` | Negative case: admin, no usable branch | `NegativeOrphan##2026` |

Legacy `@example.com` smoke accounts are soft-deleted when the seed script runs.

## Founder management UI

- **URL:** `/platform-admin/tenant-access` (requires `platform.organizations.view`; mutations require `platform.organizations.manage`).
- **Capabilities:** list access shape, activate/deactivate user, suspend/unsuspend membership, repair branch + membership, canonicalize platform principal, provision tenant admin or reception with valid org/branch.

## CLI provisioning

Prefer explicit modes (requires migration `087` / `user_organization_memberships` for tenant modes):

```bash
php scripts/create_user.php --platform-founder email "Password" "Display Name"
php scripts/create_user.php --tenant-admin email "Password" "Name" --org-id=1 --branch-id=2
php scripts/create_user.php --tenant-staff email "Password" "Name" --org-id=1 --branch-id=2
```

Legacy `php scripts/create_user.php email pass [role_code]` is **refused** for tenant roles when the memberships table exists.

## Audit scripts (read-only)

```bash
php scripts/audit_login_access_truth.php email:user@example.test
php scripts/audit_founder_tenant_boundary_truth.php id:1
php scripts/audit_user_access_shape_repair_candidates.php 200
```

## Impersonation / support mode

- `FounderImpersonationAuditService` records `founder_support_session_start` / `founder_support_session_end` in `audit_logs` for future HTTP flows. No session user switching in this wave.

## Smoke env defaults

HTTP smokes default to the `.example.test` fixtures above when env vars are unset (except `SMOKE_BASE_URL` and branch-id probes such as `SMOKE_FOREIGN_BRANCH_ID` / lifecycle branch ids).
