# BRANCH-DOMAIN-POST-HARDENING-CONSOLIDATED-CLOSURE-TRUTH-AUDIT — FOUNDATION-35 (OPS)

**Mode:** Read-only consolidation after **FOUNDATION-30**, **FOUNDATION-32**, **FOUNDATION-34** (ZIP-accepted per program). **No** code changes in this task. Prior foundations **FOUNDATION-06 through FOUNDATION-34** are **not re-opened** except where this doc cites **current tree** behavior that continues those contracts.

---

## 1. Exact `BranchDirectory` methods audited

**File:** `system/core/Branch/BranchDirectory.php`

| Method | Role |
|--------|------|
| `isActiveBranchId(int $branchId): bool` | `SELECT 1 ... WHERE id = ? AND deleted_at IS NULL` — **no** `organization_id` |
| `getActiveBranchesForSelection(): array` | F-30: org filter when context resolved |
| `listAllBranchesForAdmin(): array` | F-32: org filter when context resolved |
| `getBranchByIdForAdmin(int $id): ?array` | F-32: `id` + `organization_id` when resolved |
| `createBranch(string $name, ?string $code): int` | F-34: context org vs `defaultOrganizationIdForNewBranch()`; `isCodeTaken` org-scoped when resolved |
| `updateBranch(int $id, string $name, ?string $code): void` | F-11 assert + F-34 defensive `UPDATE` + org-scoped `isCodeTaken` when resolved |
| `softDeleteBranch(int $id): void` | F-11 assert + F-34 defensive `UPDATE` when resolved |
| `isCodeTaken(string $code, ?int $excludeId): bool` | **Private.** F-34: per-org when resolved; **global** when context null |
| `defaultOrganizationIdForNewBranch(): int` | **Private.** `MIN(id)` active organizations; used only on **null/unresolved** context path for `createBranch` |

---

## 2. Branch admin HTTP path (exact)

**Routes:** `system/routes/web/register_branches.php`

| Method | Path | Controller | Permissions |
|--------|------|--------------|-------------|
| GET | `/branches` | `BranchAdminController::index` | `AuthMiddleware` + `branches.view` |
| GET | `/branches/create` | `create` | `AuthMiddleware` + `branches.manage` |
| POST | `/branches` | `store` | same |
| GET | `/branches/{id}/edit` | `edit` | same |
| POST | `/branches/{id}` | `update` | same |
| POST | `/branches/{id}/delete` | `destroy` | same |

**Organization context entry:** Global pipeline (`system/core/router/Dispatcher.php`): `BranchContextMiddleware` → `OrganizationContextMiddleware` → route middleware (`AuthMiddleware` → `PermissionMiddleware`). **`OrganizationContextResolver::resolveForHttpRequest`** (F-09) fills `OrganizationContext` from branch row or single-org fallback.

**Multi-org unresolved staff:** **`StaffMultiOrgOrganizationResolutionGate`** inside **`AuthMiddleware`** (F-25): **>1** active org and **no** resolved positive org id ⇒ **403** before controller. Branch admin mutations **do not** run in that state.

**Null-org / single-org reachability:** When active org **count ≤ 1**, the gate **does not** require resolved org. **Single** active org: resolver sets org (F-09). **Zero** orgs: org may stay **null** (degenerate); **`createBranch`** throws via `defaultOrganizationIdForNewBranch()` if that path runs; reads/mutations follow **legacy global** dual-path in `BranchDirectory` (F-27 / F-33 / F-34 docs).

---

## 3. Consumer reconfirmation (PHP call sites)

### 3.1 Branch selector reads — `getActiveBranchesForSelection()`

