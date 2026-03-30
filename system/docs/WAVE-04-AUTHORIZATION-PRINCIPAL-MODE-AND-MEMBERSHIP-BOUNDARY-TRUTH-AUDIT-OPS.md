# WAVE-04 — Authorization principal mode and membership boundary (truth audit)

**Mode:** read-only audit only. **No code/schema/route/UI changes** in this wave.

This document answers where security truth is sourced, where it can diverge, and what can still undermine tenant/data-plane hardening.

---

## 1. Canonical truth map (code-sourced)

| Concern | Source of truth | Key code | Downstream consumers |
|--------|-----------------|----------|----------------------|
| **Authenticated identity** | Session `user_id` + live `users` row (`deleted_at IS NULL`) | `SessionAuth::login` / `id` / `user()`; `AuthService::check` | All middleware and controllers via `AuthService` |
| **Principal mode (platform vs tenant)** | **Global** `user_roles` ∩ `roles.code IN ('platform_founder')` with `roles.deleted_at IS NULL` | `PrincipalAccessService::isPlatformPrincipal` | `BranchContextMiddleware`, `TenantProtectedRouteMiddleware`, `PlatformPrincipalMiddleware`, `TenantPrincipalMiddleware`, `TenantRuntimeContextEnforcer`, `AuthenticatedHomePathResolver`, `DashboardController` |
| **Permissions (RBAC codes)** | **(A)** `user_roles` → `role_permissions` → `permissions` **without joining `roles` for soft-delete**; **(B)** staff-group pivot for current `BranchContext` | `PermissionService::getForUser`; `StaffGroupPermissionRepository::listPermissionCodesForUserInBranchScope` | `PermissionMiddleware`; ad-hoc `PermissionService::has` in controllers |
| **Organization membership** | `user_organization_memberships` where `status = 'active'` and org `deleted_at IS NULL` | `UserOrganizationMembershipReadRepository`; `TenantBranchAccessService::activeMembershipOrganizationIds` | Org context resolver alignment; branch allow-list (when pin absent) |
| **Branch context (HTTP)** | Session `branch_id` if allowed; else default from `TenantBranchAccessService`; **platform principals → forced null** | `BranchContextMiddleware` | `BranchContext`, `PermissionService` (staff groups), tenant data-plane |
| **Organization context (HTTP)** | Branch-derived org; else single active membership (strict gate); else ambiguous/unresolved; else single-org DB fallback | `OrganizationContextResolver::resolveForHttpRequest` | `OrganizationContext`, `TenantProtectedRouteMiddleware`, `OrganizationRepositoryScope`, tenant guards |
| **Tenant entry state** | `TenantEntryResolverService` → `TenantBranchAccessService::allowedBranchIdsForUser` | `TenantEntryController` | Chooser / auto-pick / blocked views |
| **Route gate (tenant modules)** | `AuthMiddleware` → `TenantProtectedRouteMiddleware` → `PermissionMiddleware` (typical) | Route registrars under `system/routes/web/*` | Handler execution |
| **Post-auth runtime gate (tenant shell)** | `StaffMultiOrgOrganizationResolutionGate` + `TenantRuntimeContextEnforcer` inside `AuthMiddleware` | `AuthMiddleware::handle` | All authenticated routes using `AuthMiddleware` (except enforcer exemptions) |
| **Suspended tenant (login)** | `OrganizationLifecycleGate::isTenantUserBoundToSuspendedOrganization` | `LoginController::attempt` (after password ok) | Blocks session for non-platform users tied to suspended org via pinned branch or active membership to suspended org |
| **Suspended tenant (session)** | `TenantRuntimeContextEnforcer` + lifecycle gate | `TenantRuntimeContextEnforcer::enforceForAuthenticatedUser` | Blocks tenant operational shell when org/branch suspended |

---

## 2. Contradiction points (explicit)

### C1 — `users.branch_id` overrides membership for branch eligibility

**Proof:** `TenantBranchAccessService::allowedBranchIdsForUser` returns **only** `[pinnedBranch]` when `activePinnedBranchIdForUser` is non-null, **before** any membership query.

```22:31:system/core/Branch/TenantBranchAccessService.php
    public function allowedBranchIdsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $userBranchId = $this->activePinnedBranchIdForUser($userId);
        if ($userBranchId !== null) {
            return [$userBranchId];
        }
```

**Divergence:** `OrganizationContextResolver::enforceBranchDerivedMembershipAlignmentIfApplicable` **returns immediately** when `countActiveMembershipsForUser === 0`, so it does **not** contradict a pinned branch.

```118:126:system/core/Organization/OrganizationContextResolver.php
    private function enforceBranchDerivedMembershipAlignmentIfApplicable(int $branchDerivedOrgId, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $mCount = $this->membershipRead->countActiveMembershipsForUser($userId);
        if ($mCount === 0) {
            return;
        }
```

