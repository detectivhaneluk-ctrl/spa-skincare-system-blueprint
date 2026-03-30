# ORGANIZATION-TENANT-SCOPE-TRUTH-AUDIT-06

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-06 — read-only truth audit.  
**Date:** 2025-03-22  
**Method:** Trace enforcement in PHP middleware, services, repositories, and SQL migrations. Distinguish **proved by code** vs **inference**.

---

## Executive summary (proved)

| Question | Finding |
|----------|---------|
| Highest real isolation boundary | **Single database, single deployment; branch + optional global (`branch_id` NULL / settings `branch_id = 0`) — not organization/tenant-isolated.** |
| `branch_id` authority | **Request-scoped:** `BranchContextMiddleware` resolves from request → session → `users.branch_id` and sets `BranchContext`. **Persistence:** session `branch_id`. **Not** a second org layer. |
| Tenant-ready? | **No.** No `organization_id` (or equivalent) in schema or auth; `grep` over `system/**/*.php` shows no tenant/org enforcement layer. |

---

## 1. Highest real isolation boundary

**Proved:** The application uses one `Database` connection to one configured schema (typical `config` + `Application` bootstrap). There is **no** code path that selects a database or schema per “tenant” or “organization.”

**Proved:** Schema defines `branches` as the top-level business location entity; `users.branch_id` is nullable for “global” operators (`005_create_users_table.sql`, `SessionAuth::user()` selecting `branch_id`).

**Proved:** `BranchContext` documents null context as “global/superadmin” — not a tenant boundary:

```8:11:system/core/Branch/BranchContext.php
 * Request-scoped current branch. Set by BranchContextMiddleware; read by services and controllers.
 * When non-null, the id is an **active** branch (`branches.deleted_at` IS NULL); soft-deleted ids are never set.
 * When set, branch-scoped writes must match; when null, global/superadmin access is allowed (or no valid branch resolved).
```

**Classification:** **Branch-scoped** with explicit **global rows** (`branch_id` NULL on entities, `branch_id = 0` on settings). **Pseudo-organization** only in the sense that one deployment implies one implied business; there is **no** separate org entity. **Not tenant-ready.**

---

## 2. Where `branch_id` is authoritative vs contextual

### Authoritative (request lifecycle)

**Proved:** `BranchContextMiddleware` builds allowed branch IDs from the authenticated user, then resolves from GET/POST `branch_id`, session, or user default:

```55:95:system/core/middleware/BranchContextMiddleware.php
        // Empty list = user row pins a branch id that is no longer active — deny request/session pivot to another branch.
        $allowedBranchIds = $userAssignedBranchInactive ? [] : ($userBranchId !== null ? [$userBranchId] : null);
        ...
        if ($fromRequest !== null && ($allowedBranchIds === null || in_array($fromRequest, $allowedBranchIds, true))) {
            $resolved = $fromRequest;
        } elseif ($fromSession !== null && ($allowedBranchIds === null || in_array($fromSession, $allowedBranchIds, true))) {
            $resolved = $fromSession;
        } elseif ($userBranchId !== null) {
            $resolved = $userBranchId;
        }
        ...
        $context->setCurrentBranchId($resolved);
        if ($resolved !== null) {
            $_SESSION[self::SESSION_KEY] = $resolved;
        }
```

- **Contextual / request-derived:** GET/POST `branch_id` and session, when allowed.
- **Authoritative for constrained users:** If `users.branch_id` is set, `allowedBranchIds` is a single branch — request cannot pivot to another branch.
- **Superadmin pattern (inference from code):** If `users.branch_id` is NULL, `allowedBranchIds` is null → any active branch from request/session is allowed (still **no** org boundary).

### Data writes

**Proved:** Services use `BranchContext::enforceBranchOnCreate` / `assertBranchMatch` (documented in `system/docs/branch-context-foundation-progress.md` and implemented in `BranchContext.php`). **Not every read path** adds `branch_id` to SQL `find()` — see section 4.

### Settings / security reads

**Proved:** `AuthMiddleware` maps null `BranchContext` to `0` for security settings — global-only rows for those keys when not in a branch context:

```51:56:system/core/middleware/AuthMiddleware.php
    private function branchIdForSettings(): int
    {
        $branch = Application::container()->get(BranchContext::class)->getCurrentBranchId();

        return $branch ?? 0;
    }
```

---

## 3. Modules / services / repositories assuming shared global storage

**Proved (architectural):** All modules share the same `Database` and tables; there is no row-level security or org predicate.

**Proved (examples of ID-only reads):** `ProductRepository::find` loads by primary key **without** `branch_id` predicate:

```15:21:system/modules/inventory/repositories/ProductRepository.php
    public function find(int $id, bool $withTrashed = false): ?array
    {
        $sql = 'SELECT * FROM products WHERE id = ?';
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }
        return $this->db->fetchOne($sql, [$id]);
    }
```

