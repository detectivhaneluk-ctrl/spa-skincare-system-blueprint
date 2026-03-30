# ORGANIZATION-REGISTRY-HTTP-EXPOSURE-AND-PLATFORM-GUARD-BOUNDARY-TRUTH-AUDIT — FOUNDATION-42 (read-only)

**Mode:** Docs-only audit. **No** code, migrations, or UI.  
**Input truth:** ZIP-accepted **FOUNDATION-38** through **FOUNDATION-41** (and prior closed waves). **Do not** reopen closed implementation waves unless the tree contradicts them — this audit **does not** contradict them.

**Companion matrix:** **`ORGANIZATION-REGISTRY-HTTP-SURFACE-MATRIX-FOUNDATION-42.md`**.

---

## 1. Scope audited (exact artifacts)

| Layer | Artifact | Path / symbol |
|-------|-----------|---------------|
| Registry read | Service | `Modules\Organizations\Services\OrganizationRegistryReadService` — `listOrganizations()`, `getOrganizationById(int)` |
| Registry read | Repository | `Modules\Organizations\Repositories\OrganizationRegistryReadRepository` |
| Registry mutation | Service | `Modules\Organizations\Services\OrganizationRegistryMutationService` — `createOrganization`, `updateOrganizationProfile`, `suspendOrganization`, `reactivateOrganization` |
| Registry mutation | Repository | `Modules\Organizations\Repositories\OrganizationRegistryMutationRepository` |
| DI | Module bootstrap | `system/modules/bootstrap/register_organizations.php` (read + mutation bindings; **no** HTTP controller binding) |
| Permission catalog | Migration + verifier | `system/data/migrations/088_platform_organization_profile_permissions_catalog.sql`; `system/scripts/verify_platform_permission_catalog.php` |
| Permission catalog | Seed parity | `system/data/seeders/001_seed_roles_permissions.php` — includes `platform.organizations.view`, `platform.organizations.manage`, `organizations.profile.manage` |
| RBAC runtime | Permission check | `Core\Permissions\PermissionService::has(int $userId, string $permission)` |
| RBAC runtime | Route guard | `Core\Middleware\PermissionMiddleware::for(string $permission)` |
| Auth | Session gate | `Core\Middleware\AuthMiddleware` |
| Post-auth org gate | Multi-org block | `Core\Organization\StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()` (invoked from `AuthMiddleware`) |
| Global pipeline | Order | `Core\Router\Dispatcher` — `CsrfMiddleware`, `ErrorHandlerMiddleware`, `BranchContextMiddleware`, `OrganizationContextMiddleware`, then **per-route** middleware |
| Branch / org context | Resolution | `Core\Middleware\BranchContextMiddleware`; `Core\Middleware\OrganizationContextMiddleware` → `OrganizationContextResolver::resolveForHttpRequest` |
| Routes | Orchestrator | `system/routes/web.php` — **no** `register_organizations` (or org registry) file in the registrar list |
| Comparable admin pattern | Branches | `system/routes/web/register_branches.php` + `Modules\Branches\Controllers\BranchAdminController` + `system/modules/bootstrap/register_branches.php` |

**Not present in tree:** Any HTTP route, controller, or view for organization registry.

---

## 2. Control-plane readiness (HTTP exposure)

### 2.1 Backend services

- **Read:** Implemented and globally scoped (F-40). Ready to be called from a controller.
- **Mutation:** Implemented (F-41). Ready to be called from a controller **after** permission + CSRF + product rules are wired.
- **Permissions:** Codes exist in DB catalog (F-39); **`PermissionMiddleware`** can reference them by string **identically** to `branches.view` / `inventory.view` patterns.

### 2.2 Route registration pattern (proof-first sequence)

- New staff routes belong in **`system/routes/web/`** as a registrar (e.g. `register_organizations.php` or `register_organization_registry.php`), then **`require`** from **`system/routes/web.php`** in a deliberate order (see **`ROUTE-REGISTRATION-TOPOLOGY-TRUTH-OPS.md`** if present; pattern matches **`register_branches.php`**).
- **Module** HTTP routes that live under `modules/*/routes/web.php` exist for some domains (`intake`, `gift-cards`, …); **organizations** module currently has **no** `routes/web.php` — either style is in-repo; **branches** use **`routes/web/register_branches.php`**, which is the closest analogue for a staff admin surface.

### 2.3 Controller / provider conventions

- **Branch admin:** `BranchAdminController` is constructed via **`register_branches.php`** singleton with `BranchDirectory`, `AuditService`, `PermissionService`.
- **Pattern:** Controller methods return void; load views with `require base_path('modules/.../views/...')`; use `flash()`, redirect, `SessionAuth::csrfToken()` for forms; **POST** actions expect CSRF (global **`CsrfMiddleware`**).
- **Comparable org-registry controller (future):** Inject `OrganizationRegistryReadService` / `OrganizationRegistryMutationService` + optional `AuditService` for parity with branch create/update auditing — **not** required by F-41 but consistent with **`BranchAdminController::store`**.

