# ORGANIZATION-REGISTRY-AND-FOUNDER-PLATFORM-ADMIN-BOUNDARY-TRUTH-AUDIT — FOUNDATION-36 (OPS)

**Mode:** Read-only whole-repo truth audit after **FOUNDATION-06 through FOUNDATION-35** (accepted unless contradicted below). **No** code/migration/UI changes in this task. **Branch-domain** closure (**F-30/32/34/35**) is **not reopened** except where it **intersects organization-control** (branch `organization_id`, branch admin vs org registry).

---

## 1. Organization data / control foundation (audited artifacts)

### 1.1 Schema / migrations

| Artifact | Truth |
|----------|--------|
| **`system/data/migrations/086_organizations_and_branch_ownership_foundation.sql`** | Creates **`organizations`** (`id`, `name`, `code`, timestamps, `deleted_at`, `uk_organizations_code`); conditional seed **`Default organization`**; adds **`branches.organization_id`** NOT NULL + FK to **`organizations`** |
| **`system/data/full_project_schema.sql`** | Same table shapes; bootstrap **`INSERT INTO organizations`** when empty; **`branches`** includes **`organization_id`** + FK |

**No later migration** in-repo (grep) adds **`users.organization_id`** or an organization membership pivot table.

### 1.2 Branch ownership linkage

- **`branches.organization_id`** → **`organizations.id`**, `ON DELETE RESTRICT`.
- Runtime writes to **`branches`** (including org pin) via **`BranchDirectory`** (accepted F-08/11/34).
- **No** PHP writes to **`organizations`** rows located in application code (see §2).

### 1.3 Runtime services/helpers depending on organization identity

| Component | Path | Role |
|-----------|------|------|
| **`OrganizationContext`** | `system/core/Organization/OrganizationContext.php` | Request-scoped current org id + resolution mode (F-09) |
| **`OrganizationContextResolver`** | `system/core/Organization/OrganizationContextResolver.php` | Branch → org via JOIN; branch null → single active org or null (count / ambiguity) |
| **`OrganizationContextMiddleware`** | `system/core/middleware/OrganizationContextMiddleware.php` | Invokes resolver after **`BranchContextMiddleware`** |
| **`OrganizationScopedBranchAssert`** | `system/core/Organization/OrganizationScopedBranchAssert.php` | Branch row `organization_id` vs context when org resolved (F-11) |
| **`OrganizationRepositoryScope`** | `system/core/Organization/OrganizationRepositoryScope.php` | SQL fragments for repo scoping (F-13+) |
| **`StaffMultiOrgOrganizationResolutionGate`** | `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php` | Multi-org + unresolved org ⇒ 403 after auth (F-25) |
| **`BranchDirectory::defaultOrganizationIdForNewBranch`** | `system/core/Branch/BranchDirectory.php` | `MIN(id)` active org when context not used for create (legacy path) |

**Callers of `organizations` table from PHP (grep `organizations` in `*.php`):** **`OrganizationContextResolver`**, **`BranchDirectory`**, **`OrganizationRepositoryScope`** (via EXISTS subquery), plus **read-only / dev scripts** under `system/scripts/` — **no** insert/update/delete of **`organizations`** in application services.

### 1.4 Bootstrap / config / docs implying platform administration

- **`system/bootstrap.php`**: registers org-related **singletons** (context, resolver, gate, scope, assert) — **infrastructure**, not a “founder UI”.
- **`BOOKER-PARITY-MASTER-ROADMAP.md` §6**: describes **platform / SaaS spine** (tenancy, internal admin / platform control plane as **future** item) — **aspirational** vs **implemented** backend.
- **`ORGANIZATION-BOUNDARY-CANONICAL-DESIGN-FOUNDATION-07.md`**: **design** for org boundary; explicitly notes pre-F-08 state; **target** model includes org-scoped RBAC **future**.

---

## 2. Organization record lifecycle (code truth)

