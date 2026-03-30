# BRANCH-WRITE-SURFACES-ORG-SCOPE-AND-CREATION-POLICY-TRUTH-AUDIT — FOUNDATION-33 (OPS)

**Mode:** Read-only audit (no runtime/schema/middleware changes). **Scope:** Branch **mutation** layer only — complements **FOUNDATION-31/32** (admin **read** surfaces). Prior foundations **FOUNDATION-06 through FOUNDATION-32** are **accepted**; this doc does not re-open them unless citing **current tree** behavior they already describe.

---

## 1. Methods audited (exact definitions)

**File:** `system/core/Branch/BranchDirectory.php` — class `Core\Branch\BranchDirectory`.

| Method | Visibility | Role |
|--------|------------|------|
| `createBranch(string $name, ?string $code): int` | public | INSERT branch row + `organization_id` |
| `updateBranch(int $id, string $name, ?string $code): void` | public | UPDATE name/code by `id` |
| `softDeleteBranch(int $id): void` | public | SET `deleted_at` when active |
| `isCodeTaken(string $code, ?int $excludeId): bool` | private | Global `branches.code` collision check |
| `defaultOrganizationIdForNewBranch(): int` | private | `MIN(id)` over active `organizations` |

**Proof (behavior summary):**

- **`createBranch`** — trims/validates name; normalizes code; if code non-null, calls `isCodeTaken($code, null)`; sets `organization_id` to `OrganizationContext::getCurrentOrganizationId()` when non-null, else `defaultOrganizationIdForNewBranch()`; `insert` into `branches`.
- **`defaultOrganizationIdForNewBranch`** — `SELECT MIN(id) AS id FROM organizations WHERE deleted_at IS NULL`; throws `RuntimeException` if no usable row.
- **`updateBranch`** — validates `id`; loads row via `getBranchByIdForAdmin($id)` (F-32 org-scoped when context resolved); `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization($id)`; re-validates name/code; `isCodeTaken($code, $id)` when code set; `UPDATE branches SET name, code ... WHERE id = ?` (**no** `organization_id` in WHERE).
- **`softDeleteBranch`** — same load + assert pattern; idempotent if already soft-deleted; `UPDATE ... WHERE id = ? AND deleted_at IS NULL`.
- **`isCodeTaken`** — `SELECT id FROM branches WHERE code = ?` (or `AND id <> ?` when excluding); **no** `organization_id` predicate — **global** uniqueness across all rows (including soft-deleted rows with same code if still present).

**DI:** `system/bootstrap.php` registers `BranchDirectory` with `Database`, `OrganizationContext`, `OrganizationScopedBranchAssert`.

---

## 2. Exact PHP callers (mutation)

**Runtime invocation** of `createBranch` / `updateBranch` / `softDeleteBranch` exists in **one** application class:

| File | Function | Calls |
|------|----------|--------|
| `system/modules/branches/controllers/BranchAdminController.php` | `store()` | `$this->branches->createBranch($name, $code)` |
| Same | `update(int $id)` | `$this->branches->updateBranch($id, $name, $code)` |
| Same | `destroy(int $id)` | `$this->branches->softDeleteBranch($id)` |

**`isCodeTaken` / `defaultOrganizationIdForNewBranch`:** no external callers; only used inside `BranchDirectory` (`createBranch`, `updateBranch`, and the create-path fallback).

**Non-runtime reference:** `system/scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php` — static file scan + JSON notes; **does not** invoke branch mutations (classified in the matrix).

---

## 3. Route / controller / policy path (proof)

**Routes:** `system/routes/web/register_branches.php`

| HTTP | Path | Handler | Middleware (order after globals) |
|------|------|---------|----------------------------------|
| GET | `/branches` | `BranchAdminController::index` | `AuthMiddleware`, `PermissionMiddleware::for('branches.view')` |
| GET | `/branches/create` | `create` | `AuthMiddleware`, `PermissionMiddleware::for('branches.manage')` |
| POST | `/branches` | `store` | same |
| GET | `/branches/{id}/edit` | `edit` | same |
| POST | `/branches/{id}` | `update` | same |
| POST | `/branches/{id}/delete` | `destroy` | same |

**Global pipeline (before route middleware):** `Dispatcher` — `CsrfMiddleware`, `ErrorHandlerMiddleware`, `BranchContextMiddleware`, `OrganizationContextMiddleware` (`system/core/router/Dispatcher.php`).

**Authenticated role / policy:**

- **Session user** required (`AuthMiddleware`).
- **Mutations** require permission **`branches.manage`** (`PermissionMiddleware`).
- **Index** requires **`branches.view`** only.
- `BranchAdminController::canManageBranches()` checks `branches.manage` for **UI** on `index` only; **`store` / `update` / `destroy` do not** call it — enforcement is **route-level** RBAC.

