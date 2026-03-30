# FOUNDATION-OBSERVABILITY-AND-ALERTING-01 — Ops / proof

## Commands

```bash
# Human + one-line JSON (from repo root)
php system/scripts/read-only/report_backend_health_critical_readonly_01.php

# Machine-only (cron → jq / monitoring)
php system/scripts/read-only/report_backend_health_critical_readonly_01.php --json

# Exit code only
php system/scripts/read-only/report_backend_health_critical_readonly_01.php --quiet
```

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | All probed layers `healthy` |
| 1 | At least one `degraded`, none `failed` |
| 2 | At least one `failed`, or bootstrap/collection error |

## Structured log (optional)

Default: if exit ≠ 0, emits one JSON line via `StructuredLogger` with `event_code=observability.backend_health.issue_v1` and `layers_compact` (layer, status, reason_codes only). Disable with `--no-structured-log`.

## Local development

- Image pipeline often reports **degraded** if `media_jobs.pending>0` and the Node worker is not running — **expected**; use exit 1 as a reminder or run the worker loop.
- Tenant isolation proof remains a **separate** script: `php system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`.

## Static proof

```bash
php system/scripts/read-only/verify_foundation_observability_backend_health_readonly_01.php
```

## Bundle

`report_operational_readiness_summary_readonly_01.php` includes the consolidated backend health step; overall bundle exit follows the worst subprocess.