**Effect:** A user can retain **operational tenant branch context** (and thus `MODE_BRANCH_DERIVED` org context) with **zero active organization memberships**, if `users.branch_id` still points at an active branch. **Permission truth** and **membership truth** are then **decoupled** for admission.

---

### C2 — Global role permissions ignore `roles.deleted_at` (and do not require membership)

**Proof:** `PermissionService::getForUser` loads permissions via `user_roles` / `role_permissions` with **no** `JOIN roles` and **no** `roles.deleted_at IS NULL` predicate.

```56:62:system/core/permissions/PermissionService.php
        $rows = $this->db->fetchAll(
            'SELECT p.code FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?',
            [$userId]
        );
```

**Divergence:** Principal plane uses `roles` with `deleted_at` for platform detection (`PrincipalAccessService`), but effective **tenant capability codes** can still be granted for role rows that are soft-deleted in other workflows if `user_roles` and `role_permissions` rows remain (schema does not auto-clear `user_roles` on role soft-delete).

**Membership:** Role grants are **not** conditioned on `user_organization_memberships` at all.

---

### C3 — Platform principal classification vs permission catalog

**Proof:** `PrincipalAccessService` uses only role **code** `platform_founder`. `PermissionService` merges **all** role-derived codes for the user.

**Contradiction class:** A single account can hold `platform_founder` **and** tenant roles simultaneously. **Mitigation:** `TenantProtectedRouteMiddleware` denies platform principals on tenant-internal routes; `BranchContextMiddleware` clears branch session for platform principals.

**Residual ambiguity:** Any **future or exceptional** route that uses `PermissionMiddleware` **without** `TenantProtectedRouteMiddleware` could still consult `PermissionService` truth that includes **tenant** codes for a platform principal. Current surveyed tenant business registrars attach `TenantProtectedRouteMiddleware`; **`GET /`** is only `AuthMiddleware` but only redirects via `AuthenticatedHomePathResolver` (principal-based), not tenant CRUD.

---

### C4 — Login vs operational denial timing

**Proof:**

- **Login** (`AuthService::attempt`) only verifies `users` existence, `deleted_at IS NULL`, and password — **no** membership or active-org check.

```43:46:system/core/auth/AuthService.php
        $user = $this->db()->fetchOne(
            'SELECT id, password_hash FROM users WHERE email = ? AND deleted_at IS NULL',
            [$identifier]
        );
```

- **Suspended org:** Non-platform users are blocked **after** successful password verification in `LoginController` via `isTenantUserBoundToSuspendedOrganization`.

- **Inactive / non-active membership:** There is **no** equivalent login block for “all memberships inactive” if `users.branch_id` remains set to a valid branch (see C1).

- **Role / membership revocation:** Session remains valid until logout; **next request** re-reads permissions from DB (`PermissionService` request-scoped array cache only).

---

### C5 — Staff group permissions vs branch context null (non-platform)

**Proof:** For `branchContextId === null`, staff-group SQL restricts to `staff_groups.branch_id IS NULL` only.

```38:42:system/core/permissions/StaffGroupPermissionRepository.php
        if ($branchContextId === null) {
            $sql .= ' AND sg.branch_id IS NULL';
        } else {
            $sql .= ' AND (sg.branch_id IS NULL OR sg.branch_id = ?)';
```

**Contradiction class:** When tenant users briefly have null branch (or platform-adjacent misconfiguration), **global-branch** staff groups apply. Combined with C1/C4, this is mostly edge-case but shows **permission truth** depends on **branch truth** in a different way than **membership alignment**.

---

### C6 — Multi-org gate vs single-org deployments

**Proof:** `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff` returns early when `countActiveOrganizations() <= 1`, so **unresolved org** is not enforced by F-25 in single-org DBs. Resolution may use `MODE_SINGLE_ACTIVE_ORG_FALLBACK` without branch; `TenantRuntimeContextEnforcer` then **denies** non-exempt paths unless branch+`MODE_BRANCH_DERIVED` — steering users toward `/tenant-entry`. This is **consistent** but **asymmetric** across deployment topology (multi-org stricter at org-resolution layer).

---

## 3. Answers to primary questions (strict)

1. **Exact sources of truth** — See §1 table.

2. **Single account holding platform + tenant artifacts** — **Yes, structurally:** same `user_id` can have `platform_founder` and other `user_roles`. Runtime **separates** planes via `TenantProtectedRouteMiddleware` and branch clearing for platform principals; **permission merge** remains **ambiguous** for any code that checks only permissions.

3. **Login blocking inactive/suspended** — **Suspended org (non-platform): blocked at login** (with noted pin/membership query in `OrganizationLifecycleGate`). **Inactive membership / no membership with pinned branch: not blocked at login**; denial is **not** guaranteed until downstream gates — and **C1** can avoid denial for tenant routes.

4. **Tenant capabilities from global role alone?** — **Yes.** `PermissionService` grants from `user_roles` with **no** membership predicate. Tenant **routes** additionally require `TenantProtectedRouteMiddleware` + branch/org mode for sealed modules.

