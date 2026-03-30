# FOUNDATION-STORAGE-ABSTRACTION-02 — Ops / proof

## What shipped (wave 02)

- **Contract:** `StorageProviderInterface` extended with stream/read helpers and explicit capability hooks (`supportsPublicFilesystemPath`, `resolvePublicFilesystemPathIfSupported`, `resolvePublicUrl`, `openReadStream`, `readStreamToOutput`).
- **Local provider:** `LocalFilesystemStorageProvider` implements all methods; `resolvePublicUrl` is **always null** (public processed URLs remain normal app paths like `/media/processed/...`).
- **Callers:** `Dispatcher::tryServePublicProcessedMedia()` uses `StorageKey::publicMedia` + provider size/stream (no direct `realpath`/`readfile` in that method). `DocumentService::deliverAuthenticatedDownload()` streams via the provider instead of `readfile` on a resolved absolute path.
- **Still local-only:** `storage.driver !== local` continues to **fail fast** at factory construction until a non-local implementation exists.

## Operator invariants

1. **Processed media URL shape** is unchanged: GET `/media/processed/...` under document root → PHP still streams bytes for the local driver.
2. **Future object storage:** A new driver would implement `openReadStream` / `readStreamToOutput` without `supportsPublicFilesystemPath`; public processed delivery may move to **signed URLs** (`resolvePublicUrl`) or app-proxy streaming, depending on product choice.
3. **Proof:** `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_02_readonly_01.php` (plus wave 01 verifier for baseline layout).
