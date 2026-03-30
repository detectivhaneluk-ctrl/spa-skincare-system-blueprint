# RUNTIME-DISTRIBUTED-PLANES-02

Operational truth for **FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02**: centralized session configuration, server-side session revocation, protected-path rate limits, durable async jobs, and storage driver selection.

## Session plane

- **Configurable backend:** `SESSION_DRIVER` (`files` | `redis`) in `system/config/session.php` via `SessionBackendConfigurator`.
- **Logout-all / revoke:** `users.session_version` (migration `123`). `SessionAuth::SESSION_EPOCH_KEY` mirrors the DB counter at login and support-entry transitions. `SessionEpochCoordinator` + `AuthService::check()` drop sessions when the stored epoch is behind the DB value.
- **Password change:** `AuthService::updatePasswordForCurrentUser()` bumps `session_version` and refreshes the current session epoch so the active session stays valid while others are invalidated if the operator also bumps version elsewhere (normally unchanged for solo password change — only increment affects *other* devices; same session gets explicit `$_SESSION` refresh).
- **CLI:** `system/scripts/invalidate_user_sessions_cli_02.php --user-id=N`

## Cache / rate-limit plane

- **Adapter:** `SlidingWindowRateLimiterInterface` (Redis or DB) — registered in `register_appointments_online_contracts.php`.
- **Named gate:** `RuntimeProtectedPathRateLimiter` — namespaces `rt_prot_v1` buckets `login_post` and `platform_manage_post`.
- **Wiring:** `LoginController::attempt` (per client IP via `ClientIp::forRequest()`), `PlatformManagePostRateLimitMiddleware` on `platformManageMw` POSTs.

## Queue plane

- **Table:** `runtime_async_jobs` (migration `124` / `full_project_schema.sql`).
- **Repository:** `Core\Runtime\Queue\RuntimeAsyncJobRepository` — explicit statuses, `attempts` / `max_attempts`, backoff to `pending`, terminal `dead`, stale processing reclaim (15 minutes).
- **Worker:** `system/scripts/worker_runtime_async_jobs_cli_02.php` (`--queue=`, `--once`).
- **Smoke (dev):** `system/scripts/dev-only/runtime_async_jobs_retry_deadletter_smoke_02.php`

## Object storage plane

- **Factory:** `StorageProviderFactory` — `local` (default) or `s3_compatible` (`STORAGE_DRIVER`, `STORAGE_S3_*` in `system/config/storage.php`).
- **S3:** `S3CompatibleObjectStorageProvider` + `S3SigV4Signer` (ext-curl). Directory-tree helpers return honest warnings; large reads buffer via `php://temp`.

## Proof scripts

- `system/scripts/read-only/verify_session_backend_and_session_epoch_readonly_02.php`
- `system/scripts/read-only/verify_runtime_async_jobs_queue_contract_readonly_02.php`
- `system/scripts/read-only/verify_storage_driver_factory_truth_readonly_02.php`
- `system/scripts/run_runtime_distributed_planes_proof_gate_02.php`