| File | Function / context |
|------|---------------------|
| `system/modules/appointments/controllers/AppointmentController.php` | branch options helper |
| `system/modules/inventory/controllers/ProductBrandController.php` | multiple actions + loop |
| `system/modules/inventory/controllers/ProductCategoryController.php` | multiple actions + loop |
| `system/modules/inventory/controllers/ProductController.php` | helper return |
| `system/modules/inventory/controllers/StockMovementController.php` | helper return |
| `system/modules/inventory/controllers/SupplierController.php` | helper return |
| `system/modules/inventory/controllers/InventoryCountController.php` | helper return |
| `system/modules/settings/controllers/SettingsController.php` | two call sites via container |
| `system/modules/clients/controllers/ClientController.php` | helper return |
| `system/modules/sales/controllers/InvoiceController.php` | helper return |
| `system/modules/sales/controllers/RegisterController.php` | helper return |
| `system/modules/gift-cards/controllers/GiftCardController.php` | helper return |
| `system/modules/marketing/controllers/MarketingCampaignController.php` | helper return |
| `system/modules/memberships/controllers/MembershipDefinitionController.php` | helper return |
| `system/modules/memberships/controllers/ClientMembershipController.php` | helper return |
| `system/modules/memberships/controllers/MembershipRefundReviewController.php` | helper return |
| `system/modules/packages/controllers/PackageDefinitionController.php` | helper return |
| `system/modules/packages/controllers/ClientPackageController.php` | helper return |
| `system/modules/payroll/controllers/PayrollRunController.php` | helper return |

**Behavior:** F-30 — **resolved org** ⇒ SQL `organization_id = ?` + active only; **null org** ⇒ global active list.

### 3.2 Branch admin reads / mutations — `BranchAdminController`

| Method | `BranchDirectory` calls |
|--------|-------------------------|
| `index` | `listAllBranchesForAdmin` |
| `store` | `createBranch`, `getBranchByIdForAdmin` (audit) |
| `edit` | `getBranchByIdForAdmin` |
| `update` | `getBranchByIdForAdmin`, `updateBranch`, `getBranchByIdForAdmin` |
| `destroy` | `getBranchByIdForAdmin`, `softDeleteBranch` |

**Behavior:** F-32 reads + F-34 mutations/code when org resolved; legacy when null.

### 3.3 `isActiveBranchId`

| File | Usage |
|------|--------|
| `system/core/middleware/BranchContextMiddleware.php` | Validates user/request/session branch candidates |
| `system/modules/inventory/controllers/ProductController.php` | Validation |
| `system/modules/memberships/controllers/ClientMembershipController.php` | Validation |

**Behavior:** **Unchanged** across F-30/32/34 — **global** “exists and not soft-deleted”; **no** org predicate.

**Architectural note:** `BranchContextMiddleware` runs **before** `OrganizationContextMiddleware`. **`OrganizationContext` is (re)set in the org middleware** via `resolveForHttpRequest` → `reset()`. Therefore **`isActiveBranchId` cannot correctly use “current request” resolved org when invoked from branch middleware** without **reordering middleware or adding a separate resolution path** (out of scope for F-30–34; not proposed as a single-file wave).

### 3.4 Internal-only

- `getBranchByIdForAdmin` — also called from `updateBranch`, `softDeleteBranch` inside `BranchDirectory`.

### 3.5 Non-runtime

- `system/scripts/verify_organization_scoped_choke_points_foundation_11_readonly.php` — static expectations for F-11; **not** a runtime consumer.

---

## 4. Closure checks (per surface)

| Surface | Resolved org | Null org | Multi-org unresolved HTTP | Remaining cross-org / global coupling |
|---------|--------------|----------|---------------------------|-------------------------------------|
| `getActiveBranchesForSelection` | Org-scoped active branches | Global active branches | Blocked before staff pages (F-25) | Null-org global list (waiver F-27) |
| `listAllBranchesForAdmin` | Org-scoped all rows | Global all rows | Blocked | Null-org: metadata breadth if `branches.view` + degenerate context |
| `getBranchByIdForAdmin` | `id` + `organization_id` | Id-only | Blocked for typical staff | Null-org id-only (waiver) |
| `createBranch` / `updateBranch` / `softDeleteBranch` | Org pin + assert + F-34 SQL + per-org code check | MIN-org create; global code; id-only UPDATE | Blocked | Null-org legacy (waiver) |
| `isCodeTaken` | Per-org | Global | N/A (private) | Coupled to context; intentional |
| `isActiveBranchId` | **Global** | **Global** | N/A at this layer | **Any** active branch id valid; branch **session/request** pivot rules in `BranchContextMiddleware` (waiver / future program) |

