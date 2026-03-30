# FOUNDATION-OBSERVABILITY-AND-ALERTING-01 — Observability truth audit + Tier A wave

**Method:** Code-truth review of signals vs blind spots. **Tier A** = consolidated honest health with exit codes + stable reason codes. **Tier B** = deeper per-path latency, tenant gate inside health cron, external sinks. **Tier C** = doc-only runbooks.

---

## 1. Session / backend readiness

| Signal today | Blind spot | Healthy / degraded / failed | Tier |
|--------------|------------|-----------------------------|------|
| `verify_session_runtime_configuration_readonly_01.php` (driver, ext-redis, ping) | Not rolled into one ops exit surface | **Failed** = production + redis driver + misconfig/unreachable; **Degraded** = same in non-prod | **A — closed:** `BackendHealthCollector::probeSession` |

**Target signal:** `layer=session` + `SESSION_REDIS_*` reason codes in `report_backend_health_critical_readonly_01.php`.

**Proof:** Run consolidated report; compare with standalone session verifier.

---

## 2. Jobs / `runtime_execution_registry`

| Signal today | Blind spot | Alert-worthy | Tier |
|--------------|------------|--------------|------|
| `verify_runtime_jobs_execution_registry_readonly_01.php` (table exists, row dump) | Stale exclusive slot / recent failures not failing any exit | Table missing = broken deploy; stale active = recovery pending; recent `last_failure_at` = operator attention | **A — closed:** probe uses `fetchAllForReadOnlyReport` + `staleMinutesFor` |

**Reason codes:** `REGISTRY_TABLE_MISSING`, `REGISTRY_EXCLUSIVE_SLOT_STALE`, `REGISTRY_RECENT_FAILURE`.

---

## 3. Image pipeline backlog / heartbeat

| Signal today | Blind spot | Tier |
|--------------|------------|------|
| `report_image_pipeline_runtime_health_readonly_01.php` (counts, `health_signal=`) | **Always exit 0** when tables exist — not cron-alertable | **A — closed:** same predicates in `BackendHealthCollector::probeImagePipeline`; rollup sets **degraded** + exit 1 |

---

## 4. Shared cache / Redis

| Signal today | Blind spot | Tier |
|--------------|------------|------|
| `SharedCacheMetrics` + `report_shared_cache_operational_readonly_01.php` | Production with `REDIS_URL` set but noop/connect-fail was not a **failed** health boundary | **A — closed:** production + URL + not `redis_effective` → **failed** exit 2; non-prod → **degraded** |

**Reason codes:** `SHARED_CACHE_REDIS_CONFIGURED_NOT_EFFECTIVE`, `SHARED_CACHE_PRODUCTION_REDIS_REQUIRED`.

---

## 5. Storage provider

| Signal today | Blind spot | Tier |
|--------------|------------|------|
| `verify_foundation_storage_abstraction_wave_01_readonly_01.php` (static) | Runtime misconfig (`storage.driver` non-local, bad `STORAGE_LOCAL_SYSTEM_PATH`) only fails at first container resolution | **A — closed:** `probeStorage` catches init/diagnostic via `driverName()` (container must resolve — bootstrap failure still exit 2 in report script) |

---

## 6. Tenant isolation / release gates

| Signal today | Blind spot | Tier |
|--------------|------------|------|
| `run_mandatory_tenant_isolation_proof_release_gate_01.php` | Not executed inside health report (would couple slow static verifiers + policy) | **B** — keep manual/CI; document in OPS |

---

## 7. Slow / failing request paths

| Signal today | Blind spot | Tier |
|--------------|------------|------|
| `StructuredLogger` / `slog()` hotspots report | No SLO histogram in-repo | **B/C** |

---

## Minimum contract delivered (wave 01)

- Vocabulary: `BackendHealthStatus` (`healthy` / `degraded` / `failed`), exit 0/1/2.
- Stable codes: `BackendHealthReasonCodes`.
- One report: `report_backend_health_critical_readonly_01.php` (`--json`, `--quiet`, `--no-structured-log`).
- Optional single structured line on issue: `observability.backend_health.issue_v1` with **compact** layer payload (no prose spam).

**Tier B backlog:** embed tenant gate optional flag; RED metrics export; Dispatcher/request timing.
