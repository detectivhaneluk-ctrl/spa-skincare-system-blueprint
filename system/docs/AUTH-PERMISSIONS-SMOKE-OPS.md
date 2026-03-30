# Auth / session / permissions — HTTP smoke foundation

**Wave:** `MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-03` — **AUTH-PERMISSIONS-SMOKE-TEST-FOUNDATION**  
**Mode:** Test-only HTTP client; **no** production code path changes; **no** DB writes from the script.  
**Date:** 2026-03-22

---

## 1. Purpose

Provide a **minimal, repo-native** regression net for:

- Public vs authenticated boundaries  
- Session cookie behavior for anonymous callers  
- JSON `401` / `403` shapes used by `AuthMiddleware` and `PermissionMiddleware`  
- Optional: full login + permitted JSON route + guest-route redirect when already logged in  
- Optional: second account **without** `notifications.view` to assert `403 FORBIDDEN`

Future bootstrap/route modularization can re-run the same script without introducing PHPUnit or new Composer dependencies.

---

## 2. Prerequisites

| Requirement | Notes |
|---------------|--------|
| **Running app** | Web server must serve `system/public/index.php` for the chosen base URL. |
| **PHP curl extension** | Required (`extension_loaded('curl')`). |
| **`SMOKE_BASE_URL`** | Origin only, no trailing slash (e.g. `http://127.0.0.1:8080`, `https://spa.test`). |
| **`SMOKE_STAFF_EMAIL` / `SMOKE_STAFF_PASSWORD`** | Optional; if both set, **Tier B** runs (real login via CSRF + session cookie jar). |
| **`SMOKE_DENIED_EMAIL` / `SMOKE_DENIED_PASSWORD`** | Optional; if both set, **Tier C** runs — account must **lack** `notifications.view` or the check will fail. |

**Do not** commit credentials. Pass secrets via environment or your CI secret store.

Optional environment:

| Variable | Purpose |
|----------|---------|
| `SMOKE_SESSION_COOKIE` | Session cookie name if not default `spa_session` (must match `SESSION_COOKIE` / `config/session.php`). |
| `SMOKE_CSRF_FIELD` | Defaults to `csrf_token` (matches `config/app.php`). |
| `SMOKE_SKIP_TLS_VERIFY` | `true` for local HTTPS with self-signed certs only. |

---

## 3. Safety / constraints

- The script issues **HTTP GET/POST only**; it does not bootstrap the application or run SQL.  
- **No** domain data mutations: login may record normal audit/login rows as in any browser session — operators should use a **non-production** instance when possible.  
- Failed logins increment lockout counters like normal — use a dedicated smoke user and avoid hammering.  
- Public **`GET /api/public/booking/slots`** is subject to **abuse-guard / rate limits** (`429` is treated as non-failure as long as the response is **not** an auth redirect/`401`).

---

## 4. Scenarios implemented

| ID | Tier | Condition | Expectation |
|----|------|-----------|-------------|
| `public_guest_login_page` | A | — | `GET /login` → **200**, body contains “Login”. |
| `public_booking_slots_not_auth_gated` | A | — | `GET /api/public/booking/slots` → **not** `401` / `302` to login (e.g. `422` / `200` / `429`). |
| `protected_notifications_json_unauthenticated` | A | — | `GET /notifications` + `Accept: application/json` → **401**, body contains `UNAUTHORIZED`. |
| `protected_dashboard_redirect_unauthenticated` | A | — | `GET /dashboard` → **302** with `Location` containing `/login`. |
| `invalid_session_cookie_unauthenticated` | A | — | Same as notifications check with bogus session cookie → **401**. |
| `auth_staff_login_post` | B | Staff creds | `POST /login` with CSRF from `GET /login` → **302**. |
| `authenticated_notifications_json_200` | B | Staff creds | `GET /notifications` + `Accept: application/json` → **200** JSON with `notifications` key (requires `notifications.view`). |
| `guest_login_redirect_when_authenticated` | B | Staff creds | `GET /login` while session active → **302** away from `/login` (`GuestMiddleware`). |
| `permission_denied_notifications_403` | C | Denied creds | `GET /notifications` + JSON → **403**, body contains `FORBIDDEN`. |

**Tier A** always runs. **Tier B** and **C** are skipped with a `SKIP` line if credentials are not provided.

---

## 5. Command

From the repository root (or any directory):

```bash
php system/scripts/smoke_auth_permissions.php
```

Example (PowerShell):

```powershell
$env:SMOKE_BASE_URL = "http://127.0.0.1:8080"
$env:SMOKE_STAFF_EMAIL = "you@example.com"
$env:SMOKE_STAFF_PASSWORD = "use-ci-secret"
php system/scripts/smoke_auth_permissions.php
```

Exit codes: **0** = all executed checks passed; **1** = at least one failure; **2** = misconfiguration (missing `SMOKE_BASE_URL` or no curl).

---

## 6. Limitations (honest)

- **Not** a replacement for unit/integration coverage of `AuthService`, throttling, or password-expiry logic.  
- **403** coverage **requires** a real user without `notifications.view` — no fabricated fixture in-repo.  
- **Tier B** assumes the staff user can open **`GET /notifications`** (`notifications.view`). If your smoke user is permission-trimmed, grant that permission or change the script’s permitted endpoint in a future wave.  
- **HTML** error pages for `403` are not asserted here (JSON `Accept` only for middleware error paths).  
- Does not validate **CSRF** on logout or other POSTs.

---

## 7. Canonical artifact

- Runner: `system/scripts/smoke_auth_permissions.php`  
- This doc: `system/docs/AUTH-PERMISSIONS-SMOKE-OPS.md`

---

*Stop after this wave — await review before expanding smoke coverage (settings, invoices, booking concurrency, etc.).*
