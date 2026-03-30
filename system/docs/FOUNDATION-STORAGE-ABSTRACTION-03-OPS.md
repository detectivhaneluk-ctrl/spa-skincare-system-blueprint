# FOUNDATION-STORAGE-ABSTRACTION-03 — Ops / proof

## What shipped (wave 03)

- **Contract:** `StorageProviderInterface::supportsContentHashing()` and `computeSha256HexForKey(StorageKey $key)` — keyed SHA-256 via internal read stream (`hash_update_stream`), no caller-visible absolute path.
- **Local provider:** Implements both; `supportsContentHashing` is **true**.
- **Callers:** `DocumentService` (document persist checksum + size), `MediaAssetUploadService` (quarantine checksum + size), `MarketingGiftCardTemplateService` (variant delete gate uses `isReadableFile` instead of path resolution).
- **Validation:** `MediaImageSignatureValidator::validateFromStream()` for magic + `finfo_buffer`; temp-path **`validate()`** remains a thin wrapper for other callers.

## Operator invariants

1. **Checksum stability:** SHA-256 of stored bytes is unchanged vs prior `hash_file` on local disk for the same file content.
2. **Non-local future:** A second driver may implement `computeSha256HexForKey` via remote stream; if impossible, return **`supportsContentHashing(): false`** and fail fast at call sites that require checksums (documents/media policies).
3. **Proof:** `php system/scripts/read-only/verify_foundation_storage_abstraction_wave_03_readonly_01.php`
