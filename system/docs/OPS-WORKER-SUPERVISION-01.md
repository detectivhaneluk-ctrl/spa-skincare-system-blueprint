# OPS-WORKER-SUPERVISION-01 — Worker Supervision, Liveness, and Queue Failure Policy

**Status:** DELIVERED (2026-04-01)
**Task:** MINIMUM-OPS-RESILIENCE-GATE-01
**Scope:** Async queue workers (`worker_runtime_async_jobs_cli_02.php`) and scheduled
background scripts (`memberships_cron.php`, `run_queue_stale_reclaim_cron.php`,
`waitlist_expire_offers.php`, `marketing_automations_execute.php`).

> **Honesty boundary:** No in-repo process supervisor (systemd unit, Supervisor conf)
> is deployed or tracked by this codebase. This document is an operator runbook — it
> specifies the minimum supervision policy required for a serious deployment. Operators
> **must** apply it using their host's process manager.

---

## 1. Async Queue Workers

### 1.1 Queue topology

Three queues are in use. Each requires exactly one long-running worker process:

| Queue | Worker command | Job types handled |
|-------|---------------|-------------------|
| `default` | `php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=default` | `clients.merge_execute` |
| `media` | `php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=media` | `media.image_pipeline` |
| `notifications` | `php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=notifications` | `notifications.outbound_drain_batch` |

- Workers run **forever** (no `--once` flag in production daemon mode).
- Workers self-sleep 2 s when the queue is empty.
- Workers crash on unrecoverable errors (table missing, DB unreachable). The supervisor **must** restart them.
- Unknown `job_type` values cause the job to enter `dead` state (see §4). The worker **does not crash** on unknown types.

### 1.2 Required: process supervisor

Each queue worker **must** be supervised by a process manager. The worker does not
self-restart on crash. Recommended options, in order of preference:

**systemd (recommended for VPS/bare-metal):**

