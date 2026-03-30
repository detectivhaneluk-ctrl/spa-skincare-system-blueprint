# Maintainer runtime truth (canonical)

This document is the **authoritative maintainer index** for what in this repository describes **current** application behavior versus **historical** material.

## Live / canonical (trust these first)

| Surface | Role |
|--------|------|
| `system/README.md` | Layout of the runnable app (`system/` as root). |
| `system/core/app/autoload.php` | `Modules\…` PSR-like mapping: `Modules\{Module}\{subdir…}\{Class}` → `modules/{kebab-module}/{subdir…}/{Class}.php` (tries lowercase dirs, then title-case segments, then original case). |
| `system/routes/web/*.php` | Registered HTTP routes (if it is not wired here, it is not a live web entry). Anonymous/public POST CSRF exemption: `['csrf_exempt' => true]` in `Router::post` options — not a separate middleware path list. |
| `system/modules/*/README.md` | Module-scoped notes aligned to the PHP tree. |
| `system/docs/*-OPS.md` | Task-specific operational narratives (**code wins** on conflict; fix the doc when behavior intentionally changes). |
| `system/scripts/read-only/*.php` | **Living** read-only checks: they must track the codebase. If they fail after a deliberate change, update the script in the same change—do not treat a failing proof as automatically wrong. |

### Bootstrap chain

- **`system/bootstrap.php`** — core-only container: env, DB, auth stack, branch/org **context objects**, tenant guards, etc. Does **not** register `OrganizationContextResolver` or `StaffMultiOrgOrganizationResolutionGate` (those need module services).
- **`system/modules/bootstrap.php`** — load after core; registers module singletons, then **`OrganizationContextResolver`**, then **`StaffMultiOrgOrganizationResolutionGate`**.
- **Full HTTP app** — `public/index.php` requires both files in that order. Scripts that need resolver, gate, or most `Modules\*` services must also `require` `modules/bootstrap.php`.
- **Dispatcher routed resolution (A-002)** — `Core\Router\Dispatcher` resolves **string** pipeline middleware and `[Controller::class, 'method']` handlers only through the container (`has` + `get`). There is no silent `new ClassName()` for those routed classes. Bind global/common middleware and `RootController` in `system/bootstrap.php`; bind module controllers in `system/modules/bootstrap/register_*.php`. `PermissionMiddleware::for()` remains a pre-built instance in the pipeline, not a class string.
- **Branch context vs global entity (A-006)** — `Core\Branch\BranchContext` has `assertBranchMatchStrict` (branch-scoped record required; null/zero entity branch denied under context) and `assertBranchMatchOrGlobalEntity` (explicit “global row allowed”). The removed `assertBranchMatch` name must not return; call sites choose the contract. `OrganizationScopedBranchAssert` remains org-ownership of a concrete `branches.id`, separate from those checks.
- **`base_path()` / `SYSTEM_PATH` (M-007)** — `system/core/app/helpers.php` requires a defined non-empty `SYSTEM_PATH` (normally from `system/bootstrap.php`). There is no silent fallback to `system/core/`.
- **Special offers (H-006)** — `marketing_special_offers` rows are admin catalog only; activation toward live pricing is blocked in repository + service until a consumer path exists. See `verify_special_offers_admin_only_h006_01.php`.
- **Payment settings patch (M-004)** — `SettingsService::patchPaymentSettings` requires `PaymentMethodService` in the container to validate `default_method_code` (fail closed if missing).
- **Typed access denial (H-003)** — Use `Core\Errors\AccessDeniedException` for authorization / tenant-branch denials so `HttpErrorHandler` returns 403; do not rely on `DomainException` message allowlists. Appointments controllers/services map principal branch denials accordingly.

## Archival / not authoritative

| Surface | Role |
|--------|------|
| `archive/blueprint-reference/` | Early blueprint and vision docs (may predate or diverge from the current module layout). **Not** a spec for day-to-day implementation. |
| `archive/cursor-context/` | Exported Cursor/manifest-style snapshots. Convenience only; not release-verified. |
| Repository root `README.md` | Package introduction (Armenian blueprint text + maintainer pointers). Detailed runtime truth is under `system/`. |

## Proof scripts and audit risk

Scripts under `system/scripts/read-only/` that search for **UI strings or paths** will go stale when copy or structure changes. Prefer updating them when you change the UI they guard, or demote them with an explicit **DEPRECATED** header in-file if the task is retired (do not leave silent false confidence).
