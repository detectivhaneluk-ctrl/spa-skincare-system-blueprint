# WAVE-01 Production Runtime Foundation — Ops Runbook

**Wave:** WAVE-01  
**Status:** DELIVERED (2026-03-31)  
**Charter:** `system/docs/SCALE-WAVE-EXECUTION-CHARTER-01.md`  
**Proof script:** `system/scripts/read-only/verify_wave01_production_runtime_foundation_01.php`

---

## What was delivered

| ID | Deliverable | File(s) |
|----|-------------|---------|
| W1-A | Redis mandatory in production; `NoopSharedCache` blocked as production path | `ProductionRuntimeGuard`, `bootstrap.php` |
| W1-B | Redis session handler — multi-server session readiness | `RedisSessionHandler`, `bootstrap.php` |
| W1-C | `DistributedLockInterface` + Redis/MySQL implementations; `WaitlistService` `GET_LOCK` replaced | `DistributedLockInterface`, `RedisDistributedLock`, `MysqlDistributedLock`, `WaitlistService` |
| W1-D | `ProductionRuntimeGuard` — formalized startup failure on missing/broken Redis config | `system/core/Runtime/Guard/ProductionRuntimeGuard.php` |
| W1-E | This ops runbook + cache key conventions | This file |

---

## Redis configuration

### Required environment variables

| Variable | Required in production | Default | Notes |
|---|---|---|---|
| `REDIS_URL` | **YES — MANDATORY in production** | *(none)* | Format: `redis://[:password@]host[:port][/db]` |
| `REDIS_KEY_PREFIX` | No | `spa` | Prefixes all keys; isolates tenant from other apps on shared Redis |

### PHP requirement

`ext-redis` must be installed and loaded. Verify:

```bash
php -m | grep redis
# Must show: redis
```

### Minimum Redis version

Redis 3.0.6+ for atomic `SET NX PX` (always available in Redis 4+ / 5+ / 6+).  
Redis 6.0+ recommended (ACL support, better observability).

---

## Cache key conventions

All keys use the `{REDIS_KEY_PREFIX}:` prefix (default `spa`). Operators MUST set `REDIS_KEY_PREFIX` to a unique value when multiple application instances share one Redis server.

| Key pattern | TTL | Owner | Notes |
|---|---|---|---|
| `{prefix}:sess:{session_id}` | `SESSION_LIFETIME * 60` seconds | `RedisSessionHandler` | PHP session data; sliding TTL reset on each write |
| `{prefix}:lock:{key}` | 30–120 seconds (per-acquire call) | `RedisDistributedLock` | Distributed advisory locks; auto-expire prevents deadlock |
| `{prefix}:settings:{org_id}:{branch_id}` | varies | `SettingsService` | Cached settings resolved per branch |
| `{prefix}:rate:{type}:{identifier}` | varies | Rate limiter | Sliding-window public abuse counters |

### Invalidation contract

- **Settings cache**: invalidated immediately on `SettingsService::set()`. TTL is a safety net only.
- **Session cache**: TTL extended on every `write()`. Destroyed explicitly on logout.
- **Lock keys**: auto-expire on TTL. Released immediately on `release()`. Stale locks expire within TTL window.
- **Rate limit keys**: TTL-driven; no explicit invalidation.

---

## Production startup sequence

```
1. PHP process starts.
2. bootstrap.php runs.
3. RedisConnectionProvider singleton resolves (connects to REDIS_URL).
4. SharedCacheInterface singleton resolves (marks metrics backend).
5. DistributedLockInterface singleton resolves.
6. ProductionRuntimeGuard::assertRedisOrDie() evaluates:
   - If APP_ENV = production AND backend ≠ redis → HTTP 503 + exit(1).
   - Otherwise → continues.
7. RedisSessionHandler::registerIfAvailable() registers session handler.
8. Application handles the request.
```

### Failure modes

| Failure | Guard behaviour | HTTP response |
|---|---|---|
| `REDIS_URL` not set in production | 503 + JSON error + STDERR + exit | `{"error":"Service unavailable: Redis is required in production.","detail":"REDIS_URL is not configured..."}` |
| `REDIS_URL` set but `ext-redis` missing | 503 + JSON error + STDERR + exit | `{"detail":"PHP ext-redis extension is not loaded..."}` |
| `REDIS_URL` set, extension loaded, connection refused | 503 + JSON error + STDERR + exit | `{"detail":"REDIS_URL is configured but the connection attempt failed..."}` |
| Redis available but key operations fail mid-request | Cache degrades to Noop for that operation; lock returns false (skip, not unguarded) | Request continues with degraded behaviour (not a startup blocker) |

---

## Distributed lock — WaitlistService

`WaitlistService` previously used `SELECT GET_LOCK() / RELEASE_LOCK()` (MySQL advisory locks).

**Problems with MySQL advisory locks at scale:**
- Connection-scoped: lock released if the connection drops (requires transaction management).
- Server-scoped: does not work correctly across multiple MySQL primary servers (HA / Galera / multi-write).
- Held for full sweep duration on the MySQL connection — prevents connection pool reuse.

**New behaviour (WAVE-01):**
- `DistributedLockInterface` injected into `WaitlistService`.
- In production (Redis available): `RedisDistributedLock` uses `SET NX PX` with Lua-script atomic release.
- In non-production dev (no Redis): `MysqlDistributedLock` preserves the old `GET_LOCK` behaviour as a local dev fallback.
- Fail-closed: `tryAcquire()` returns `false` on any error → operation skipped, not proceeded unguarded.
- Lock TTL: expiry sweep = 120 seconds, per-slot auto-offer = 60 seconds.

---

## Redis session handler

Sessions are stored in Redis as `{prefix}:sess:{session_id}` with a TTL equal to `SESSION_LIFETIME * 60` seconds (default from config `session.lifetime`).

**Why this is required at scale:**
- PHP default file sessions are stored on the local filesystem of each server.
- With multiple application servers (horizontal scaling), a session written on Server A is not visible on Server B.
- Redis sessions are visible from all servers sharing the same Redis cluster.

**Session data encoding:** PHP's native serialization format (same as file sessions — no format change).

**Note on `SessionEarlyReleaseMiddleware`:** The existing middleware calls `session_write_close()` early (before controller action completes), limiting the write window. This is compatible with `RedisSessionHandler` — the `write()` call still goes to Redis but is issued early to release the implicit per-session lock.

---

## Readiness verification

Run the proof script after deployment:

```bash
php system/scripts/read-only/verify_wave01_production_runtime_foundation_01.php
```

Expected output: all assertions `PASS`, exit code 0.

---

## Rollback path

If Redis becomes unavailable in production and rolling back is required:

1. Change `APP_ENV` from `production` to a non-production value (e.g. `staging`) to bypass the guard temporarily.
2. Investigate Redis connectivity.
3. Do NOT leave `APP_ENV` as non-production in a real production environment — this disables the guard permanently.

Long-term: add a Redis Sentinel or Redis Cluster for HA to eliminate single-Redis-server failures.
