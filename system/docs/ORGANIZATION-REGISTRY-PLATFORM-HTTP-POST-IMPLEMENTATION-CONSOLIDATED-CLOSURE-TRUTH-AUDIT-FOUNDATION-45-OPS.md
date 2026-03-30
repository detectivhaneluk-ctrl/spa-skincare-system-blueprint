# ORGANIZATION-REGISTRY-PLATFORM-HTTP-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT — FOUNDATION-45 (read-only)

**Mode:** Docs-only. **No** code/migrations/UI.  
**Input:** ZIP-accepted **FOUNDATION-06**–**FOUNDATION-44** as baseline; **do not** reopen closed waves unless contradicted — **no** contradiction found.

**Companion matrix:** **`ORGANIZATION-REGISTRY-PLATFORM-HTTP-CONSOLIDATED-SURFACE-MATRIX-FOUNDATION-45.md`**.

---

## 1. Services / schema / catalog baseline (audited)

| Wave | Artifact | Path / role |
|------|-----------|----------------|
| **F-38** | Schema | `organizations.suspended_at`; `user_organization_memberships` — **`system/data/migrations/087_organization_registry_membership_foundation.sql`**, snapshot **`system/data/full_project_schema.sql`** |
| **F-39** | Permission catalog | **`088_platform_organization_profile_permissions_catalog.sql`** — `platform.organizations.view`, `platform.organizations.manage`, `organizations.profile.manage` (catalog only; no default `role_permissions` in migration) |
| **F-39** | Seed parity | **`001_seed_roles_permissions.php`** — same three codes; **owner** role receives **all** permission ids in loop |
| **F-40** | Read service | **`OrganizationRegistryReadService`** — `listOrganizations()`, `getOrganizationById(int)` → **`OrganizationRegistryReadRepository`** |
| **F-41** | Mutation service | **`OrganizationRegistryMutationService`** — `createOrganization`, `updateOrganizationProfile`, `suspendOrganization`, `reactivateOrganization` → mutation + read repos |

---

## 2. HTTP exposure layer (audited)

| Item | Truth |
|------|--------|
| **Registrar** | **`system/routes/web/register_platform_organization_registry.php`** |
| **Orchestrator** | **`system/routes/web.php`** — `require` after `register_branches.php` |
| **Read controller** | **`Modules\Organizations\Controllers\PlatformOrganizationRegistryController`** — `index`, `show` |
| **Manage controller** | **`Modules\Organizations\Controllers\PlatformOrganizationRegistryManageController`** — `create`, `store`, `edit`, `update`, `suspend`, `reactivate` |
| **Views** | **`system/modules/organizations/views/platform-registry/`** — `index.php`, `show.php`, `create.php`, `edit.php` |
| **DI** | **`system/modules/bootstrap/register_organizations.php`** — repos, services, both controllers; loaded via **`system/modules/bootstrap.php`** |

**Exact route → action → permission:** see matrix doc §1.

---

## 3. Guard / boundary layer (audited)

| Component | Path / behavior |
|-----------|------------------|
| **Global pipeline** | **`Core\Router\Dispatcher`** — Csrf → ErrorHandler → BranchContext → OrganizationContext → **per-route** middleware |
| **Auth** | **`Core\Middleware\AuthMiddleware`** — session, inactivity, password expiry, then **`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()`** |
| **RBAC** | **`Core\Middleware\PermissionMiddleware::for(string)`** — **`PermissionService::has($userId, $permission)`** |
| **`PermissionService::has`** | **`system/core/permissions/PermissionService.php`** — grants if exact code, or `*`, or `{prefix}.*` for permission prefix; **`getForUser`** merges **role** permissions with **staff-group** permissions for **current `BranchContext` branch** |

**F-25 exemptions (platform org registry only):** **`StaffMultiOrgOrganizationResolutionGate`** (`system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php`)

- **`isPlatformOrganizationRegistryReadPath`:** GET **`/platform/organizations`**, GET **`/platform/organizations/<digits>`** (F-43).
- **`isPlatformOrganizationRegistryManagePath`:** GET **`/platform/organizations/create`**, GET **`/platform/organizations/<digits>/edit`**, POST **`/platform/organizations`**, POST **`/platform/organizations/<digits>`**, POST **`.../suspend`**, POST **`.../reactivate`** (F-44).

**Unresolved org (multi-org, count > 1, no resolved context):** Any **non-exempt** staff route still **403** with `ORGANIZATION_CONTEXT_REQUIRED` (JSON) or plain text — unchanged outside these paths.

---

## 4. Caller / surface confirmation (exact PHP)

### 4.1 `OrganizationRegistryReadService`

| Caller | Function / context |
|--------|-------------------|
| **`PlatformOrganizationRegistryController`** | `index()` → `listOrganizations()`; `show()` → `getOrganizationById()` |
| **`PlatformOrganizationRegistryManageController`** | `edit()` → `getOrganizationById()`; `update()` pre-check / reload paths |
| **`register_organizations.php`** | DI factory only |
| **`audit_organization_registry_read_service.php`** | Verifier |
| **`audit_organization_registry_mutation_service.php`** | Verifier (post-rollback checks) |

**No other PHP runtime callers** (grep `*.php` under `system/`).

### 4.2 `OrganizationRegistryMutationService`