### 2.4 Auth + permission middleware (current truth)

- **`AuthMiddleware`:** Requires authenticated user; applies inactivity + optional password expiry; then calls **`StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()`** (F-25).
- **`PermissionMiddleware`:** Requires `AuthService::user()`; then **`PermissionService::has($userId, $permission)`**.
- **`PermissionService::getForUser`:** Role permissions (all branches) **∪** staff-group permissions for **current `BranchContext` branch** (`listPermissionCodesForUserInBranchScope`). Platform codes are **not** special-cased; they flow through the same `has()` logic.
- **Wildcard:** If user has `*` or `platform.*`-style prefix entries in the merged set, `has()` can pass without an exact code (see `PermissionService::has`).

### 2.5 Platform boundary vs “ordinary tenant admin”

- **Mechanism today:** **Only** the **permission string** on the route (e.g. `PermissionMiddleware::for('platform.organizations.view')`) distinguishes platform registry access from `branches.view` / `settings.view`. There is **no** separate middleware type, route group flag, or “hosting” tier in **`Dispatcher`**.
- **Seed truth:** **`001_seed_roles_permissions.php`** grants **every** permission id to the **`owner`** role only (`foreach ($permIds as $p)`). **`admin`** / **`reception`** roles get **no** automatic loop — so **default `admin` users do not** receive `platform.organizations.*` unless manually granted via `role_permissions` / staff groups.
- **Risk:** On typical single-tenant installs, **`owner`** holds **both** full tenant powers **and** `platform.organizations.*`, so the **technical** boundary is RBAC-only, not a separate principal class. That matches F-36/F-37 “no founder HTTP layer yet” history but means **URL + permission choice** must avoid implying “every branch admin can list all orgs.”

---

## 3. First HTTP surface — backend boundary (phase 1)

### 3.1 Routes needed (minimal)

| HTTP | Path (illustrative) | Action | Service | Permission (F-37 / F-39) |
|------|---------------------|--------|---------|-------------------------|
| GET | `/platform/organizations` (or `/organizations` under explicit prefix) | List registry | `OrganizationRegistryReadService::listOrganizations()` | `platform.organizations.view` |
| GET | `/platform/organizations/{id:\d+}` | Show one | `OrganizationRegistryReadService::getOrganizationById` | `platform.organizations.view` |

**Prefix recommendation:** Use a **dedicated URL prefix** (e.g. **`/platform/organizations`**) so the surface is not confused with tenant **`/branches`** or future in-tenant profile editing. Not mandated by code today — **product choice** — but reduces “accidental tenant feature” perception.

### 3.2 Read-first vs mutation-later

| Phase | Expose | Rationale |
|-------|--------|-----------|
| **1a (safest)** | **GET list + GET show** only | Proves routing, DI, views/HTML (or JSON), and **`platform.organizations.view`** without writes or CSRF POST complexity |
| **1b** | POST create / suspend / reactivate / profile (platform) | Requires **`platform.organizations.manage`** on mutating routes, CSRF tokens on forms, validation mapping to `OrganizationRegistryMutationService`, and explicit UX/error handling |

**Answer:** Backend is **ready** for both **reads** and **mutations** at the **service** layer; for **HTTP**, **reads should ship first** to preserve proof-first ordering and smaller blast radius.

### 3.3 In-tenant profile (separate track)

- F-37 assigns **name/code** edits for **resolved org** to **`organizations.profile.manage`**.
- That should **not** reuse the **global list** service for “edit arbitrary org by id”; controller must assert **target org id == `OrganizationContext::getCurrentOrganizationId()`** (or equivalent) — **out of scope** for the **first** platform registry read wave but must not be conflated with `platform.organizations.manage` cross-tenant edits.

---

## 4. Middleware stack — can it enforce the platform boundary “cleanly”?

**Permission layer:** **Yes** — `PermissionMiddleware::for('platform.organizations.view'|'platform.organizations.manage')` is mechanically sufficient and matches existing patterns (`register_branches.php`, `register_inventory.php`, …).

**Blocking issue — F-25 before permission:**

- **`StaffMultiOrgOrganizationResolutionGate`** runs **inside `AuthMiddleware`** and **before** route middleware executes (`Dispatcher` runs global middleware, then route middleware array).
- When **`OrganizationContextResolver::countActiveOrganizations() > 1`** and **`OrganizationContext::getCurrentOrganizationId()`** is null/≤0, the gate returns **403** with code **`ORGANIZATION_CONTEXT_REQUIRED`** (JSON) or plain text — **unless** path is exempt (`/logout` POST, `/account/password` GET/POST only).
- Therefore a **platform operator** who is authenticated but **lacks** a resolved org (e.g. no branch session, HQ user, or multi-org ambiguity) **cannot reach** `PermissionMiddleware` on a new registry route **without** a **code change** to exempt or bypass the gate for **explicit platform paths** (or without always having resolved org — contradicts F-37 design note that platform registry need not “be inside” a tenant).

