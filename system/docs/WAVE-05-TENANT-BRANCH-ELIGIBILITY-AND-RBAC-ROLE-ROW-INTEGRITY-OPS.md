# WAVE-05 — Tenant branch eligibility and RBAC role-row integrity

Status: **CLOSED**

Scope in this wave is strictly limited to:

1. C1/R1 — pinned `users.branch_id` must not bypass membership-backed tenant eligibility when membership support exists.
2. C2/R2 — role-derived permission loading must ignore soft-deleted roles.

## Code changes

- `system/core/Branch/TenantBranchAccessService.php`
  - When membership table exists:
    - pinned branch is allowed only if user has active membership(s) and pinned branch belongs to one of those membership organizations.
    - pinned + zero active memberships => no allowed branches.
    - pinned + mismatched membership organizations => no allowed branches.
  - Membership-table-absent fallback is isolated and explicit.
- `system/core/permissions/PermissionService.php`
  - Role-derived permission query now joins `roles` and requires `roles.deleted_at IS NULL`.
- `system/scripts/smoke_wave_05_tenant_branch_and_rbac_integrity.php`
  - Deterministic runtime verifier for WAVE-05 requirements and key no-regression checks.

## Executed runtime proof

Command executed:

- `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe system/scripts/smoke_wave_05_tenant_branch_and_rbac_integrity.php`

Observed output:

- PASS `pinned_with_valid_membership_allowed`
- PASS `pinned_with_zero_memberships_denied`
- PASS `pinned_with_mismatched_membership_denied`
- PASS `membership_only_user_still_allowed_by_membership`
- PASS `tenant_runtime_context_valid_pinned_membership`
- PASS `tenant_runtime_context_denied_zero_membership`
- PASS `tenant_runtime_context_denied_mismatched_membership`
- PASS `platform_principal_behavior_unchanged`
- PASS `soft_deleted_role_permissions_excluded`
- PASS `live_role_permissions_still_granted`
- PASS `tenant_entry_single_branch_regression_green`
- PASS `tenant_entry_none_branch_regression_green`
- Summary: `12 passed, 0 failed`

## Runtime truth after repair

- With membership support present, a pinned branch is no longer a standalone authorization truth for tenant runtime.
- Tenant eligibility is fail-closed for pinned users with zero/misaligned active memberships.
- Platform principal branch behavior remains unchanged.
- Effective role-derived permission truth now matches live-role truth (`roles.deleted_at IS NULL`).

## Out of scope (intentionally untouched)

- Session invalidation redesign.
- Staff-group permission model redesign.
- Multi-org topology redesign.
- Route shell/control-plane redesign.
- Non-targeted module/data-plane work.