| Operation | In-repo application code? |
|-----------|-------------------------|
| **Create / update / soft-delete organization** | **No** HTTP or service layer found |
| **Read organization list for staff** | **No** dedicated controller |
| **Infer org count / single org** | **Yes** — **`OrganizationContextResolver`**, **`StaffMultiOrgOrganizationResolutionGate::countActiveOrganizations()`** |

**Conclusion:** Organization **lifecycle** is **data + migration/bootstrap only**, not an **operational registry** in PHP.

---

## 3. Admin / control surfaces — scope classification

| Surface | Routes / entry | Permission | Org context | Classification |
|---------|----------------|------------|-------------|----------------|
| **Branch catalog admin** | `register_branches.php` → **`BranchAdminController`** | `branches.view` / `branches.manage` | Resolved under normal multi-org staff (F-25); F-32/34 directory behavior | **Organization-admin–aligned** when context resolved (via branch/org inference); **legacy global** when org null (degenerate) |
| **Staff / groups** | `register_staff.php` | `staff.*` | Same global pipeline | **Branch-scoped** staff groups + **global** role permissions — **not** org-scoped RBAC |
| **Settings** | `register_settings.php` → **`SettingsController`** | `settings.view` / `settings.edit` | Operator branch merge (`branch_id` 0 + overlay) | **Global + branch overlay** — **no** `organization_id` on **`settings`** rows in schema |
| **VAT / other settings submodules** | settings zone routes | module permissions | Branch / global per doc | **Not** org-first |
| **Roles/permissions catalog** | DB tables | Assigned via **`user_roles`** + staff groups | N/A | **Global permission catalog**; **no** `organization_id` on assignments |

**Founder / platform-global admin layer (dedicated):** **Missing entirely** — no **`/platform/*`**, **`/organizations`**, **`organizations.manage`**, or equivalent in **`system/routes`**.

---

## 4. User / control-plane identity model (code truth)

### 4.1 `users` table (canonical schema)

From **`system/data/full_project_schema.sql`**: **`users`** has **`branch_id`** (nullable FK to **`branches`**), **`email`**, roles via **`user_roles`**, **no** **`organization_id`** column.

### 4.2 How organization authority is inferred today

1. **`BranchContextMiddleware`** sets **`BranchContext`** from session / request / **`users.branch_id`** (F-06/F-07 truth).
2. **`OrganizationContextMiddleware`** → **`OrganizationContextResolver`**: if branch set, org = **`branches.organization_id`** joined to active **`organizations`**; if branch null, org = only active org when **count = 1**, else null.
3. **No** direct “user belongs to org X” column or pivot in schema.

### 4.3 Roles / permissions vs founder vs org admin

- **`PermissionService`** (`system/core/Permissions/PermissionService.php`): merges **global** role permissions + **branch-scoped** staff-group permissions for **`BranchContext::getCurrentBranchId()`**. Supports wildcard **`*`** in **effective** permission list **if present in DB** — **no** migration in-repo seeds **`*`** (grep **`permissions` SQL**).
- **No** separate permission code family (e.g. `platform.*`, `organizations.*`) found in migrations/routes.
- **“HQ”** pattern (F-07): **`users.branch_id` null** allows broader branch pivot rules in middleware — **not** the same as **platform founder** or **org owner** in code.

### 4.4 Global-capable reads/writes vs platform-only controls

Powerful module permissions (**`settings.edit`**, **`staff.*`**, **`branches.manage`**, etc.) gate **operational** admin, **not** a separate **platform operator** tier. **Org isolation** for those actions is **downstream** of **OrganizationContext** + repo/assert patterns (F-11–F-20+), **not** an explicit “this user may act across all tenants” flag distinct from “this user has settings.edit”.

---

## 5. Enumerated PHP-related surfaces (consolidated)

**Organization infrastructure (core):**  
`OrganizationContext.php`, `OrganizationContextResolver.php`, `OrganizationScopedBranchAssert.php`, `OrganizationRepositoryScope.php`, `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationContextMiddleware.php`, `BranchDirectory.php` (org MIN query + F-34).