5. **Module paths where membership and permission diverge** — **Any tenant module** behind `TenantProtectedRouteMiddleware`: admission uses branch/org resolution (membership alignment **conditional** on `mCount > 0`). **Pinned branch without membership** breaks parity. **PermissionMiddleware** does not read membership.

6. **Platform accounts behaving as tenant under edge conditions** — **Not on routes that use `TenantProtectedRouteMiddleware`** (explicit 403). **Platform** users hit `/platform-admin` and org-registry exemptions; they do **not** receive tenant branch from middleware. **Risk** is **misconfigured route** missing tenant middleware, not current surveyed defaults.

7. **Tenant accounts depending on non-canonical / non-fail-closed branch context** — **Canonical** intended path: session branch ∈ `allowedBranchIdsForUser` (`BranchContextController` enforces on POST). **Non-canonical:** **`users.branch_id` as implicit allow-list bypass** over membership (C1). **Fail-closed:** `TenantProtectedRouteMiddleware` and data-plane guards **fail closed** when org/mode wrong; they **do not** repair C1.

---

## 4. Risk ranking (strict)

| ID | Issue | Exploitability | Blast radius | Drift likelihood | Undermines hardening |
|----|--------|----------------|--------------|------------------|----------------------|
| R1 | Pinned `users.branch_id` with **zero** active membership still yields allowed branch + branch-derived org (alignment skipped) | Low–medium (needs DB/admin error or stale pin) | **High** (full tenant module + data-plane scope for that branch) | **Medium** (ops: revoke membership, forget clear pin) | **Yes** |
| R2 | `PermissionService` role path ignores `roles.deleted_at` | Low | Medium (wrong caps if roles soft-deleted inconsistently) | Low–medium | Partial (RBAC vs repo scope) |
| R3 | Platform + tenant role stacking; permission merge is superset | Low (tenant routes blocked) | **Latent** (future routes / internal calls) | Low | **If** new surfaces skip tenant middleware |
| R4 | Session survives admin revocation until next request / no server-side invalidation | Low | Medium | Medium | Standard session semantics |
| R5 | Staff-group permissions under `branch_id === null` use global-branch groups only | Low | Low–medium | Low | Minor |
| R6 | Single-org vs multi-org asymmetric F-25 behavior | Low | Low (design) | N/A | No direct bypass |

---

## 5. Single recommended next repair wave (only)

**WAVE-05 — TENANT-BRANCH-ELIGIBILITY-AND-RBAC-ROLE-ROW-INTEGRITY**

**Scope (narrow, backend-only):**

1. **Canonical tenant branch eligibility:** Change `TenantBranchAccessService` so a non–platform user **cannot** treat `users.branch_id` as the sole allow-list when it **contradicts** active membership policy (e.g. require active membership row for that branch’s organization, or clear/gate pinned branch when `mCount === 0` after membership table present). Align with `OrganizationContextResolver` membership alignment so **mCount === 0** does not silently skip checks when a pin exists.

2. **RBAC read integrity:** Extend `PermissionService` role query to join `roles` and enforce `roles.deleted_at IS NULL` (and document interaction with `user_roles` lifecycle).

**Explicitly out of scope for WAVE-05:** permission catalog redesign, new session store, UI, route rewrites beyond what is required for a single admin repair endpoint if needed, client/documents module edits.

---

## 6. Evidence index (non-exhaustive)

- `system/core/auth/SessionAuth.php`, `AuthService.php`, `PrincipalAccessService.php`, `AuthenticatedHomePathResolver.php`
- `system/core/permissions/PermissionService.php`, `StaffGroupPermissionRepository.php`
- `system/core/middleware/AuthMiddleware.php`, `BranchContextMiddleware.php`, `OrganizationContextMiddleware.php`, `TenantProtectedRouteMiddleware.php`, `PlatformPrincipalMiddleware.php`, `TenantPrincipalMiddleware.php`, `PermissionMiddleware.php`
- `system/core/Organization/OrganizationContextResolver.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationLifecycleGate.php`
- `system/core/Branch/TenantBranchAccessService.php`
- `system/core/tenant/TenantRuntimeContextEnforcer.php`
- `system/modules/auth/controllers/LoginController.php`, `TenantEntryController.php`, `BranchContextController.php`
- `system/modules/auth/services/TenantEntryResolverService.php`
- `system/modules/organizations/repositories/UserOrganizationMembershipReadRepository.php`
- `system/routes/web/register_core_dashboard_auth_public.php` (notably `GET /` = `AuthMiddleware` only)
- `system/data/full_project_schema.sql` — `users`, `roles`, `user_roles`, `user_organization_memberships`

---

## 7. Intentionally not done in WAVE-04

- No code, migration, seed, route, or UI changes.
- No verification scripts added (optional follow-up).
- No updates to `BOOKER-PARITY-MASTER-ROADMAP.md` unless project discipline requests it in a separate editorial task.
