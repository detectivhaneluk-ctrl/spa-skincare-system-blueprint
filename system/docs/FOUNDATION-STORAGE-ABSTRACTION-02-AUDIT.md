# FOUNDATION-STORAGE-ABSTRACTION-02 — Serving / consumption truth audit (wave 02)

**Scope:** Remaining runtime paths that assumed host-local `realpath` + `readfile` for **public processed media** and **authenticated document download**. Does not re-audit wave 01 upload/quarantine/marketing purge (see `FOUNDATION-STORAGE-ABSTRACTION-01-AUDIT.md`).

---

## 1. `system/core/router/Dispatcher.php::tryServePublicProcessedMedia()`

| # | Old assumption | Stream vs URL | Tier |
|---|----------------|---------------|------|
| 1 | `SYSTEM_PATH . '/public'`, `realpath`, `readfile` on absolute disk path | Needs stream or remote fetch later | **A — closed wave 02** |

**Closure target:** `StorageKey::publicMedia($rel)` + `StorageProviderInterface::isReadableFile`, `fileSizeOrFail`, `readStreamToOutput`.

**Proof:** `verify_foundation_storage_abstraction_wave_02_readonly_01.php`; runtime unchanged for local driver (same bytes, same headers).

---

## 2. `system/modules/documents/services/DocumentService::deliverAuthenticatedDownload()`

| # | Old assumption | Stream vs URL | Tier |
|---|----------------|---------------|------|
| 1 | `localFilesystemPathFor` + `readfile($absolute)` | Needs stream for object drivers | **A — closed wave 02** |

**Closure target:** Resolve `StorageKey` from DB path → `fileSizeOrFail` + `readStreamToOutput` (headers unchanged).

**Deferred (historical):** `storeUploadedFile` checksum — **closed in STG-03** (`computeSha256HexForKey`).

---

## 3. Other grep hits (defer with proof)

| Path | Assumption | Tier |
|------|------------|------|
| `MediaAssetUploadService` | `getimagesize($tmpPath)` for dimensions | **B** — PHP temp path; stream/dimension library later |
| `system/public/router.php` | PHP built-in static server `file_exists` | **Out of scope** — dev server only |

---

## Minimum contract extension (wave 02)

| Method | Purpose |
|--------|---------|
| `supportsPublicFilesystemPath()` | Capability: can expose verified absolute paths (local true; object false). |
| `resolvePublicFilesystemPathIfSupported()` | Optional fast path for tooling when supported (prefer **`computeSha256HexForKey`** for digests — wave 03). |
| `resolvePublicUrl()` | CDN/signed URL when bytes are not proxied; **local returns null**. |
| `openReadStream()` | Binary read stream; required for portable serving. |
| `readStreamToOutput()` | HTTP body without caller `readfile`. |

**Not implemented:** Second storage driver, signed URLs, CDN offload, changing worker layout.
