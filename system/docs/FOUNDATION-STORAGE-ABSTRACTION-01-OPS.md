# FOUNDATION-STORAGE-ABSTRACTION-01 — Ops / proof

## What shipped (wave 01)

- **PHP:** `Core\Storage\Contracts\StorageProviderInterface` with `LocalFilesystemStorageProvider` (only implementation). Canonical roots are derived from `SYSTEM_PATH` or optional `STORAGE_LOCAL_SYSTEM_PATH` / `storage.local.system_root`.
- **Config:** `system/config/storage.php` — `STORAGE_DRIVER` (must be `local` until another driver exists), optional `STORAGE_LOCAL_SYSTEM_PATH`.
- **Callers:** `MediaAssetUploadService`, `DocumentService`, `MarketingGiftCardTemplateService` use the provider instead of ad hoc `base_path()` for Tier A paths.
- **Worker:** `workers/image-pipeline/src/processor.mjs` accepts `STORAGE_LOCAL_SYSTEM_PATH` when `MEDIA_SYSTEM_ROOT` is unset (same validation: directory must contain `storage/media/`).

## Operator invariants

1. **Single layout:** Application `system/` directory contains `storage/` and `public/` as today. Overriding the root is **only** for nonstandard deployments; PHP and the image worker must use the **same** absolute `system/` path.
2. **Multi-node:** Wave 01 is still **local filesystem**. Horizontal scale requires shared disk or a future object-storage driver; `storage.driver !== local` intentionally **throws** at bootstrap so misconfiguration is loud.
3. **Proof:** `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_01_readonly_01.php`
4. **Wave 02 (serving):** `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_02_readonly_01.php` — see `FOUNDATION-STORAGE-ABSTRACTION-02-OPS.md`.
5. **Wave 03 (checksum / stream validation):** `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_03_readonly_01.php` — see `FOUNDATION-STORAGE-ABSTRACTION-03-OPS.md`.

## Env quick reference

| Variable | Where |
|----------|--------|
| `STORAGE_DRIVER` | PHP — default `local` |
| `STORAGE_LOCAL_SYSTEM_PATH` | PHP `storage.local.system_root`; optional alias for worker |
| `MEDIA_SYSTEM_ROOT` | Worker — preferred if both worker vars could differ; if unset, worker falls back to `STORAGE_LOCAL_SYSTEM_PATH` or repo-relative `system/` |
