# FOUNDATION-STORAGE-ABSTRACTION-01 — Storage truth audit (wave 01)

**Method:** Code-truth review of upload, document, marketing cleanup, and worker paths. **Tier A** = HTTP- or purge-visible flows that hardcoded `base_path()` / single-host filesystem layout. **Tier B** = static HTTP serving or secondary callers. **Tier C** = document-only alignment.

---

## 1. `system/modules/media/services/MediaAssetUploadService.php`

| # | Locations now | Local path assumptions | Worker visibility | Tier |
|---|---------------|------------------------|-------------------|------|
| 1 | Quarantine: `storage/media/quarantine/{org}/{branch}/{uuid}.ext` | Was `base_path('storage/media/quarantine/...')` | Worker reads same tree via `MEDIA_SYSTEM_ROOT` / default | **A — closed wave 01** |

**Closure target:** `StorageKey::mediaQuarantine` + `StorageProviderInterface` (`importLocalFile`, `renameKey`).

**Acceptance proof:** `verify_foundation_storage_abstraction_wave_01_readonly_01.php` + no `base_path('storage/media/quarantine` in this file.

---

## 2. `system/modules/documents/services/DocumentService.php`

| # | Locations now | Local path assumptions | Stream semantics | Tier |
|---|---------------|------------------------|------------------|------|
| 1 | Store under `storage/documents/{Y/m}/{random}.ext` | Was `base_path('storage/documents/...')` + `realpath` containment | `readfile` after validated local path | **A — closed wave 01** |
| 2 | Download | Was hand-rolled `base_path` + `realpath` under documents root | Same | **A — closed wave 01** |

**Closure target:** `StorageKey::documents` / `fromDocumentsModuleStoragePath` + provider `localFilesystemPathFor`.

**Acceptance proof:** Verifier + DB `storage_path` prefix unchanged (`storage/documents/`).

---

## 3. `system/modules/marketing/services/MarketingGiftCardTemplateService.php`

| # | Locations now | Assumptions | Tier |
|---|---------------|-------------|------|
| 1 | Purge: quarantine, `public/media/processed` variants, staging `__stg_*` dirs | Was `base_path('public/...')`, `base_path('storage/media/quarantine/...')` | **A — closed wave 01** |
| 2 | Legacy library file cleanup | Was `base_path` + `realpath` under `storage` or `public` | **A — closed wave 01** |

**Closure target:** `StorageKey::publicMedia`, `mediaQuarantine`, `storageSubtree` + `deletePublicDirectoryTreeIfUnderPrefix`, `deleteFileIfExists`.

---

## 4. `workers/image-pipeline/src/processor.mjs`

| # | Behavior | PHP parity | Tier |
|---|----------|------------|------|
| 1 | `resolveSystemRoot()` → `system/storage/media`, `system/public` layout | Must match PHP `SYSTEM_PATH` (or override) | **A — aligned wave 01** (`STORAGE_LOCAL_SYSTEM_PATH` alias) |

---

## 5. `system/core/router/Dispatcher.php::tryServePublicProcessedMedia()`

| # | Behavior | Tier |
|---|----------|------|
| 1 | Serves files under `public/media/processed` without DB | **A — closed wave 02** (`FOUNDATION-STORAGE-ABSTRACTION-02-AUDIT.md`) |

---

## Minimum abstraction contract (wave 01)

- **`StorageProviderInterface`:** local path resolution (legacy tooling), stream serve, **SHA-256 via `computeSha256HexForKey` (wave 03)**, existence, directory checks, upload import, rename, recursive delete with prefix guard, public-directory delete under prefix.
- **`StorageKey`:** volume + POSIX relative path (no `..`); maps cleanly to future bucket keys.
- **`StorageProviderFactory`:** `storage.driver === local` only; other values **fail fast** at construction.
- **Config:** `system/config/storage.php` — `STORAGE_DRIVER`, `STORAGE_LOCAL_SYSTEM_PATH` (optional `system/` root).

**Not implemented (honest):** Non-local storage driver, signed URLs/CDN offload; `getimagesize` on PHP temp path in media upload (STG-03); client profile image purge if any separate path.

---

## Tier summary

| Tier | Items |
|------|--------|
| A (wave 01) | Media upload quarantine; document store; marketing purge/legacy cleanup; worker root env parity |
| A (wave 02) | Dispatcher processed static serve; authenticated document **download** streaming via provider |
| A (wave 03) | **`computeSha256HexForKey`** for document + media staging checksums; **`validateFromStream`**; marketing variant purge without `localFilesystemPathFor` probe |
| B | `getimagesize($tmpPath)` in media upload; any remaining ad hoc `base_path('storage` / `public/media` outside Tier A |
| C | Broader GDPR/backup runbooks tied to storage driver |
