# Staff org resolution gate — file / function impact matrix (FOUNDATION-24)

**Companion:** `STAFF-HTTP-ORG-RESOLUTION-GATE-IMPLEMENTATION-BOUNDARY-TRUTH-CUT-FOUNDATION-24-OPS.md`

---

## 1) Future implementation — primary touch set (minimal)

| Path | Symbol | Change kind (future) |
|------|--------|----------------------|
| `system/core/middleware/AuthMiddleware.php` | `handle` | **Optional** — call gate before `$next()` (pattern P1) |
| `system/core/router/Dispatcher.php` | `dispatch` / pipeline build | **Optional** — insert middleware after `AuthMiddleware` (pattern P2) |
| `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php` | **new** `enforce()` or `handle` | **New file** — policy + HTTP response |
| `system/bootstrap.php` | container `singleton` | Register gate (and optionally `AuthMiddleware`) |

**Optional read-only helper (avoid duplicate SQL):**

| Path | Symbol | Change kind (future) |
|------|--------|----------------------|
| `system/core/organization/OrganizationContextResolver.php` | new public `countActiveOrganizations(): int` | **Expose** existing query logic — **must not** change `resolveForHttpRequest` |

---

## 2) Proof — `AuthMiddleware` instantiation

`Dispatcher::runPipeline` resolves middleware: container `has` → `get`, else `new $m()` (```59:61:system/core/router/Dispatcher.php```). **`AuthMiddleware` is not registered** in `system/bootstrap.php` (grep: no hits). Therefore **today** `AuthMiddleware` is **`new AuthMiddleware()`** with **no constructor dependencies**. Any **constructor DI** for P1 requires **registering** `AuthMiddleware` in the container **or** using **`Application::container()->get(Gate::class)`** inside `handle()` without constructor changes.

---

## 3) `AuthMiddleware` route references (inventory scale)

Ripgrep **`AuthMiddleware::class`** under `system/` (PHP): **17** files hit (central registrars + module routes). **Not** an exhaustive route count; indicates **wide** behavioral blast radius **without** per-route file edits if gate is centralized post-auth.

**Guest-only counterexample:** `/login` uses `GuestMiddleware` only (`register_core_dashboard_auth_public.php`).

---

## 4) Middleware order (evidence)

Global: `Csrf` → `ErrorHandler` → `BranchContext` → `OrganizationContext` → **route**: typically `AuthMiddleware` → `PermissionMiddleware` → …

Gate **after** `AuthMiddleware::handle` success **and before** `$next()` **equals** before `PermissionMiddleware` when P1 is used.

---

## 5) Non-target files (confirm no edit in minimal wave)

- `OrganizationContextMiddleware.php`
- `BranchContextMiddleware.php`
- `OrganizationContext.php` (except **reading** `getCurrentOrganizationId()` / `getResolutionMode()` from gate)
- `modules/**/repositories/*.php` (all)
- `modules/**/controllers/*.php` (minimal wave)
