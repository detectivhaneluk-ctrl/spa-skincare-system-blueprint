# FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01 — Jobs / scheduler execution truth (ops)

## Purpose

Give operators **honest** visibility into scheduled PHP scripts and the Node image worker: last start/finish/success/failure, exclusive-run conflicts, stale media job rows, and worker heartbeats. This layer does **not** replace a process supervisor (systemd, pm2, K8s); it records what ran and surfaces backlog risk.

## Database

- **Table:** `runtime_execution_registry` (migration `121_runtime_execution_registry_foundation.sql`)
- **Keys:**
  - `php:outbound_notifications_dispatch` — parallel-safe timestamps (overlapping dispatchers OK; row claims use `SKIP LOCKED`)
  - `php:memberships_cron` — exclusive run + stale recovery
  - `php:marketing_automations:{automation_key}` — exclusive per key
  - `worker:image_pipeline` — Node worker heartbeat + graceful shutdown markers

## Environment (optional)

| Variable | Effect |
|----------|--------|
| `RUNTIME_STALE_OUTBOUND_MINUTES` | Stale threshold for exclusive logic (unused for outbound parallel mode); default 45 in config. |
| `RUNTIME_STALE_MEMBERSHIPS_MINUTES` | Exclusive stale recovery for memberships; default 180. |
| `RUNTIME_STALE_MARKETING_AUTOMATIONS_MINUTES` | Exclusive stale recovery for marketing automations; default 120. |
| `RUNTIME_STALE_IMAGE_WORKER_MINUTES` | Documented alignment with worker heartbeat checks; default 15 in `runtime_jobs.php`. |
| `RUNTIME_EXECUTION_DEFAULT_STALE_MINUTES` | Fallback stale window; default 120. |
| `RUNTIME_IMAGE_BACKLOG_HEARTBEAT_WARN_MINUTES` | Verifier: pending jobs + heartbeat older than this → `backlog_no_recent_worker_heartbeat`; default 20. |
| `IMAGE_JOB_STALE_LOCK_MINUTES` | Matches worker/PHP semantics for **media_jobs** lock age; default 30. |

Config file: `system/config/runtime_jobs.php`.

## Script behavior

### `php system/scripts/outbound_notifications_dispatch.php`

- **Flock:** `storage/locks/outbound_notifications_dispatch.lock` (non-blocking); exit **11** if held.
- **Registry:** parallel batch start/end (no exclusive mutex; safe with multiple hosts).
- **Business:** unchanged — `OutboundNotificationDispatchService::runBatch()` + stale claim reclaim inside service.

### `php system/scripts/memberships_cron.php`

- **Flock:** `storage/locks/memberships_cron.lock` (existing); exit **11** if held.
- **Registry:** exclusive run; second host/process gets `RuntimeExecutionConflictException` until stale window elapses, then previous slot is cleared with an honest `last_error_summary` note and the new run proceeds.
- **Heartbeats:** `heartbeatExclusive()` between major steps.

### `php system/scripts/marketing_automations_execute.php`

- **Flock:** `storage/locks/marketing_automations_{sanitized_key}.lock`; exit **11** if held.
- **Registry:** exclusive per automation key (same stale rules as `php:marketing_automations` in config).

### Node `workers/image-pipeline/src/worker.mjs`

- **Heartbeat:** each outer-loop iteration updates `worker:image_pipeline` via `executionRegistry.mjs`.
- **Shutdown:** `finalizeWorkerSession` runs on normal exit, `WORKER_ONLY_RECLAIM=1`, or fatal error in the main loop. **SIGKILL** does not run finalize — expect missing heartbeat while backlog exists (verifier signal).
- **Concurrency:** multiple Node processes remain **job-safe** (`FOR UPDATE SKIP LOCKED`); registry heartbeat is last-writer-wins (honest limitation).

## Read-only verifiers

From repository root:

```bash
php system/scripts/read-only/verify_runtime_jobs_execution_registry_readonly_01.php
php system/scripts/read-only/report_image_pipeline_runtime_health_readonly_01.php
```

## Code references

- `system/core/Runtime/Jobs/RuntimeExecutionRegistry.php`
- `system/core/Runtime/Jobs/RuntimeExecutionKeys.php`
- `workers/image-pipeline/src/executionRegistry.mjs`
- `workers/image-pipeline/src/worker.mjs`