**Organization context resolution (same request, pre-auth):**

- `OrganizationContextResolver::resolveForHttpRequest` (`system/core/Organization/OrganizationContextResolver.php`): branch context → org from active branch+org JOIN; no branch → **single** active org ⇒ set that org (`MODE_SINGLE_ACTIVE_ORG_FALLBACK`); **multiple** active orgs with no branch ⇒ `getCurrentOrganizationId()` **null** (`MODE_UNRESOLVED_AMBIGUOUS_ORGS`); zero orgs ⇒ null (`MODE_UNRESOLVED_NO_ACTIVE_ORG`).

**Post-auth multi-org gate (FOUNDATION-25):**

- `AuthMiddleware` calls `StaffMultiOrgOrganizationResolutionGate::enforceForAuthenticatedStaff()` (`system/core/middleware/AuthMiddleware.php`).
- If **more than one** active organization and `getCurrentOrganizationId()` is null/≤0 ⇒ **403** (staff cannot reach controller).
- If active org count **≤ 1**, gate **returns without** requiring resolved org — **degenerate paths** (especially **zero** orgs) can still run authenticated routes with **null** org context (`system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php`).

---

## 4. Separation of behaviors (policy truth)

### 4.1 Create behavior

- **Org pin:** Resolved org id from `OrganizationContext` when non-null; else **`defaultOrganizationIdForNewBranch()`** = MIN active `organizations.id`.
- **Normal staff HTTP (≥1 org, multi-org deployment):** F-25 blocks unresolved org; with one org, F-09 sets org via single-org fallback when branch null — **`createBranch` typically receives non-null org** in healthy deployments.
- **Null-org + `defaultOrganizationIdForNewBranch`:** Still **reachable** when `countActiveOrganizations() <= 1` **and** resolver left org null (e.g. **zero** active orgs: `MODE_UNRESOLVED_NO_ACTIVE_ORG`) — gate does not block; **`createBranch` then throws** `RuntimeException` (no MIN org), not a silent wrong-tenant insert.
- **Tenancy risk assessment:** For **multi-org + authenticated staff**, F-25 **prevents** reaching `createBranch` with unresolved org — the historical “pin to MIN id across many orgs” path is **not** staff-HTTP reachable. The fallback remains justified as **single-org / CLI / non-HTTP** compatibility (aligned with **FOUNDATION-08/11**), but **any future non-HTTP caller** with null context still pins to MIN id — **latent coupling** if multiple orgs exist outside HTTP resolution rules.

### 4.2 Update / delete behavior

- **Load:** `getBranchByIdForAdmin` — **org-scoped when org resolved** (F-32); **global id-only** when org null.
- **Enforcement:** `OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization($id)` — **no-op when `getCurrentOrganizationId()` is null** (`system/core/Organization/OrganizationScopedBranchAssert.php` lines 31–33).
- **SQL:** UPDATEs filter by **`id` only** (not `organization_id`).
- **Sufficient for org safety:** **When org is resolved**, assert + F-32 load **match** branch row to current org before mutate — **sufficient** for ownership. **When org is null** (degenerate), **no** org enforcement on mutate — **legacy global** behavior; **reachable** only through F-25’s `count ≤ 1` escape hatch (see F-27).

### 4.3 Code uniqueness behavior

- **`isCodeTaken` is global** across all `branches` rows (no org filter, no `deleted_at` filter).
- **Product / coupling:** Two organizations **cannot** each use the same non-null `code` **even if** branches are otherwise isolated — **cross-org coupling**. Whether that is product-correct depends on whether `code` is intended as a **global** business key vs **per-organization** short code (not decided in code).

### 4.4 Null-org and single-org fallback (interaction)

| Condition | Org context after global middleware | F-25 | Branch mutations reachable? |
|-----------|--------------------------------------|------|---------------------------|
| Multi-org, branch null | Unresolved null | **403** | **No** |
| Single org, branch null | Resolved via fallback | pass | **Yes**, org pinned |
| Zero org | null | pass (`count ≤ 1`) | **Yes**; create throws; update/delete use global id paths |

---

## 5. Caller classification (summary)

See **`BRANCH-WRITE-CALLER-MATRIX-FOUNDATION-33.md`**. **Net:** one **organization-admin mutation surface** (`BranchAdminController` + `branches.manage`); **no** mixed/ambiguous **call sites**; verifier script = **tooling only**, not a mutation caller.

---

## 6. Fit / waiver / risk (concise)