```ini
# /etc/systemd/system/spa-worker-default.service
[Unit]
Description=SPA async queue worker — default queue
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/repo
ExecStart=/usr/bin/php system/scripts/worker_runtime_async_jobs_cli_02.php --queue=default
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Create analogous unit files for the `media` and `notifications` queues.

Enable and start:
```bash
systemctl enable spa-worker-default spa-worker-media spa-worker-notifications
systemctl start  spa-worker-default spa-worker-media spa-worker-notifications
```

**Supervisor (alternative for shared hosting / non-systemd):**

```ini
[program:spa-worker-default]
command=/usr/bin/php /path/to/repo/system/scripts/worker_runtime_async_jobs_cli_02.php --queue=default
directory=/path/to/repo
user=www-data
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=30
stderr_logfile=/var/log/spa-worker-default.err.log
stdout_logfile=/var/log/spa-worker-default.out.log
```

Repeat for `media` and `notifications` queues.

### 1.3 Restart policy

- **Restart on any non-zero exit:** workers exit with non-zero on fatal errors only.
  Normal idle polling returns to the main loop without exiting.
- **Exit code 2 = migration missing:** `runtime_async_jobs` table not found.
  Apply migration 124 before restarting.
- **RestartSec ≥ 5 s:** prevents tight restart loops during DB outages.
- **No supervisor restart limit:** workers should be restarted indefinitely until
  the underlying failure is resolved.

---

## 2. Stale-Reclaim Cron

The stale-reclaim cron resets `processing` rows that have exceeded the 900-second
threshold back to `pending` for re-pickup. This handles workers that crash mid-job
without marking the job failed.

**Recommended crontab (every 5 minutes, all queues):**
```cron
*/5 * * * * www-data php /path/to/repo/system/scripts/run_queue_stale_reclaim_cron.php >> /var/log/spa-queue-reclaim.log 2>&1
```

**Or per-queue:**
```cron
*/5 * * * * www-data php /path/to/repo/system/scripts/run_queue_stale_reclaim_cron.php --queue=default
*/5 * * * * www-data php /path/to/repo/system/scripts/run_queue_stale_reclaim_cron.php --queue=media
*/5 * * * * www-data php /path/to/repo/system/scripts/run_queue_stale_reclaim_cron.php --queue=notifications
```

- Stale threshold: 900 seconds (15 minutes).
- The cron logs reclaimed counts to stdout with timestamp.
- Exit code 0 = success (0 or more rows reclaimed).
- Exit code 1 = configuration/runtime error.

---

## 3. Liveness Check — How to Detect a Stuck or Dead Worker

**Quick operator check (all queues, human-readable):**
```bash
php system/scripts/read-only/queue_health_metrics_cli.php
```

**Machine-readable JSON (for cron alerting or monitoring integration):**
```bash
php system/scripts/read-only/queue_health_metrics_cli.php --json
```

**Exit codes:**

| Exit code | Meaning |
|-----------|---------|
| `0` | All queues healthy — no dead-letter or stale rows |
| `2` | Warning — stale `processing` rows detected (workers may be stuck) |
| `3` | Critical — `dead` letter jobs exist (exhausted all retry attempts) |

**Consolidated backend health (includes async queue probe):**
```bash
php system/scripts/read-only/report_backend_health_critical_readonly_01.php
php system/scripts/read-only/report_backend_health_critical_readonly_01.php --json
```
Exit codes: `0` = healthy, `1` = degraded, `2` = failed.
The `async_queue` layer reports `ASYNC_QUEUE_DEAD_JOBS` and/or `ASYNC_QUEUE_STALE_JOBS`
when intervention is needed.

**Recommended monitoring cron (alert on non-zero exit):**
```cron
*/10 * * * * www-data php /path/to/repo/system/scripts/read-only/queue_health_metrics_cli.php --json > /var/log/spa-queue-health.json 2>&1; [ $? -ne 0 ] && /usr/local/bin/send-alert "SPA queue health degraded"
```

---

## 4. Dead-Letter (DLQ) Policy

### 4.1 What "dead" means

A job enters `status=dead` when it has exhausted all retry attempts
(`attempts >= max_attempts`, default `max_attempts=5`). The job will never be
retried automatically. The `last_error` column contains the final error message.

### 4.2 Inspect dead-letter jobs

```sql
SELECT id, queue, job_type, attempts, max_attempts, last_error, created_at, updated_at
FROM runtime_async_jobs
WHERE status = 'dead'
ORDER BY updated_at DESC
LIMIT 25;
```

Or via the health CLI (shows dead count per queue):
```bash
php system/scripts/read-only/queue_health_metrics_cli.php
```

### 4.3 Operator decision matrix

| Cause | Action |
|-------|--------|
| Transient DB/network error (evident from `last_error`) | Re-queue: `UPDATE runtime_async_jobs SET status='pending', attempts=0, available_at=NOW(3) WHERE id=?` |
| Business-logic failure (e.g. referenced entity deleted) | Discard: leave as `dead` or delete the row. No retry. |
| Code bug (handler threw unexpectedly) | Fix the code, then re-queue affected rows. |
| Unknown `job_type` (no handler registered) | Register the handler and re-queue, OR discard. |
| Volume of dead jobs > 10 per day | Escalate — systematic handler failure. Investigate root cause. |

### 4.4 Poison-job definition

A **poison job** is any job that repeatedly fails and exhausts all retries. The
worker marks such jobs `dead` (fail-closed) and continues draining other jobs.
The poison job does NOT crash the worker and does NOT block subsequent jobs.

**The worker never silently discards a job.** Every failure path results in either:
- Re-queue with linear backoff (up to 24 h), OR
- `dead` status with `last_error` recorded.

### 4.5 Unknown job_type handling

If a handler is not registered for a `job_type`, the worker throws
`RuntimeException('No handler registered for job_type: ...')`. This triggers
`markFailedRetryOrDead()`. After `max_attempts` failures the job enters `dead`
state. This is the correct fail-closed behavior for unrecognised job types.

---

## 5. Other Scheduled Scripts

| Script | Exclusivity | Recommended schedule |
|--------|-------------|---------------------|
| `memberships_cron.php` | Exclusive lock + registry heartbeat | Every 5 minutes |
| `waitlist_expire_offers.php` | Exclusive lock (flock) | Every 5 minutes |
| `marketing_automations_execute.php` | Per-key exclusive lock | As needed / per-automation key |
| `run_queue_stale_reclaim_cron.php` | None (additive) | Every 5 minutes |
| `run_audit_log_archival_cron.php` | Check script for exclusivity | Every hour or daily |

All scripts exit `11` when the exclusive lock is already held (concurrent run
attempted). This is the correct behavior — do not alert on exit code 11.

---

## 6. Observability Summary

| Signal | Where | Coverage |
|--------|-------|----------|
| Queue depth / dead / stale | `queue_health_metrics_cli.php` | All async queues |
| Async queue in consolidated health | `report_backend_health_critical_readonly_01.php` layer=`async_queue` | dead + stale |
| Scheduler heartbeats / exclusive slots | `report_backend_health_critical_readonly_01.php` layer=`runtime_execution_registry` | Cron scripts |
| Image pipeline worker heartbeat | `report_backend_health_critical_readonly_01.php` layer=`image_pipeline` | Node worker |
| Session / Redis / cache / storage | `report_backend_health_critical_readonly_01.php` | All backend layers |
| Structured log events | `slog()` → `critical_path.queue`, `critical_path.*` | Job outcomes, stale reclaim |
