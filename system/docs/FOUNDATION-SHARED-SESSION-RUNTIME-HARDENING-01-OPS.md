# FOUNDATION-SHARED-SESSION-RUNTIME-HARDENING-01 — Session backend and lock behavior (ops)

## Purpose

Document environment variables and operational checks for shared session storage (Redis) and optional early session write release on safe GET routes.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `SESSION_DRIVER` | `files` (default) or `redis`. |
| `SESSION_REDIS_URL` | Optional. Overrides `REDIS_URL` for **session** storage only. Same format as `REDIS_URL` (e.g. `redis://:pass@host:6379/0`). Use a logical DB index (path) to isolate keys from app cache if desired. |
| `REDIS_URL` | Used for sessions when `SESSION_REDIS_URL` is empty and `SESSION_DRIVER=redis`. |
| `SESSION_REDIS_PREFIX` | Prefix for phpredis session keys (default `spa:sess:`). |
| `SESSION_FILES_PATH` | Optional override for file session directory; default `system/storage/sessions` when empty. |
| `SESSION_EARLY_RELEASE_ENABLED` | Default `true`. When `false`, {@see \Core\Middleware\SessionEarlyReleaseMiddleware} becomes a no-op even on opted-in routes. |

Cookie settings remain in `system/config/session.php` (`SESSION_COOKIE`, `SESSION_LIFETIME`, `SESSION_DOMAIN`, `SESSION_SECURE`, etc.). Security flags are unchanged by this task.

## Production rules

- `APP_ENV=production` and `SESSION_DRIVER=redis` **require** PHP **ext-redis** and a non-empty Redis URL (`SESSION_REDIS_URL` or `REDIS_URL`). Otherwise the application throws on session bootstrap (fail-fast).
- Non-production: missing extension or URL falls back to **files** with a `error_log` line (`spa_session_*_fallback_v1`).

## Early session release

- **Mechanism:** {@see \Core\Middleware\SessionEarlyReleaseMiddleware} calls {@see session_write_close()} after upstream middleware, on **GET/HEAD/OPTIONS** only, when the route sets `session_early_release` => true and registers this middleware **after** {@see \Core\Middleware\AuthMiddleware}.
- **Reference route:** `GET /clients/merge/job-status` — JSON-only poll endpoint; no CSRF token minting or flash in the handler.
- **Unsafe patterns:** Do not add this middleware to routes whose controller or layout calls `csrfToken()`, `flash()`, login/logout, or support-entry flows after the middleware runs.

## Read-only verifiers

From repository root:

```bash
php system/scripts/read-only/verify_session_runtime_configuration_readonly_01.php
php system/scripts/read-only/verify_session_ini_after_bootstrap_readonly_01.php
```

The first script exits **1** in production when `SESSION_DRIVER=redis` but Redis is unreachable, misconfigured, or ext-redis is missing. The second prints JSON with masked `save_path` after a real session start (CLI side effect: may create a session file or Redis key).

## Implementation references

- `system/core/Runtime/Session/SessionBackendConfigurator.php`
- `system/core/auth/SessionAuth.php`
- `system/config/session.php`
- `system/core/middleware/SessionEarlyReleaseMiddleware.php`
