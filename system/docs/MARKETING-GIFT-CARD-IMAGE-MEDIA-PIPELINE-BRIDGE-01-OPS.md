# MARKETING-GIFT-CARD-IMAGE-PIPELINE-CANONICALIZATION-01 — ops note

## Old behavior removed

- Gift card template image uploads no longer validate with the marketing-local MIME/extension list (including GIF) and no longer write files under `storage/marketing/gift-card-images/...`.
- `MarketingGiftCardTemplateService` no longer uses `validateAndNormalizeImageUpload` / `storeUploadedImage` for new uploads.

## New behavior added

- New uploads go through `MediaAssetUploadService::acceptUpload()` (same gateway as `POST /media/assets`): quarantine on disk, `media_assets` row (`pending`), `media_jobs` enqueue (`process_photo_variants_v1`).
- `marketing_gift_card_images` rows created for new uploads set `media_asset_id`, and use a logical `storage_path` prefix `media/assets/{id}` (not a second direct marketing tree).
- Public previews for **ready** media-backed rows use the worker’s primary variant URL under `/media/processed/...` (files live under `system/public/media/processed/`).
- Template primary image selection only allows **legacy** rows or media-backed rows whose `media_assets.status` is **`ready`**. Pending/processing/failed rows stay visible in the image library with an honest status; they are not selectable as final template art.

## Legacy compatibility

- Rows with `media_asset_id IS NULL` keep using existing `storage_path` / `filename` / `mime_type` / `size_bytes` and remain listable; GIF (and other legacy) files already stored are unchanged. This wave does **not** mass-migrate old files.
- `marketing_gift_card_templates.image_id` still references `marketing_gift_card_images.id` (unchanged FK contract).
- Soft-delete rules are unchanged: cannot delete a library row while active templates reference it. Marketing soft-delete does **not** remove canonical media files in this wave.

## Accepted file types for **new** uploads

Aligned with `MediaImageSignatureValidator`: **JPEG, PNG, WebP, AVIF**. **SVG blocked.** **GIF** is not accepted on new uploads; legacy GIF rows remain readable in the library.

## Proof commands

From the `system/` directory (same as other scripts):

```bash
php scripts/migrate.php
php scripts/read-only/verify_marketing_gift_card_images_canonical_media_bridge_01.php
php scripts/read-only/verify_marketing_gift_card_templates_backend_foundation_01.php
php scripts/read-only/verify_marketing_gift_card_templates_post_migration_runtime_05.php
```

HTTP multipart proof for the media gateway (exercises `is_uploaded_file`):

```bash
php scripts/dev-only/proof_media_post_assets_http.php
```

After upload, variants are produced by the Node worker. With **`APP_ENV=local`**, the app spawns a **background drain** for that upload’s `media_assets.id` (see `MediaUploadWorkerDevTrigger`); you can still run a continuous worker in a second terminal if you prefer. With **`APP_ENV=production`**, you must run the worker via your process manager as before.

**FIFO:** each `run_media_image_worker_once.php` run processes **one** job — the lowest `media_jobs.id` still pending. A brand-new upload can sit behind older pending jobs.

```bash
php scripts/dev-only/run_media_image_worker_once.php
```

If a specific `media_assets.id` stays pending, inspect the queue and whether older jobs block it:

```bash
php scripts/read-only/media_queue_pending_audit.php
php scripts/dev-only/drain_media_queue_until_asset.php --asset-id=YOUR_ASSET_ID
```

For local development, keep a worker running while you upload (second terminal — **standard path**):

```bash
php scripts/dev-only/run_media_image_worker_loop.php
```

See `workers/image-pipeline/README.md` for details and env variables.

## Laragon / Windows — local runtime truth

**Env keys (expected)**

| Key | Role |
|-----|------|
| `APP_ENV=local` | Enables dev auto-drain after each `MediaAssetUploadService::acceptUpload()` (non-production only). |
| `MEDIA_DEV_AUTO_DRAIN_ON_UPLOAD` | Unset on `local` = ON. Set `0` / `false` to disable background drain. |
| `MEDIA_DEV_PHP_BINARY` | **Required when the web SAPI reports `php-cgi.exe` (or otherwise not a CLI binary).** Set to the full path of `php.exe` (e.g. `C:\laragon\bin\php\php-8.2.x-Win32-vs16-x64\php.exe`). |
| `NODE_BINARY` | Optional but recommended if `node` is not on the Apache/PHP service `PATH`. Must be a path to `node.exe` if set; it is forwarded to the detached drain process on Windows (`set NODE_BINARY=...&& start /B ...`). |

**How auto-drain works**

1. Upload completes in HTTP: quarantine + `media_assets` + `media_jobs` only (no synchronous encode).
2. `MediaUploadWorkerDevTrigger::maybeSpawnAfterUpload()` runs only when not production, auto-drain is enabled, and a CLI PHP binary resolves (see `MediaWorkerLocalRuntimeProbe::resolveCliPhpBinary()`).
3. A **detached** Windows `cmd /c start /B` (or Unix `exec … &`) runs `scripts/dev-only/drain_media_queue_until_asset.php` for that `media_assets.id` so FIFO is respected without blocking the request.
4. If CLI PHP cannot be resolved, **no fake success**: `storage/logs/media_dev_worker_spawn.json` records `ok: false` and `reason: cli_php_unresolved` (or `popen_failed`, etc.).

**When the worker is offline**

- Rows stay `pending`/`processing` until the Node worker updates jobs/assets. The UI polls `/marketing/gift-card-templates/images/status`, which returns **queue truth** (`queue.*`) and `worker_hint` (`worker_process_detected`, `probable_block_reason`). There is no optimistic “ready” preview.

**Continuous worker loop (second terminal)**

From the `system/` directory:

```bash
php scripts/dev-only/run_media_image_worker_loop.php
```

**Proof / diagnosis (one command)**

```bash
php scripts/read-only/gift_card_image_pipeline_diagnose.php
```

**Typical local proof sequence (Windows)**

1. `cd` to `system/`
2. Ensure `.env` has `APP_ENV=local`, `MEDIA_DEV_PHP_BINARY` pointing at Laragon `php.exe` if needed, and `NODE_BINARY` if `node` is not on PATH.
3. Terminal A: `php scripts/dev-only/run_media_image_worker_loop.php`
4. Upload a gift-card image in the browser; or rely on auto-drain alone if CLI spawn succeeds.
5. If stuck: `php scripts/read-only/gift_card_image_pipeline_diagnose.php` — read the final `DIAGNOSIS:` line and `last_spawn_diagnostics`.