| Topic | Assessment |
|-------|------------|
| **Mutation surface closure** | All three mutators are **only** reachable via **`BranchAdminController`** + documented routes + **`branches.manage`**. |
| **`defaultOrganizationIdForNewBranch`** | **Justified** as F-08/F-11 single-org default and non-resolved-context fallback; **not** a live multi-org staff-HTTP tenancy leak due to **F-25**; **residual** risk = **non-HTTP** / **future** callers with null context in multi-org data. |
| **Update/delete ownership** | **Org-safe when context resolved** (assert + F-32 reads); **degenerate null-org** aligns with F-21/F-27 **legacy global** waiver. |
| **Global `code` uniqueness** | **Provable cross-org coupling**; product should **explicitly** accept global codes or schedule **org-scoped** uniqueness when context resolves. |

---

## 7. Recommended single next wave (not opened here)

**FOUNDATION-34 — BRANCH-WRITE-MUTATIONS-ORG-SCOPED-CODE-UNIQUENESS-AND-DEFENSIVE-SQL-R1** (name for scheduling only): product decision on **`branches.code`** scope; implement **org-aware `isCodeTaken`** when `OrganizationContext` resolves (preserve explicit legacy/global mode if required); optional **defense-in-depth** `UPDATE`/`DELETE` adding `AND organization_id = ?` when org resolved; document or gate **`defaultOrganizationIdForNewBranch`** for **non-HTTP** entrypoints if CLI/jobs gain branch creators.

**Stop:** No further waves named beyond this line.

---

## 8. Code citations (anchor lines)

```113:134:system/core/Branch/BranchDirectory.php
    public function createBranch(string $name, ?string $code): int
    {
        // ...
        if ($code !== null && $this->isCodeTaken($code, null)) {
            throw new \InvalidArgumentException('That branch code is already in use.');
        }

        $resolvedOrg = $this->organizationContext->getCurrentOrganizationId();
        $organizationId = $resolvedOrg !== null ? $resolvedOrg : $this->defaultOrganizationIdForNewBranch();

        return $this->db->insert('branches', [
            'name' => $name,
            'code' => $code,
            'organization_id' => $organizationId,
        ]);
    }
```

```142:154:system/core/Branch/BranchDirectory.php
    private function defaultOrganizationIdForNewBranch(): int
    {
        $row = $this->db->fetchOne(
            'SELECT MIN(id) AS id FROM organizations WHERE deleted_at IS NULL'
        );
        // ... throws RuntimeException if missing
        return (int) $row['id'];
    }
```

```156:180:system/core/Branch/BranchDirectory.php
    public function updateBranch(int $id, string $name, ?string $code): void
    {
        // ...
        $existing = $this->getBranchByIdForAdmin($id);
        // ...
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($id);
        // ...
        if ($code !== null && $this->isCodeTaken($code, $id)) {
            throw new \InvalidArgumentException('That branch code is already in use.');
        }
        $this->db->query(
            'UPDATE branches SET name = ?, code = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$name, $code, $id]
        );
    }
```

```213:225:system/core/Branch/BranchDirectory.php
    private function isCodeTaken(string $code, ?int $excludeId): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE code = ? AND id <> ? LIMIT 1',
                [$code, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne('SELECT id FROM branches WHERE code = ? LIMIT 1', [$code]);
        }

        return $row !== null;
    }
```

```52:131:system/modules/branches/controllers/BranchAdminController.php
    public function store(): void
    {
        // ...
            $id = $this->branches->createBranch($name, $code);
        // ...
    }
    // ...
    public function update(int $id): void
    {
        // ...
            $this->branches->updateBranch($id, $name, $code);
        // ...
    }
    // ...
    public function destroy(int $id): void
    {
        // ...
            $this->branches->softDeleteBranch($id);
        // ...
    }
```

```9:14:system/routes/web/register_branches.php
$router->get('/branches', [\Modules\Branches\Controllers\BranchAdminController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.view')]);
$router->get('/branches/create', [\Modules\Branches\Controllers\BranchAdminController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->post('/branches', [\Modules\Branches\Controllers\BranchAdminController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->get('/branches/{id:\d+}/edit', [\Modules\Branches\Controllers\BranchAdminController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->post('/branches/{id:\d+}', [\Modules\Branches\Controllers\BranchAdminController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->post('/branches/{id:\d+}/delete', [\Modules\Branches\Controllers\BranchAdminController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
```

```26:48:system/core/Organization/OrganizationScopedBranchAssert.php
    public function assertBranchOwnedByResolvedOrganization(?int $branchId): void
    {
        if ($branchId === null || $branchId <= 0) {
            return;
        }
        if ($this->organizationContext->getCurrentOrganizationId() === null) {
            return;
        }
        // ... load branch organization_id, assert match
    }
```

```27:43:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php
    public function enforceForAuthenticatedStaff(): void
    {
        // ...
        if ($this->resolver->countActiveOrganizations() <= 1) {
            return;
        }

        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return;
        }

        $this->denyUnresolvedOrganization();
    }
```
