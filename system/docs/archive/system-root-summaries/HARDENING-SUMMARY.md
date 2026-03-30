# Phase 1 Hardening Summary

## 1. Changed Files List

| File | Change |
|------|--------|
| `config/session.php` | **New** — Session cookie config (name, lifetime, secure, httponly, samesite) |
| `config/app.php` | Unchanged |
| `core/auth/SessionAuth.php` | Regenerate session on login; secure logout; session cookie config |
| `core/auth/AuthService.php` | LoginThrottleService integration; `remainingLockoutSeconds()` |
| `core/auth/LoginThrottleService.php` | **New** — Throttling (5 attempts, 15 min lockout) |
| `core/audit/AuditService.php` | New schema: actor_user_id, action, target_type, target_id, metadata_json |
| `core/app/SettingsService.php` | type, group, branch_id; typed getters (getInt, getBool, getJson) |
| `core/app/Response.php` | **New** — jsonSuccess, jsonError, codeToHttp |
| `core/errors/HttpErrorHandler.php` | Standard error codes and messages; uses Response |
| `core/router/RootController.php` | **New** — Guest→/login, auth→/dashboard |
| `core/app/autoload.php` | moduleFolder() for GiftcardsPackages→giftcards-packages, etc. |
| `modules/auth/controllers/LoginController.php` | Throttle check; AuditService new signature; redirect to /dashboard |
| `modules/dashboard/controllers/DashboardController.php` | **New** — Placeholder page |
| `routes/web.php` | Root uses RootController; /dashboard route |
| `shared/layout/base.php` | Nav: Dashboard link |
| `bootstrap.php` | LoginThrottleService registered |
| `.env.example` | SESSION_* vars |
| `scripts/migrate.php` | Run multi-statement migrations |
| `docs/CONVENTIONS.md` | **New** — Permissions, branch rules, API format |

---

## 2. Migration Changes

| # | Migration | Purpose |
|---|-----------|---------|
| 010 | `010_create_login_attempts_table.sql` | Login throttling storage |
| 011 | `011_alter_audit_logs_schema.sql` | actor_user_id, target_type, target_id, metadata_json; drop old cols |
| 012 | `012_alter_settings_schema.sql` | type, group, branch_id, FK to branches |
| 013 | `013_database_integrity.sql` | users FKs (created_by, updated_by); audit FKs; branches uk_code |

---

## 3. Hardening Summary

| Area | Before | After |
|------|--------|-------|
| **Session** | Basic start/destroy | Secure cookie params; regenerate on login; full destroy on logout |
| **Login** | No throttling | 5 attempts / 15 min lockout; centralized audit |
| **Audit** | entity_type, entity_id, user_id, old/new | actor_user_id, target_type, target_id, metadata_json |
| **Settings** | key, value | key, value, type, group, branch_id; typed getters |
| **Permissions** | Ad-hoc | Documented module.action pattern |
| **Response** | Inline JSON | Response::jsonSuccess, Response::jsonError |
| **Branch** | Placeholder | Documented resolution and scope rules |
| **Autoload** | Auth, Settings only | GiftcardsPackages, ServicesResources, OnlineBooking mapped |
| **Root** | Always /settings | Guest→/login, auth→/dashboard |

---

## 4. Documented Conventions

See `docs/CONVENTIONS.md`:

- **§1 Permission Naming** — module.action (settings.view, clients.create, etc.)
- **§2 Branch Isolation** — Resolution, branch-scoped vs global, owner vs admin scope
- **§3 API Response** — JSON success/error structure, error codes
- **§4 HTML Errors** — 403, 404, 500 pages

---

## 5. Phase 2 — Final Backend Hardening Pass

### 5.1 Changes in this pass

| Area | Change |
|------|--------|
| **Inventory write paths** | `StockMovementService::createAndApplyStock()` and `InventoryCountService::create()` enforce branch via `BranchContext`: when user is branch-scoped, `branchId` is forced from context and `assertBranchMatch($branchId)` is called before applying. Product branch must still match movement/count branch. |
| **Client custom field definitions** | `ClientService::createCustomFieldDefinition()` uses `enforceBranchOnCreate($payload)`. `ClientService::updateCustomFieldDefinition()` calls `assertBranchMatch($existing['branch_id'])` after find. No delete flow for definitions; branch enforcement on create/update only. |
| **Controller single-record branch guards** | Controllers that load a single record by id now call a private `ensureBranchAccess($entity)` after find (and 404 check): uses `BranchContext::assertBranchMatch($entity['branch_id'])`; on `DomainException` returns 403 via `HttpErrorHandler::handle(403)`. Applied in: **Clients** (show, edit, update, destroy, registrationsShow); **Appointments** (show, edit, update, consumePackage); **Sales/Invoices** (show, edit, update, cancel, redeemGiftCard); **Inventory** (ProductController: show, edit, update, destroy; SupplierController: show, edit, update, destroy). |
| **Documents routes** | Permission middleware added: `PermissionMiddleware::for('documents.view')` on GET list definitions, list client consents, check client consents; `PermissionMiddleware::for('documents.edit')` on POST create definition, sign client consent. **Permission codes `documents.view` and `documents.edit` must exist in `permissions` table and be assigned to roles; no seed/migration was added in this pass — document and assign manually if needed.** |

### 5.2 Modules / routes covered

- **Inventory:** stock movements, inventory counts (service-level branch asserts); products and suppliers (controller show/edit/update/destroy branch guards).
- **Clients:** custom field definitions (create/update branch enforcement); client and registration single-record actions (controller guards).
- **Appointments:** single-record show, edit, update, consumePackage (controller guards).
- **Sales:** invoices show, edit, update, cancel, redeemGiftCard (controller guards).
- **Documents:** all five document/consent routes protected with `documents.view` or `documents.edit` middleware.

### 5.3 Intentionally postponed

| Item | Reason |
|------|--------|
| Permission seed for `documents.view` / `documents.edit` | Not safe to automate without knowing existing role/permission layout; must be added to DB and assigned to roles manually. |
| Delete flow for client custom field definitions | No delete endpoint implemented; nothing to harden. |
| Staff / Services–Resources / other single-record controllers | Can be added in a follow-up using the same `ensureBranchAccess` pattern where entities carry `branch_id`. |
| Branch context middleware (resolution from session/header) | Already in place from branch-context foundation; this pass only added controller/service-level asserts. |

---

## 6. Remaining Placeholders (pre-Phase 2, still valid)

| Item | Status |
|------|--------|
| Password reset flow | Placeholder hint only |
| 2FA | Not implemented |
| Dashboard | Placeholder page; no KPIs |
| Settings UI for type/group | Uses string type only |
| Composite (key, branch_id) for settings | Single key PK; branch scoping deferred |