**Mutation paths:** Only **`BranchAdminController`** (F-33). No additional mutation call sites introduced.

**Branch code uniqueness:** **Per-organization** when `getCurrentOrganizationId()` is **> 0**; **global** when context is null — **intentional** F-34 split (not “global everywhere”).

---

## 5. Remaining risk / waiver list (explicit)

1. **Degenerate null-org dual-path** on all `BranchDirectory` methods that branch on context — **documented** since F-21/F-27/F-33/F-34; mitigated for **normal multi-org staff** by F-25.
2. **`isActiveBranchId` + branch middleware order** — eligibility of branch ids is **not** org-scoped at the directory method; org is derived **after** branch resolution (F-09). This is **not contradicted** by F-30/32/34; it is a **separate** tenancy-adjacent topic if product requires org-locked branch picking without middleware redesign.
3. **`branches.view` / `branches.manage`** — no separate “cross-org operator” permission tier (**F-31 option C** still a **product** fork, not implemented).
4. **Soft-deleted rows** still participate in **`isCodeTaken`** (no `deleted_at` filter) — unchanged by design across waves.

---

## 6. Final closure verdict (exactly one)

**B) Closed with documented waiver(s).**

**Rationale:** The **branch catalog** work targeted by **F-30 / F-32 / F-34** (shared selector reads, admin list/id reads, mutations, org-scoped code + defensive SQL) is **consistent in tree** and **operationally closed** for **normal multi-org staff HTTP** under **F-25**. **Residual** behavior is **explicitly** the **null-org legacy** path and **global** `isActiveBranchId` / branch-resolution ordering — **waived** here as **outside** the closed F-30/32/34 scope, not an unlisted “mystery” gap.

**Not C:** A single **minimal backend-only** follow-up in **`BranchDirectory` alone** does **not** safely close **`isActiveBranchId`** semantics for **`BranchContextMiddleware`** without **middleware / pipeline** consideration (see §3.3). Therefore this audit does **not** mandate **FOUNDATION-36** as a one-file fix.

---

## 7. Single recommended next program (after branch-domain closure)

**Continue the active product/platform queues in `BOOKER-PARITY-MASTER-ROADMAP.md`** — e.g. the **next §5.C** or **§6** item already prioritized for the program (fresh **FULL ZIP** truth audit, or the next deferred domain). **No** additional **FOUNDATION-*** branch wave is **required** by this closure audit.

**Optional future** (only if product mandates): **branch context org-lock** (middleware / user-model / `isActiveBranchId` contract) or **F-31 option C** permissions — **named** as programs, **not** opened as waves here.

---

## 8. Code anchors (current tree)

```31:40:system/core/Branch/BranchDirectory.php
    public function isActiveBranchId(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM branches WHERE id = ? AND deleted_at IS NULL',
            [$branchId]
        );

        return $row !== null;
    }
```

```52:64:system/core/Branch/BranchDirectory.php
    public function getActiveBranchesForSelection(): array
    {
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            return $this->db->fetchAll(
                'SELECT id, name, code FROM branches WHERE deleted_at IS NULL AND organization_id = ? ORDER BY name',
                [$orgId]
            );
        }

        return $this->db->fetchAll(
            'SELECT id, name, code FROM branches WHERE deleted_at IS NULL ORDER BY name'
        );
    }
```

```235:261:system/core/Branch/BranchDirectory.php
    private function isCodeTaken(string $code, ?int $excludeId): bool
    {
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId !== null && $orgId > 0) {
            // ... organization_id predicates
        } else {
            // ... global predicates
        }

        return $row !== null;
    }
```

---

## 9. Stop condition

Delivered: this ops doc + **`BRANCH-DOMAIN-CONSOLIDATED-SURFACE-MATRIX-FOUNDATION-35.md`** + one **§8** roadmap row. **No** FOUNDATION-36 opened.
