# ORGANIZATION-CONTROL-PLANE-SURFACE-MATRIX — FOUNDATION-36

Read-only matrix. **Scope model:** **G** = global deployment / not org-partitioned; **O** = org-scoped when **`OrganizationContext`** resolves; **B** = branch-primary; **—** = not applicable; **∅** = missing surface.

---

## A) Data plane

| Item | Migration / schema | Runtime writes | Scope |
|------|---------------------|----------------|-------|
| **`organizations`** | **086** + snapshot | SQL seed only; **no** app CRUD | **G** table; **not** administered in PHP |
| **`branches.organization_id`** | **086** | **`BranchDirectory`** + backfill | **O** ownership on branch row |
| **`users.branch_id`** | core users migration | staff flows | **B**; **no** `users.organization_id` |
| **`settings.branch_id`** | core | **`SettingsService`** | **G** `0` + **B** overlay; **no** org column |

---

## B) Organization inference / enforcement (core PHP)

| File | Responsibility | Resolved org | Null org |
|------|----------------|--------------|----------|
| `OrganizationContextResolver.php` | Fill context | Branch-derived or single-org id | Null + mode |
| `OrganizationContext.php` | Hold id/mode | Used by directory/repos | Assert no-op / legacy paths |
| `OrganizationScopedBranchAssert.php` | Branch row vs org | **DomainException** mismatch | No-op |
| `OrganizationRepositoryScope.php` | SQL fragments | Scoped clauses | Empty fragment (legacy) |
| `StaffMultiOrgOrganizationResolutionGate.php` | Staff HTTP gate | Pass if resolved | **403** if multi-org + unresolved |
| `OrganizationContextMiddleware.php` | Wire resolver | Runs every request (after branch) | — |

---

## C) Staff HTTP admin surfaces (representative)

| Surface | Route file | Permission | Org context on request? | Global / org / branch |
|---------|------------|------------|---------------------------|------------------------|
| Branches | `register_branches.php` | `branches.*` | Yes (after globals) | **O**-aligned reads/mutations when resolved (F-30/32/34); degenerate **G** legacy |
| Settings | `register_settings.php` | `settings.*` | Yes | **B** + global `0` merge |
| Staff | `register_staff.php` | `staff.*` | Yes | **B** groups; **G** role catalog |
| *(Organizations)* | *none* | *none* | *n/a* | **∅** |

---

## D) Founder / platform / tenancy admin

| Capability | Present? |
|------------|----------|
| Dedicated platform routes | **No** |
| `organizations` CRUD | **No** |
| `users` org membership | **No** |
| Permission tier `platform.*` / seeded `*` | **No** seeds found; `*` supported in **`PermissionService`** if manually granted |

---

## E) Verdict row

| Biggest SaaS control-plane gap | **No org registry + no user↔org membership + no platform RBAC** |
|--------------------------------|------------------------------------------------------------------|
| Single next wave (name only) | **FOUNDATION-37 — ORGANIZATION-REGISTRY-AND-PLATFORM-CONTROL-PLANE-MINIMAL-DESIGN-R1** |