**Conclusion:** The stack **does not** today cleanly support “platform-only registry HTTP” for **multi-org** deployments **without** either:

1. **Extending F-25 exemptions** (or conditional bypass when user has `platform.organizations.*` — **design choice**, not present now), or  
2. **Resolving org context** for that user before the gate (operational workaround, not a general platform pattern).

Single-org deployments (`countActiveOrganizations() <= 1`): gate **no-ops**; **read HTTP** is **reachable** once routes + permissions exist.

---

## 5. Comparable in-repo patterns (exact)

| Need | Follow | Evidence |
|------|--------|----------|
| Route registration | `register_branches.php` | `$router->get(..., [Controller::class, 'method'], [AuthMiddleware::class, PermissionMiddleware::for('...')])` |
| Controller + view | `BranchAdminController::index` | Lists rows from service/directory; passes `$title`, `$csrf`, `flash()`; `require base_path('modules/branches/views/index.php')` |
| POST + redirect | `BranchAdminController::store` | Validates input; calls domain service; `AuditService::log` on success; CSRF via global middleware |
| DI for controller | `register_branches.php` (bootstrap) | `$container->singleton(BranchAdminController::class, fn ($c) => new ...)` |
| Permission deny | `PermissionMiddleware::deny` | 403 JSON if `Accept: application/json`, else `HttpErrorHandler` |

---

## 6. Required truth questions (explicit answers)

| Question | Answer |
|----------|--------|
| Is the repo ready for a **platform-only** organization registry **HTTP read** surface **now**? | **Services + permissions catalog: yes.** **HTTP: no** — **no routes/controllers**. **Multi-org: blocked by F-25** until exempt/bypass path is implemented or org always resolved. |
| Ready for **mutation** HTTP **now**, or **reads first**? | **Reads first** recommended at HTTP layer; **mutation** immediately after **same** F-25 compatibility + **`platform.organizations.manage`** + CSRF. |
| Can current auth/permission middleware distinguish **platform** control **cleanly enough** for phase 1? | **Permission check: yes.** **Org-resolution gate: no** for multi-org without further work — **this is the main platform-boundary gap** for HTTP. |
| Would an existing route group **accidentally** expose registry as ordinary tenant admin? | **No accidental route** exists (no org registry routes). **Future risk:** if URLs are generic (`/organizations`) and **`branches.view`/`settings.*`**-class permissions were misused — mitigated by using **`platform.organizations.*`** only and a clear URL prefix. |

---

## 7. Risk list (exact)

1. **F-25 vs platform HTTP (multi-org):** Authenticated users without resolved `OrganizationContext` get **403** before permission check — platform registry unreadable without gate change or workaround.  
2. **Owner role breadth:** Default seed gives **`owner`** all permissions including **`platform.organizations.*`** — platform codes are **not** isolated to a separate role in-tree.  
3. **`PermissionService` wildcard / `platform.*`:** Users with broad wildcards could pass `has()` — operational seed discipline required (documented in F-36/F-37).  
4. **Cross-tenant data in HTML:** List/show surfaces expose all org rows from `OrganizationRegistryReadService` — must stay behind **`platform.organizations.view`** only.  
5. **In-tenant profile vs platform:** Mixing **`organizations.profile.manage`** (resolved org) with platform list in one controller without strict checks risks wrong scope — split controllers or explicit checks.  
6. **CSRF:** All POST mutations must include CSRF token like **`BranchAdminController`**.  
7. **Staff-group permissions:** Branch-scoped group merge may not attach `platform.*` unless roles grant it — usually correct for platform; verify operator accounts are role-based or documented.

---

## 8. Single safest next implementation wave (name only)

**FOUNDATION-43 — ORGANIZATION-REGISTRY-PLATFORM-HTTP-READ-SURFACE-AND-F25-PLATFORM-PATH-COMPATIBILITY-MINIMAL-R1**

*(Scope implied: add minimal GET routes + controller + views or JSON, **`platform.organizations.view`**, DI for controller, and the **minimum** F-25 / path compatibility so multi-org platform operators can reach the route — exact mechanism left to that wave’s task brief.)*

---

## 9. Stop

This wave **ends** at audit docs + roadmap row. **No** implementation.

**Id note:** **`ORGANIZATION-REGISTRY-MUTATION-SERVICE-IMPLEMENTATION-FOUNDATION-41.md`** §9 named a **membership/context** slice as “FOUNDATION-42”. **This** program wave **FOUNDATION-42** is the **HTTP / platform-guard boundary audit** (task id). **F-37 S5** membership integration remains a **separate future** implementation wave when explicitly tasked (id TBD by roadmap at that time).

