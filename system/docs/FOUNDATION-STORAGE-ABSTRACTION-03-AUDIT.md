# FOUNDATION-STORAGE-ABSTRACTION-03 — Local-only consumption audit (wave 03)

**Scope:** Remaining **`hash_file` / `localFilesystemPathFor` / direct `fopen`** on **storage-backed** keys and upload validation shape. **Not repeated:** STG-01 upload roots, STG-02 Dispatcher/document stream serve, worker env parity.

---

## 1. `system/modules/documents/services/DocumentService::storeUploadedFile`

| # | Before | After | Tier |
|---|--------|-------|------|
| 1 | `localFilesystemPathFor` + `filesize` + `hash_file('sha256', …)` | `fileSizeOrFail` + `computeSha256HexForKey` | **A — closed** |

---

## 2. `system/modules/media/services/MediaAssetUploadService::acceptUpload`

| # | Before | After | Tier |
|---|--------|-------|------|
| 1 | Staging checksum: `localFilesystemPathFor` + `hash_file` + `filesize` | `computeSha256HexForKey` + `fileSizeOrFail` | **A — closed** |
| 2 | Signature: `validate($tmpPath)` only | `validateFromStream(fopen tmp)`; **`getimagesize($tmpPath)`** unchanged (PHP needs path) | **A — closed** |

---

## 3. `system/modules/media/services/MediaImageSignatureValidator`

| # | Before | After | Tier |
|---|--------|-------|------|
| 1 | Internal `fopen` + `finfo->file(path)` | **`validateFromStream`**: `stream_get_contents` + `finfo->buffer` + magic on head; **`validate(path)`** wraps stream | **A — closed** |

**Note:** PHP upload temp remains a host path for `getimagesize` in `MediaAssetUploadService` — honest local-only until GD/imagick stream APIs are adopted (defer).

---

## 4. `system/modules/marketing/services/MarketingGiftCardTemplateService` (variant purge)

| # | Before | After | Tier |
|---|--------|-------|------|
| 1 | `localFilesystemPathFor` after `fileExists` to “prove” containment before delete | **`isReadableFile`** gate + `deleteFileIfExists` | **A — closed** |

---

## 5. Minimum contract extension (wave 03)

| Method | Role |
|--------|------|
| `supportsContentHashing(): bool` | Capability for keyed digest (object driver may return false until implemented). |
| `computeSha256HexForKey(StorageKey $key): string` | Stream hash; no path returned to callers. |

---

## 6. Deferred (proof in charter backlog)

| Surface | Reason |
|---------|--------|
| Full non-local `StorageProviderInterface` implementation | Factory still **`local` only**; second driver is a separate wave. |
| `getimagesize` on temp path in media upload | Replace with stream-safe dimension probe later. |
| Scripts / cron `fopen` locks | Not application storage provider scope. |