**Branch admin intersecting org:**  
`BranchAdminController.php`, `register_branches.php`.

**Consumers of org-scoped **repository** helpers (representative, not exhaustive):**  
Marketing, payroll, clients, etc. — per **F-12+** docs; they **consume** context, not **manage** organizations.

**Verification / dev (non-production admin):**  
`verify_organization_context_resolution_readonly.php`, `verify_organization_branch_ownership_readonly.php`, `verify_organization_scoped_choke_points_foundation_11_readonly.php`, `seed_branch_smoke_data.php` (dev-only).

---

## 6. Required truth questions (explicit answers)

| Question | Answer |
|----------|--------|
| **Does the repo have a real founder/platform admin backend layer?** | **No** — no dedicated routes/controllers/services for platform operators; §6 describes future **internal admin / platform control plane**. |
| **Does the repo have an operational organization registry/admin layer?** | **No** — **`organizations`** exists and is **read** for resolution/counts; **no** staff UI or service CRUD. |
| **Can organization lifecycle be managed in-repo, or only inferred from migrations/data?** | **Only migrations/seeds/SQL/operators** — **not** application-managed lifecycle. |
| **Are global-capable reads/writes protected by explicit platform-only controls?** | **No** — they use **ordinary module permissions** + branch/org **context** where implemented; **no** separate platform RBAC layer. |
| **Single biggest backend gap vs true multi-tenant SaaS control plane?** | **No first-class organization registry (CRUD + suspend/archive), no user↔organization membership model in schema, no platform-operator identity/RBAC separated from org staff** — org is **derived** from branch/session topology, not **administered** as a tenant. |

**Schema note (intersects branch domain):** Canonical snapshot **`full_project_schema.sql`** shows **`UNIQUE KEY uk_branches_code (code)`** on **`branches`**. Application-level **F-34** allows duplicate **`code`** across orgs when context resolves; if the **live DB** enforces that unique index on non-null codes, **DB and app semantics may diverge** — **control-plane / migration alignment** risk for multi-org product (not reopened as branch wave; flagged for **org/platform** program).

---

## 7. Risk / gap list (concise)

1. **Missing organization registry** — cannot create/rename/archive orgs in-app.
2. **No `users` ↔ `organization` membership** — org authority is **inferred**, not **assigned**.
3. **No founder/platform RBAC** — powerful permissions are **module**-level, not **tenant-operator**-level.
4. **Settings / VAT / staff** remain **branch-global-merge** or **branch-scoped**, not **organization-default** as in F-07 **target** (three-level settings).
5. **Optional:** **`uk_branches_code`** vs F-34 per-org code policy — verify on deployed DB.

---

## 8. Final decision — single safest next wave (name only)

**FOUNDATION-37 — ORGANIZATION-REGISTRY-AND-PLATFORM-CONTROL-PLANE-MINIMAL-DESIGN-R1**

**Rationale:** Safest next step is a **single** **docs-first** closure that defines **organization lifecycle**, **founder vs org-admin boundary**, **`users` membership model**, and **permission/catalog** approach **before** schema/RBAC/UI implementation — aligned with **§6** Phase 2 direction without opening parallel implementation waves.

**Stop:** No **FOUNDATION-38** named; no implementation in this audit.

---

## 9. Code citations (anchors)

```62:77:system/data/full_project_schema.sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    password_changed_at TIMESTAMP NULL DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    ...
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
```

```30:42:system/core/Permissions/PermissionService.php
    public function has(int $userId, string $permission): bool
    {
        $perms = $this->getForUser($userId);
        if (in_array('*', $perms, true)) {
            return true;
        }
        ...
    }
```

```9:14:system/routes/web/register_branches.php
$router->get('/branches', [\Modules\Branches\Controllers\BranchAdminController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.view')]);
...
```

*(No `register_organizations.php` or `OrganizationController` exists.)*
