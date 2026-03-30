# Image pipeline worker (media_jobs)

## What this does

- Claims rows from `media_jobs` with `job_type = process_photo_variants_v1` (MySQL `FOR UPDATE SKIP LOCKED`).
- Reads originals from `system/storage/media/quarantine/{organization_id}/{branch_id}/`.
- Writes responsive variants under `system/public/media/processed/` and updates `media_assets` / `media_asset_variants`.

Upload paths (including **Gift Card Image Library**) call `MediaAssetUploadService::acceptUpload()`, which inserts a `media_assets` row (`pending`) and **always enqueues** a `media_jobs` row. **PHP does not encode variants**; until this worker runs, assets stay `pending` and the UI shows processing.

## Execution observability (FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01)

- `src/worker.mjs` updates table `runtime_execution_registry` (`execution_key = worker:image_pipeline`) on each loop iteration (heartbeat) and on graceful shutdown.
- Queue + heartbeat report (from repo root): `php system/scripts/read-only/report_image_pipeline_runtime_health_readonly_01.php`
- Ops notes: `system/docs/FOUNDATION-JOBS-SCHEDULER-RELIABILITY-01-OPS.md`

## Why uploads look “stuck” locally

The web server (Apache, `php -S`, Laragon, etc.) does **not** start this worker. If you never run it, jobs accumulate as `pending` and the gift-card library never reaches **Ready**.

### FIFO (one job per `run_media_image_worker_once`)

Claims use `ORDER BY j.id ASC LIMIT 1` (`processor.mjs`). Each invocation of `run_media_image_worker_once.php` completes **at most one** job. If many older rows are still `pending`, a newly uploaded asset’s job id is larger and will **not** run until those clear — the UI can poll forever while the queue drains slowly.

**Diagnose (from `system/`):**

```bash
php scripts/read-only/media_queue_pending_audit.php
```

**Clear backlog for one asset (dev):**

```bash
php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=YOUR_MEDIA_ASSETS_ID
```

## Local dev: recommended (env from app)

From the **`system/`** directory, with `system/.env` (or `.env.local`) containing the same `DB_*` keys as the app:

**Default (APP_ENV=local):** After each upload, the app spawns a background drain for that asset so **FIFO backlog still clears** without a second terminal. Set `MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD=0` to disable. Production (`APP_ENV=production`) never runs this.

**One job (quick test):**

```bash
php scripts/dev-only/run_media_image_worker_once.php
```

**Continuous processing (optional second terminal — processes all queues without spawning PHP drains):**

```bash
php scripts/dev-only/run_media_image_worker_loop.php
```

**Or from this package (same Node loop):**

```bash
npm run dev:loop
```

Optional: set `WORKER_POLL_MS=4000` in `.env` for a slightly quicker idle poll when no jobs are queued.

## Local dev: run Node directly

From **`workers/image-pipeline/`** after `npm ci`:

```bash
cd workers/image-pipeline
npm run start
```

You must export **`DB_DATABASE`**, **`DB_USERNAME`**, and **`DB_PASSWORD`** (and optionally `DB_HOST`, `DB_PORT`) to match the app database. If unset, the worker exits immediately.

`MEDIA_SYSTEM_ROOT` is optional: when omitted, the worker resolves the repo `system/` directory relative to this package. When the variable **is** set, it must point at a directory that contains `storage/media/`; otherwise the worker exits with an error (no silent fallback).

**FOUNDATION-STORAGE-ABSTRACTION-01:** If `MEDIA_SYSTEM_ROOT` is unset, the worker also honors **`STORAGE_LOCAL_SYSTEM_PATH`** (same value as PHP `storage.local.system_root` / `STORAGE_LOCAL_SYSTEM_PATH`) so web + worker share one explicit `system/` root when you override the default layout.

## Verify one upload end-to-end

1. Start the worker loop (see above).
2. Upload an image via **Marketing → Gift Card Templates → Manage images**.
3. In MySQL, confirm a new `media_jobs` row (`pending` → `completed`) for the new `media_assets.id`.
4. Confirm rows in `media_asset_variants` and `media_assets.status = ready`.
5. Reload the library page (or rely on UI polling): **Ready** + preview.

Read-only checks:

```bash
cd system
php scripts/read-only/diagnostics_media_image_pipeline.php
php scripts/read-only/runtime_proof_media_latest_rows.php
```

## Production

Use a process manager (systemd, pm2, Kubernetes job worker, etc.) to run `node src/worker.mjs` with the same database credentials and `MEDIA_SYSTEM_ROOT` pointing at the deployed `system` tree if paths differ.