| Caller | Function / context |
|--------|-------------------|
| **`PlatformOrganizationRegistryManageController`** | `store` → `createOrganization`; `update` → `updateOrganizationProfile`; `suspend` / `reactivate` → matching service methods |
| **`register_organizations.php`** | DI factory only |
| **`audit_organization_registry_mutation_service.php`** | Verifier |

**No other PHP runtime callers.**

### 4.3 `platform.organizations.view`

| Site | Role |
|------|------|
| **`register_platform_organization_registry.php`** | `$viewMw` on GET list + GET show |
| **`verify_platform_permission_catalog.php`** | Catalog check |
| **`001_seed_roles_permissions.php`** / **`088_...sql`** | Definition |

### 4.4 `platform.organizations.manage`

| Site | Role |
|------|------|
| **`register_platform_organization_registry.php`** | `$manageMw` on create/store/edit/update/suspend/reactivate routes |
| **`PlatformOrganizationRegistryController::canManageOrganizations()`** | `PermissionService::has(..., 'platform.organizations.manage')` for UI links only |
| Seed / migration / verifier | Same as view |

### 4.5 `organizations.profile.manage`

| Site | Role |
|------|------|
| **Permissions table / seed / `verify_platform_permission_catalog.php`** | **Present in catalog** |
| **Routes / `PermissionMiddleware` / controllers** | **No references** — **held for later** in-tenant profile HTTP (F-37 / F-42 intent). Platform manage uses **`OrganizationRegistryMutationService::updateOrganizationProfile` by org id** under **`platform.organizations.manage`**, not this code. |

---

## 5. Closure checks (per platform org HTTP surface)

| Check | Result |
|-------|--------|
| **Permission guard** | **view** on GET list/show; **manage** on all mutation routes and GET create/edit — see matrix |
| **Route / action mapping** | One-to-one in registrar; **POST `/{id}/suspend` / `/{id}/reactivate`** registered **before** POST `/{id}` so router distinguishes |
| **Create field scope** | POST **`name`** (required in practice via service), optional **`code`** — matches **F-41** |
| **Update field scope** | POST **`name`**, **`code`** (empty clears when `code` key submitted) — matches **F-41** |
| **Suspend / reactivate** | Service sets **`suspended_at`** non-null / **NULL** — unchanged |
| **Unresolved-org behavior** | Exempt paths skip F-25 block; **Auth** + **Permission** still apply |
| **F-25 boundary** | **Manual sync** required: gate path/method logic must match **`register_platform_organization_registry.php`** (no compile-time link) |
| **Tenant crossover** | **No** alternate route maps same handlers under `branches.*` or `settings.*`; **URL prefix** `/platform/organizations` is distinct. **Risk:** **owner** seed grants **all** permissions including platform codes — **operational RBAC**, not a second principal type (F-36/F-42 truth). |
| **Remaining gap in this layer** | **No mandatory extra backend wave** for declared F-43/F-44 scope; optional product gaps below are **waivers / next program**, not blockers for “platform registry HTTP slice” closure. |

---

## 6. Remaining risk / waiver list

| ID | Waiver / risk |
|----|----------------|
| **W-1** | **F-25 ↔ route drift:** New/changed platform org routes **must** update **`StaffMultiOrgOrganizationResolutionGate`** in the same change set, or multi-org operators hit **403** before permission check. |
| **W-2** | **`organizations.profile.manage`:** Catalog-only; **no** HTTP — in-tenant profile editing remains a **future** slice. |
| **W-3** | **Audit trail:** Org mutations do **not** emit **`AuditService`** entries (unlike **`BranchAdminController`** branch create/update) — acceptable omission vs F-44 scope; note for compliance-minded deployers. |
| **W-4** | **`PermissionService` wildcards:** `*` / `prefix.*` can grant platform codes without explicit rows — seed/ops discipline (F-36/F-42). |
| **W-5** | **View-only users** see list/show **without** manage links; **manage** URLs still **403** without **`platform.organizations.manage`** — **no** accidental mutation exposure via UI for view-only role. |

---

## 7. Final closure verdict (exactly one)

**B) Closed with documented waiver(s).**

The **organization-registry platform HTTP layer** (F-43 read + F-44 manage + F-25 compatibility + F-40/F-41 services + F-39 codes) is **complete for its stated scope**. **W-1–W-5** are explicit **waivers / operational risks**, not an omitted mandatory backend step inside that same slice.

*(Not **A** because W-1/W-3/W-4 require ongoing discipline or optional follow-up; not **C** because there is **no** single remaining **required** minimal wave to finish F-43/F-44 platform HTTP itself.)*

---

## 8. Single recommended next program (after closure)

**FOUNDATION-46 — USER-ORGANIZATION-MEMBERSHIP-AND-ORGANIZATION-CONTEXT-RESOLUTION-INTEGRATION-MINIMAL-R1**

**Evidence from tree:** **`user_organization_memberships`** exists (**F-38**) but has **no** application HTTP/services integration in this repo; **F-37 S5** and **F-44** explicitly deferred membership/context; **`organizations.profile.manage`** has **no** routes — next coherent backend program is **membership + context integration** (or a smaller doc-only gate if product reprioritizes). **Name is id-only; scope is set when F-46 is tasked.**

---

## 9. Stop

This wave **ends** at audit docs + roadmap row. **No** implementation.
