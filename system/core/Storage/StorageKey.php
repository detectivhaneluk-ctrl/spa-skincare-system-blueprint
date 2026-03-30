<?php

declare(strict_types=1);

namespace Core\Storage;

/**
 * Logical storage address: a volume (root contract) plus a POSIX relative path.
 * Object storage backends can map volume + path to a bucket key without exposing local paths to callers.
 */
final class StorageKey
{
    public const VOLUME_DOCUMENTS = 'documents';

    public const VOLUME_MEDIA_QUARANTINE = 'media_quarantine';

    public const VOLUME_PUBLIC = 'public';

    /** Entire `storage/` tree except where a more specific volume applies (legacy paths). */
    public const VOLUME_STORAGE = 'storage';

    private function __construct(
        private readonly string $volume,
        private readonly string $relativePosixPath,
    ) {
    }

    public function volume(): string
    {
        return $this->volume;
    }

    /** Relative path using forward slashes, no leading slash. */
    public function relativePosixPath(): string
    {
        return $this->relativePosixPath;
    }

    public static function documents(string $relativeUnderDocuments): self
    {
        return new self(self::VOLUME_DOCUMENTS, self::normalizeRelativeOrThrow($relativeUnderDocuments));
    }

    public static function mediaQuarantine(int $organizationId, int $branchId, string $leafName): self
    {
        if ($organizationId <= 0 || $branchId <= 0) {
            throw new \InvalidArgumentException('Quarantine path requires positive organization and branch ids.');
        }
        $leaf = self::normalizeLeafOrThrow($leafName);

        return new self(self::VOLUME_MEDIA_QUARANTINE, $organizationId . '/' . $branchId . '/' . $leaf);
    }

    public static function publicMedia(string $relativeUnderPublic): self
    {
        return new self(self::VOLUME_PUBLIC, self::normalizeRelativeOrThrow($relativeUnderPublic));
    }

    public static function storageSubtree(string $relativeUnderStorage): self
    {
        return new self(self::VOLUME_STORAGE, self::normalizeRelativeOrThrow($relativeUnderStorage));
    }

    /**
     * DB column `storage_path` for documents module: must start with `storage/documents/`.
     */
    public static function fromDocumentsModuleStoragePath(string $storagePathRelative): ?self
    {
        $rel = str_replace('\\', '/', trim($storagePathRelative));
        $prefix = 'storage/documents/';
        if ($rel === '' || !str_starts_with($rel, $prefix)) {
            return null;
        }
        $tail = substr($rel, strlen($prefix));
        if ($tail === '') {
            return null;
        }

        try {
            return self::documents($tail);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function normalizeLeafOrThrow(string $leaf): string
    {
        $leaf = trim($leaf);
        if ($leaf === '' || str_contains($leaf, '/') || str_contains($leaf, '\\')) {
            throw new \InvalidArgumentException('Invalid storage leaf name.');
        }
        if ($leaf === '.' || $leaf === '..') {
            throw new \InvalidArgumentException('Invalid storage leaf name.');
        }

        return $leaf;
    }

    private static function normalizeRelativeOrThrow(string $relative): string
    {
        $rel = trim(str_replace('\\', '/', $relative));
        $rel = ltrim($rel, '/');
        if ($rel === '') {
            throw new \InvalidArgumentException('Storage relative path is empty.');
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Storage relative path contains invalid segments.');
            }
        }

        return $rel;
    }
}