Any controller/service that loads by id and **does not** call `assertBranchMatch` before acting allows cross-branch access if the caller can guess IDs (exact exposure depends on route + permission layer — **not** tenant isolation).

**Proved:** `PackageRepository::find` is the same pattern (id-only, optional soft-delete).

**Proved:** `DocumentService::resolveRow` loads owner rows by id only; branch enforcement is **later** via `assertBranchMatch` on the loaded `branch_id`:

```350:356:system/modules/documents/services/DocumentService.php
        $sql = 'SELECT * FROM ' . $table . ' WHERE id = ?';
        if ($checkDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        $row = $this->db->fetchOne($sql, [$id]);
```

**Inference:** List endpoints generally pass `branch_id` filters from controllers/services when branch-scoped; **find-by-id** paths are the primary consistency risk.

---

## 4. Tables: globally shared vs branch-bound vs tenant-sensitive

Derived from `system/data/migrations` **CREATE TABLE** definitions (proved per file).

### No `branch_id` column (globally shared within the one DB)

| Table(s) | Risk for future tenant isolation |
|----------|-----------------------------------|
| `roles`, `permissions`, `role_permissions`, `user_roles` | **Global RBAC catalog**; roles are not branch-scoped or org-scoped. |
| `invoice_number_sequences` | **Global sequence key** `sequence_key` — invoice numbering is not branch-partitioned (`043_payment_refunds_and_invoice_sequence.sql`). |
| `login_attempts` | Global credential abuse tracking (per `010_create_login_attempts_table.sql`). |

### `branch_id` nullable or `0` “global overlay” (branch + global mix)

| Area | Notes |
|------|--------|
| Most operational entities (e.g. `clients`, `appointments`, `products`, `services`, `packages`, …) | Nullable `branch_id` FK to `branches` — **global rows** coexist with branch rows. |
| `settings` | After `014_settings_schema_corrections.sql`: unique (`key`, `branch_id`), `branch_id` default `0` — **global + per-branch overlay** (`SettingsService::get` / `all`). |
| `vat_rates` | Global (`branch_id` NULL) + branch rows (`047_create_vat_rates_table.sql`). |
| `notifications`, `outbound_notification_messages` | Nullable `branch_id` — global broadcast style possible (`048`, `072`). |

### Required `branch_id` (branch-bound row)

| Table | Notes |
|-------|--------|
| `public_commerce_purchases` | `branch_id` NOT NULL (`075_public_commerce_foundation.sql`). |

**Tenant-sensitive (inference):** Any PII tables (`clients`, `users`, intake submissions, consents, documents) are **sensitive** but today are isolated only by **branch + app logic**, not by organization/tenant id.

---

## 5. Settings scope safety (global vs branch)

**Proved:** `SettingsService` implements deterministic merge: for branch `B > 0`, row `(key, B)` wins over `(key, 0)`:

```189:195:system/core/app/SettingsService.php
        $row = $this->db->fetchOne(
            'SELECT `value`, type FROM settings WHERE `key` = ? AND (branch_id = ? OR branch_id = 0) ORDER BY branch_id DESC LIMIT 1',
            [$key, $bid]
        );
```

**Proved bug / inconsistency (UI vs branch context):** `SettingsController::index` loads **`$settings = $settingsService->all()`** with **no** branch argument (defaults to global `0` only), while section-specific getters use `$settingsContextBranch`:

```178:180:system/modules/settings/controllers/SettingsController.php
        $settingsContextBranch = $onlineBookingBranchId > 0 ? $onlineBookingBranchId : null;
        $settings = $settingsService->all();
        $establishment = $settingsService->getEstablishmentSettings($settingsContextBranch);
```

The view renders “other” keys from `$settings` (`system/modules/settings/views/index.php` ~261+), so **misc keys are shown without branch merge** when a branch context is selected via query param. Structured domains use merged getters — **scope-safe for those** if callers pass the correct branch.

**Proved:** `store()` persists patches using `online_booking_context_branch_id` → `settingsSaveBranch` (null = global `0` in `SettingsService`). Branch operators could theoretically POST another branch id if the route does not enforce permission + branch alignment (verify route middleware separately; not re-audited here).

---

## 6. Documents / uploads / exports / paths

**Proved:** Upload path is **not** namespaced by branch or org — only date + random name:

```408:428:system/modules/documents/services/DocumentService.php
        $datePath = date('Y/m');
        $root = base_path('storage/documents/' . $datePath);
        ...
            'storage_path' => 'storage/documents/' . $datePath . '/' . $storedName,
```

**Proved:** DB row carries `branch_id`; read/download uses `assertBranchMatch` on document row. **On disk**, all tenants (future) would share one directory — **collision-safe by random name**, but **not** org-isolated at filesystem level.

**Proved:** Outbound log transport writes under `SYSTEM_PATH . '/storage/'` with path sanitization — no branch prefix (`LogOutboundMailTransport`).

