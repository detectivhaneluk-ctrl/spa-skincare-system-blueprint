# MARKETING-GIFT-CARD-IMAGE-DELETE-HARDENING-01 — Ops note

## Fatal cause fixed

`MarketingGiftCardTemplateRepository::hardDeleteOrphanMediaAssetForLibrary()` called `Core\App\Database::rowCount()`, which does not exist. Deletion success is now detected with `PDOStatement::rowCount()` on the statement returned by `Database::query()`.

## DB rows affected (delete flow, in order)

1. **`marketing_gift_card_templates`**: `image_id` set to `NULL` for rows in the same branch that reference the library image and have **`deleted_at IS NOT NULL`** (archived only). Active templates are unchanged; delete is still blocked if any active template uses the image.
2. **`marketing_gift_card_images`**: soft delete — `deleted_at`, `is_active = 0`, `updated_by`.
3. **`media_jobs` / `media_assets`**: pending/processing jobs and assets are failed/updated, then **`DELETE FROM media_assets`** when no active library row references the asset. **`media_asset_variants`** and **`media_jobs`** rows for that asset are removed by **ON DELETE CASCADE** (schema).

## Archived-template reference cleanup

Canonical rule: before the library row is soft-deleted, archived templates in the same tenant-scoped branch that still point at this `image_id` are updated to **`image_id = NULL`**. Active templates are not altered and continue to block deletion.

## Filesystem cleanup targets (media-backed)

After a successful orphan **`media_assets`** delete:

- Quarantine: `storage/media/quarantine/{org}/{branch}/{stored_basename}` and the same basename with **`.incoming`** (basename must be a single segment; no path separators).
- Variant files: each safe `media_asset_variants.relative_path` under `public/` (rejects `..`).
- Processed tree: **`public/media/processed/{org}/{branch}/{assetId}/`** removed recursively, only if the resolved path stays under **`public/media/processed`**.
- Worker staging: sibling directories under **`public/media/processed/{org}/{branch}/`** named **`__stg_{assetId}_{jobId}`** (numeric job id only; no broad wildcards).

The previous incorrect cleanup target **`public/media/assets/{org}/{branch}/{assetId}`** (wrong for the Node worker) is no longer used for processed output.

## Legacy (`media_asset_id` NULL)

Only soft delete + archived-template cleanup run in the DB. Filesystem cleanup runs only when `storage_path` resolves to a single existing file under an allowed prefix:

- `storage/…` under the app `storage/` root, or  
- `media/processed/…` or `media/assets/…` under `public/`.

Otherwise no filesystem deletion is attempted; a log line records that legacy path cleanup was skipped.

## Guarded cases / partial failure

- **DB failure**: transaction rolls back; controller shows **error** flash (no success).
- **Filesystem cleanup failure after commit**: **warning** flash plus **`error_log`** lines prefixed with **`[gc-image-delete]`**.
- **Retry**: second delete on the same id fails with **“already deleted or missing”** (idempotent).
- **Other assets**: cleanup is scoped to the deleted asset id and validated roots; sibling asset directories are not matched by staging pattern (exact `__stg_{id}_` prefix).

## Verifier (static)

```bash
php system/scripts/read-only/verify_marketing_gift_card_image_delete_hardening_01.php
```
