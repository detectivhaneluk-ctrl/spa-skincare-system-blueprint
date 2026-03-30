<?php

declare(strict_types=1);

namespace Core\Storage\Contracts;

use Core\Storage\StorageKey;

/**
 * Runtime storage contract. Drivers: local filesystem ({@see \Core\Storage\LocalFilesystemStorageProvider}),
 * S3-compatible object storage ({@see \Core\Storage\S3CompatibleObjectStorageProvider}) when `storage.driver=s3_compatible`.
 *
 * **Serving / streaming (wave 02):** Prefer {@see self::readStreamToOutput()} for HTTP bodies so callers do not assume
 * a host-local path.
 * **Checksums (wave 03):** Prefer {@see self::computeSha256HexForKey()} over {@see self::localFilesystemPathFor()} + `hash_file`.
 * Use {@see self::localFilesystemPathFor()} only when {@see self::supportsPublicFilesystemPath()} is true and no stream/hash API fits.
 */
interface StorageProviderInterface
{
    public function driverName(): string;

    /**
     * True when {@see self::computeSha256HexForKey()} works for stored keys (stream-based hash internally).
     */
    public function supportsContentHashing(): bool;

    /**
     * SHA-256 hex digest of stored object bytes. Uses read streaming; does not expose paths to callers.
     *
     * @throws \RuntimeException when unsupported, unreadable, or hashing fails
     */
    public function computeSha256HexForKey(StorageKey $key): string;

    /**
     * True when this driver can expose a verified absolute filesystem path for readable files (single-node local disk).
     * Object/CDN drivers should return false; callers must use {@see self::openReadStream()} / {@see self::readStreamToOutput()}.
     */
    public function supportsPublicFilesystemPath(): bool;

    /**
     * Resolved absolute path when {@see self::supportsPublicFilesystemPath()} is true and the object exists as a readable file; null otherwise.
     */
    public function resolvePublicFilesystemPathIfSupported(StorageKey $key): ?string;

    /**
     * Direct browser/CDN URL for this object when applicable. Local driver returns null (URLs are app routes, not storage-signed).
     */
    public function resolvePublicUrl(StorageKey $key): ?string;

    /**
     * @return resource Binary read stream; caller must fclose(). Throws if unreadable.
     */
    public function openReadStream(StorageKey $key);

    /**
     * Write object bytes to the current output buffer (e.g. HTTP response body). Does not set headers.
     */
    public function readStreamToOutput(StorageKey $key): void;

    /**
     * Absolute path for local I/O. Throws if the key does not denote a readable file under the volume root.
     */
    public function localFilesystemPathFor(StorageKey $key): string;

    public function fileExists(StorageKey $key): bool;

    public function isDirectory(StorageKey $key): bool;

    public function isReadableFile(StorageKey $key): bool;

    public function fileSizeOrFail(StorageKey $key): int;

    public function deleteFileIfExists(StorageKey $key): bool;

    public function ensureParentDirectoryExists(StorageKey $key): void;

    /**
     * Consume a temp upload path into the destination key (move_uploaded_file when allowed, else rename/copy).
     */
    public function importLocalFile(string $localSourcePath, StorageKey $destKey, bool $isPhpUploadedFile): void;

    public function renameKey(StorageKey $from, StorageKey $to): void;

    public function copyKeyThenDeleteSource(StorageKey $from, StorageKey $to): void;

    /**
     * @return list<string> warning messages (empty on full success)
     */
    public function deleteDirectoryTree(StorageKey $directoryKey): array;

    /**
     * Delete a directory tree only if it is under the resolved real path of {@see $prefixKey} (same volume).
     *
     * @return list<string>
     */
    public function deletePublicDirectoryTreeIfUnderPrefix(StorageKey $directoryKey, StorageKey $prefixKey): array;

    /**
     * @return list<string> immediate child names (not . or ..); empty if unreadable / not a directory
     */
    public function listImmediateChildNames(StorageKey $directoryKey): array;
}