**Inference:** No first-class “export artifact” directory pattern found in quick grep; reports are largely in-DB aggregation. Any future export should be audited the same way (path + branch predicate).

---

## 7. Public flows (booking / intake / commerce)

**Proved:** Public booking requires an explicit **branch** id and branch-effective settings; example gate:

```83:94:system/modules/online-booking/services/PublicBookingService.php
    public function requireBranchPublicBookability(int $branchId, string $endpoint = 'unknown'): array
    {
        if ($this->validateBranch($branchId) === null) {
            ...
            return ['ok' => false, 'error' => 'Branch not found or inactive.'];
        }
        $ob = $this->settings->getOnlineBookingSettings($branchId);
        if (!$ob['enabled']) {
            ...
            return ['ok' => false, 'error' => 'Online booking is not enabled for this branch.'];
        }
        return ['ok' => true, 'ob' => $ob];
    }
```

**Conclusion (proved):** Public flows are **branch-scoped** (caller supplies `branch_id` / token resolves to entities with branch), **not** multi-organization. They do **not** prove tenant isolation across separate businesses sharing one app instance.

---

## 8. User lifecycle (onboarding / offboarding / access removal)

**Proved:** Session user row is loaded with `branch_id`; no organization id:

```79:83:system/core/auth/SessionAuth.php
            return $this->db->fetchOne(
                'SELECT id, email, name, branch_id, password_changed_at, created_at FROM users WHERE id = ? AND deleted_at IS NULL',
                [$id]
            );
```

**Proved:** Login respects `deleted_at IS NULL` (`AuthService` / `SessionAuth`).

**Proved (limited surface):** No `modules/**` `INSERT INTO users` for admin onboarding was found; `create_user.php` script exists for ops. User updates in modules are mainly password reset (`PasswordResetService`).

**Conclusion:** Lifecycle is **user + optional branch assignment + roles**, **not** organization-scoped provisioning/deprovisioning.

---

## 9. Assumptions blocking future SaaS-style isolation

| Blocker | Evidence |
|---------|----------|
| **No organization / tenant key** | Branches + users only; roadmap text in `BOOKER-PARITY-MASTER-ROADMAP.md` acknowledges need for business layer — not implemented. |
| **Single DB, shared sequences** | `invoice_number_sequences` global key. |
| **Global RBAC tables** | `roles` / `permissions` / `user_roles` without branch or org. |
| **Nullable global entity rows** | `BranchContext::assertBranchMatch` **allows** `entityBranchId === null` — global records editable from any branch context (by design today). |
| **Filesystem storage** | `storage/documents/YYYY/MM` not partitioned by tenant. |
| **Settings UI** | `all()` without branch in settings index for miscellaneous keys (section 5). |
| **find-by-id repositories** | Many `find()` methods without branch predicate; reliance on upper-layer checks. |

---

## 10. Safest next implementation sequence (post-audit, recommendation only)

1. **Frozen architecture decision:** target model (single DB multi-tenant vs DB-per-tenant) — not implemented in this wave.  
2. **Schema inventory:** add explicit `organization_id` (or equivalent) to `branches` first, then propagate to enforcement choke points (`BranchContextMiddleware`, `BranchContext`, public entrypoints).  
3. **Harden find-by-id:** mandatory scope check helper or repository pattern requiring branch/org predicate for mutating paths.  
4. **Partition high-risk globals:** `invoice_number_sequences`, RBAC, settings `all()` UI, document storage paths.  
5. **Only then** subscription/package/platform features.

---

## Evidence index (primary files)

| File | Role |
|------|------|
| `system/core/middleware/BranchContextMiddleware.php` | Branch resolution order |
| `system/core/Branch/BranchContext.php` | assert/enforce semantics; global row allowance |
| `system/core/auth/SessionAuth.php` | User shape; `branch_id` only |
| `system/core/middleware/AuthMiddleware.php` | Security settings branch vs 0 |
| `system/core/app/SettingsService.php` | Settings merge; persistence |
| `system/modules/settings/controllers/SettingsController.php` | Save/read branch context; `all()` issue |
| `system/modules/online-booking/services/PublicBookingService.php` | Public branch gating |
| `system/modules/documents/services/DocumentService.php` | Disk path; branch on row |
| `system/data/migrations/001_create_branches_table.sql` | Top-level branch entity |
| `system/data/migrations/005_create_users_table.sql` | User `branch_id` nullable |
| `system/data/migrations/043_payment_refunds_and_invoice_sequence.sql` | Global invoice sequence |
| `system/data/migrations/006_create_user_roles_table.sql` | Global RBAC link |

---

## Supporting script

See `system/scripts/read-only/audit_migration_branch_columns.php` — scans `system/data/migrations/*.sql` for `CREATE TABLE` blocks and whether `branch_id` appears in the block (heuristic for auditors).
