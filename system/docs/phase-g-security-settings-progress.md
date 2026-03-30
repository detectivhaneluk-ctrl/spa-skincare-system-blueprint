# Phase G — Security Settings foundation (historical baseline)

Backend-first; grouped settings only. No IP allowlist, public key settings, or file encryption in this phase.

**Mixed-schema shims (password column):** see `system/docs/SCHEMA-COMPATIBILITY-SHIMS.md` §1 (central list of `password_changed_at` fallbacks).

---

## Findings (original foundation audit context)

- **Booker reference:** Password expiration is constrained to: 90 days, never. Inactivity timeout is constrained to: 15 min, 30 min, 120 min. IP allowlist, public key settings, and file encryption for card import are treated as separate/backlog; not part of Phase G.
- **Auth/session (historical at original Phase G write time):** this file originally captured a settings-foundation-only stage where inactivity timeout enforcement was not yet wired.
- **Password expiration (current runtime):** When `security.password_expiration` is `90_days` (effective per branch via `SettingsService::getSecuritySettings`), `AuthMiddleware` blocks authenticated requests (except exempt paths) if the password is older than 90 days, using `users.password_changed_at` when present, else `created_at` on the user row (`SessionAuth::user()`). If migration 055 (`users.password_changed_at`) is **not** applied, `SessionAuth::user()` falls back to a SELECT without that column; `AuthService::updatePasswordForCurrentUser()` updates `password_hash` and `password_changed_at` when the column exists, and **only** `password_hash` when it does not (mixed-schema safe). **Login-time** (`AuthService::attempt()`) does not force an immediate “password expired” redirect; enforcement is on subsequent authenticated requests via middleware.
- **Conclusion (updated):** this document remains the record of the **settings foundation** delivery. Runtime inactivity-timeout enforcement exists. Password-expiration policy is **enforced in middleware** when settings and user data allow; installs without `password_changed_at` still load the user and can change password without fatal errors.

---

## Chosen settings keys and allowed values

| Key | Type | Allowed values | Default |
|-----|------|----------------|---------|
| `security.password_expiration` | string | `never` \| `90_days` | `never` |
| `security.inactivity_timeout_minutes` | int | `15` \| `30` \| `120` | `30` |

---

## Where they are used

- **Settings:** Group `security`; `getSecuritySettings(?int $branchId)`, `setSecuritySettings(array, ?int $branchId)`. Validation in setter: only allowed values accepted; `InvalidArgumentException` on invalid input. Persistence in `settings` table; seed 009 for branch_id 0.
- **Settings page:** Security section with two selects: Password expiration (Never / 90 days), Inactivity timeout (15 / 30 / 120 minutes). Grouped keys excluded from “Other” section.
- **Branch-write parity update (backend-only):** `SettingsController` now reuses the existing `online_booking_context_branch_id` context to load/save `security.*` per selected branch (fallback global when context is 0), aligning save-path behavior with branch-aware runtime enforcement in `AuthMiddleware`.
- **Enforcement:** Settings values are stored; **runtime enforcement** for inactivity timeout and password expiration lives in `AuthMiddleware` + `SessionAuth`/`AuthService` (see Findings above), not in this original Phase G slice.

---

## Changed files

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | SECURITY_KEYS; SECURITY_GROUP; PASSWORD_EXPIRATION_VALUES; INACTIVITY_TIMEOUT_VALUES; getSecuritySettings(); setSecuritySettings(). |
| `system/modules/settings/controllers/SettingsController.php` | index: pass $security; isGroupedKey: security.; store: block for security.* with try/catch for InvalidArgumentException. |
| `system/modules/settings/views/index.php` | $security default; Security section (password_expiration select, inactivity_timeout_minutes select); Other excludes security.*. |
| `system/data/seeders/009_seed_phase_g_security_settings.php` | **New.** Security defaults (branch_id 0: never, 30). |
| `system/scripts/seed.php` | require 009. |
| `system/docs/phase-g-security-settings-progress.md` | **New.** This progress document. |

---

## What was enforced in this original Phase G slice

- **Originally in Phase G:** values were stored and exposed on the settings page only.
- **Current runtime status update:** inactivity-timeout and password-expiration **policy** (when `90_days`) are enforced via `AuthMiddleware` with exempt paths for logout and account password change; user load and password change tolerate missing `password_changed_at` column (see Findings).

---

## What was postponed

- **Password expiration — further work:** Core enforcement exists in middleware + optional `password_changed_at` / `created_at` fallback; dedicated login-screen “password expired” flow (vs. block on next request) and richer policy options remain backlog if product requires them.
- **Inactivity timeout enforcement:** this postponement note is historical; inactivity-timeout enforcement now exists in runtime and should no longer be treated as open for Phase G foundation tracking.
- **IP allowlist:** Separate entity/module; backlog.
- **Public key settings:** Later security utility; not in Phase G.
- **File encryption for card import:** Later utility flow; not in Phase G.

---

## Manual QA checklist

1. **Persistence and UI**  
   Run seed (include 009). Open /settings → Security section. Default: Password expiration “Never”, Inactivity timeout “30”. Change to “90 days” and “120”, save, reload → values persist. Change back to “Never” and “15” → persists.

2. **Constrained values**  
   Only the three inactivity options (15, 30, 120) and two password expiration options (Never, 90 days) appear. Saving the form stores only these values.

3. **Invalid values**  
   If the backend receives an invalid value (e.g. via crafted POST), setSecuritySettings() throws InvalidArgumentException; controller catches and flashes error, redirects to /settings; no DB write for invalid security data.

4. **Other section**  
   Security keys do not appear in “Other” and are not overwritten by the generic key/value loop.

5. **Backward compatibility**  
   If 009 has not run, getSecuritySettings() returns password_expiration `never`, inactivity_timeout_minutes `30`. Settings page renders without error.

---

## Phase G acceptance readiness

**Phase G (Security Settings foundation) is acceptance-ready as historical scope.** Grouped security settings are registered, persisted, and retrieved with branch-aware get/set; only constrained values are allowed; defaults are seeded; a minimal Security section exists on the settings page. Since this document was created, inactivity-timeout enforcement and password-expiration middleware enforcement (with mixed-schema-safe user load and password update) exist in runtime. IP allowlist, public key settings, and file encryption remain out of scope and left for backlog/later phases.
