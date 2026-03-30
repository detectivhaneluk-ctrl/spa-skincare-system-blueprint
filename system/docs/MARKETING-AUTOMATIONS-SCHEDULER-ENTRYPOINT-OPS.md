# Marketing automations — scheduler entrypoint (A-007)

## Contract

- **In-repo execution entrypoint:** `system/scripts/marketing_automations_execute.php` (CLI only).
- **Not invoked by the web app.** Operators must configure **cron**, Windows Task Scheduler, or another external runner to call PHP on that script with `--key=…` (see script docblock).
- **Operator acknowledgment** in-app is stored via settings key `marketing.automations_external_scheduler_acknowledged` (see `SettingsService` and `/marketing/automations/scheduler-acknowledgment`). This records that the external dependency is understood; it does not run jobs.

## Verification

```bash
php system/scripts/read-only/verify_marketing_automation_scheduler_honesty_a007_01.php
```
