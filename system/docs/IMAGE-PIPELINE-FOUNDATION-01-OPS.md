# Image pipeline — foundation ops (REAL-REAPPLY-08)

## Topology (this repo)

- **Bootstrap:** `system/modules/bootstrap.php` loads ordered registrars from `system/modules/bootstrap/` including `register_media.php`.
- **Routes:** `system/routes/web.php` loads `system/routes/web/register_media.php` among other registrars.
- **Autoload:** `Modules\Media\…` maps to `system/modules/media/{controllers|repositories|services}/…` per `system/core/app/autoload.php`.

## Foundation wave (current)

- **Migration:** `system/data/migrations/103_media_image_pipeline_foundation.sql` — tables `media_assets`, `media_asset_variants`, `media_jobs`; permissions `media.upload`, `media.view` (owner/admin backfill).
- **Upload:** `POST /media/assets` — multipart field `image`; JSON only. Middleware: `AuthMiddleware`, `TenantProtectedRouteMiddleware`, `PermissionMiddleware::for('media.upload')`.
- **Storage:** raw files under `system/storage/media/quarantine/{organization_id}/{branch_id}/` (not web-served; `system/storage/.htaccess` denies HTTP).
- **Config:** `system/config/media.php` — `IMAGE_MAX_UPLOAD_MB`, `IMAGE_MAX_MEGAPIXELS` → `config('media.*')`.

## Apply schema

From the `system/` directory (with DB credentials in `system/.env` or `system/.env.local`):

```bash
php scripts/migrate.php
```

## Diagnostics

```bash
php scripts/read-only/diagnostics_media_image_pipeline.php
```

Expect: three tables present, both permission codes present, pending counts, quarantine path exists and is writable (create `storage/media/quarantine` if missing).

## Runtime proof (multipart POST)

`scripts/create_user.php` loads `modules/bootstrap.php` so provisioning services resolve.

PHP built-in server (from `system/public/`):

```bash
php -S 127.0.0.1:8900 router.php
```

Then (from `system/`), after logging in via the same session flow as a browser (CSRF + cookies), `scripts/dev-only/proof_media_post_assets_http.php` performs a real `POST /media/assets` with `CURLFile` so `is_uploaded_file()` succeeds.

## Worker (variant processing — IMAGE-PIPELINE-WORKER-VARIANT-PROCESSING-13)

- **Code:** `workers/image-pipeline/` — `sharp` + `mysql2`; claims `media_jobs` (`process_photo_variants_v1`) with `FOR UPDATE SKIP LOCKED`, writes **`public/media/processed/`** only (canonical variant root; no parallel `public/media/variants` tree).
- **Critical (local):** the HTTP app **does not** start the worker. New uploads enqueue `media_jobs` only; without a running worker, `media_assets` stay `pending` (gift-card library and any other consumer will look “stuck”). See `workers/image-pipeline/README.md`.
- **Continuous loop (dev — recommended default):** from `system/`: `php scripts/dev-only/run_media_image_worker_loop.php` (forwards `MEDIA_SYSTEM_ROOT` + `DB_*` from app env; run in a second terminal while testing uploads). Without this (or another supervisor), nothing dequeues jobs.
- **One pass (dev):** from `system/`: `php scripts/dev-only/run_media_image_worker_once.php` (sets `MEDIA_SYSTEM_ROOT`, `WORKER_ONCE`, `WORKER_MAX_JOBS=1`, DB + optional `IMAGE_JOB_*` from app env). **Processes at most one job per invocation**, and claims the **oldest** pending `media_jobs.id` (FIFO). Running it once after a new upload does **not** guarantee that upload’s job ran if older pending jobs exist.
- **Queue audit (read-only):** `php scripts/read-only/media_queue_pending_audit.php` — stuck gift-card row, job row, count of older pending jobs ahead in FIFO, first 10 pending by id. Optional: `--asset-id=N`. Optional env `MEDIA_QUEUE_AUDIT_PROBE_PROCESSES=1` (Windows) to list `node.exe` command lines containing `worker.mjs`.
- **Drain until asset (dev backlog):** `php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=N` — runs the one-job worker in a loop until that asset is `ready` or `failed` (or `--max-passes`). Use when FIFO backlog blocks a specific upload.
- **Auto-drain after upload (local only):** With `APP_ENV=local`, each successful `MediaAssetUploadService::acceptUpload()` (gift-card library and `POST /media/assets`) spawns a **background** PHP process that runs `drain_media_queue_until_asset.php` for that `asset_id`, so FIFO backlog clears without a second terminal or repeated `run_once`. Production (`APP_ENV=production`) never runs this. Disable with `MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD=0` or `false`.
- **AVIF:** emitted only when a startup probe successfully encodes a 2×2 buffer (otherwise webp + jpg only).
- **Proof:** Pasted `*RESULT.txt` files are no longer kept under `system/docs/` (source hygiene). Regenerate locally from `system/` after upload + worker (commands above), e.g. `php scripts/read-only/prove_marketing_image_end_to_end.php` and/or `php scripts/read-only/media_queue_health_truth.php`.

## Worker hardening (IMAGE-PIPELINE-WORKER-HARDENING-14)

- **Env (optional):** `IMAGE_JOB_STALE_LOCK_MINUTES` (default 30), `IMAGE_JOB_MAX_ATTEMPTS` (default 5) — same names in `.env` / `.env.local`; forwarded by `run_media_image_worker_once.php`.
- **Housekeeping:** each loop reclaims `processing` jobs with `locked_at` older than the threshold (and matching `media_assets` → `pending`); fails `pending` jobs with `attempts >= max`.
- **Retries:** transient failures requeue (`job` + `asset` → `pending`, partial output dir removed, variant rows deleted). Decode/corrupt/unsupported → immediate terminal fail. Missing quarantine at claim time → terminal fail **without** incrementing `attempts`.
- **Success:** deletes quarantine source after DB commit.
- **Idempotency:** before insert, worker `DELETE`s `media_asset_variants` for the asset in the success transaction.
- **Dev proof driver:** `php scripts/dev-only/hardening_proof_media_worker.php …`
- **Proof:** No in-repo RESULT transcript. From `system/`, use the dev proof driver (see script header for subcommands) and read-only state checks: `php scripts/read-only/runtime_proof_media_worker_hardening.php`.

## Not in this wave (remaining)

- Public read API / `<picture>` partials / CDN — delivery layer still deferred.

## Next wave (reference only)

Optional: public read route for variants, `GET /media/assets/{id}` for staff, backoff on `available_at`, metrics.
