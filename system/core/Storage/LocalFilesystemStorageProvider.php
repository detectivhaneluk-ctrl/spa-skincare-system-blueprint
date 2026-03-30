<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\App\Config;
use Core\Storage\Contracts\StorageProviderInterface;

/**
 * Local-only provider: maps {@see StorageKey} volumes to directories under the application `system/` tree.
 */
final class LocalFilesystemStorageProvider implements StorageProviderInterface
{
    private function __construct(
        private readonly string $documentsRoot,
        private readonly string $quarantineRoot,
        private readonly string $publicRoot,
        private readonly string $storageRoot,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $override = $config->get('storage.local.system_root');
        $systemRoot = self::normalizeSystemRoot($override);

        return new self(
            $systemRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents',
            $systemRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine',
            $systemRoot . DIRECTORY_SEPARATOR . 'public',
            $systemRoot . DIRECTORY_SEPARATOR . 'storage',
        );
    }

    private static function normalizeSystemRoot(mixed $override): string
    {
        if (is_string($override)) {
            $t = trim($override);
            if ($t !== '') {
                $resolved = realpath($t);
                if ($resolved === false || !is_dir($resolved)) {
                    throw new \RuntimeException(
                        'storage.local.system_root / STORAGE_LOCAL_SYSTEM_PATH must be an existing directory (application system/ root).'
                    );
                }

                return $resolved;
            }
        }

        if (!defined('SYSTEM_PATH') || SYSTEM_PATH === '') {
            throw new \RuntimeException('SYSTEM_PATH is not defined; cannot resolve local storage roots.');
        }
        $resolved = realpath(SYSTEM_PATH);
        if ($resolved === false) {
            throw new \RuntimeException('SYSTEM_PATH does not resolve to a directory.');
        }

        return $resolved;
    }

    public function driverName(): string
    {
        return 'local_filesystem';
    }

    public function supportsContentHashing(): bool
    {
        return true;
    }

    public function computeSha256HexForKey(StorageKey $key): string
    {
        $ctx = hash_init('sha256');
        $h = $this->openReadStream($key);
        try {
            $n = hash_update_stream($ctx, $h);
            if ($n === false) {
                throw new \RuntimeException('Failed to hash storage object.');
            }
        } finally {
            fclose($h);
        }
        $hex = hash_final($ctx);
        if (!is_string($hex) || strlen($hex) !== 64) {
            throw new \RuntimeException('Failed to finalize storage hash.');
        }

        return $hex;
    }

    public function supportsPublicFilesystemPath(): bool
    {
        return true;
    }

    public function resolvePublicFilesystemPathIfSupported(StorageKey $key): ?string
    {
        if (!$this->isReadableFile($key)) {
            return null;
        }
        try {
            return $this->localFilesystemPathFor($key);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function resolvePublicUrl(StorageKey $key): ?string
    {
        return null;
    }

    public function openReadStream(StorageKey $key)
    {
        $path = $this->localFilesystemPathFor($key);
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Failed to open storage object for reading.');
        }

        return $h;
    }

    public function readStreamToOutput(StorageKey $key): void
    {
        $h = $this->openReadStream($key);
        try {
            fpassthru($h);
        } finally {
            fclose($h);
        }
    }

    public function localFilesystemPathFor(StorageKey $key): string
    {
        $abs = $this->absolutePath($key);
        $rootReal = realpath($this->rootFor($key->volume()));
        if ($rootReal === false) {
            throw new \RuntimeException('Storage volume root is not accessible.');
        }
        $fileReal = realpath($abs);
        if ($fileReal === false || !is_file($fileReal)) {
            throw new \RuntimeException('Document not found.');
        }
        if (!$this->isStrictPathUnderRoot($fileReal, $rootReal)) {
            throw new \RuntimeException('Storage path escaped volume root.');
        }

        return $fileReal;
    }

    public function fileExists(StorageKey $key): bool
    {
        return is_file($this->absolutePath($key));
    }

    public function isDirectory(StorageKey $key): bool
    {
        return is_dir($this->absolutePath($key));
    }

    public function isReadableFile(StorageKey $key): bool
    {
        $abs = $this->absolutePath($key);

        return is_file($abs) && is_readable($abs);
    }

    public function fileSizeOrFail(StorageKey $key): int
    {
        $path = $this->localFilesystemPathFor($key);
        $sz = filesize($path);
        if ($sz === false) {
            throw new \RuntimeException('Document not found.');
        }

        return (int) $sz;
    }

    public function deleteFileIfExists(StorageKey $key): bool
    {
        $abs = $this->absolutePath($key);
        if (!is_file($abs)) {
            return false;
        }
        $rootReal = realpath($this->rootFor($key->volume()));
        if ($rootReal === false) {
            return false;
        }
        $fileReal = realpath($abs);
        if ($fileReal === false || !$this->isStrictPathUnderRoot($fileReal, $rootReal)) {
            return false;
        }

        return @unlink($fileReal);
    }

    public function ensureParentDirectoryExists(StorageKey $key): void
    {
        $abs = $this->absolutePath($key);
        $parent = dirname($abs);
        if (is_dir($parent)) {
            return;
        }
        if (!@mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new \RuntimeException('Failed to create storage directory.');
        }
    }

    public function importLocalFile(string $localSourcePath, StorageKey $destKey, bool $isPhpUploadedFile): void
    {
        if (!is_file($localSourcePath)) {
            throw new \RuntimeException('Source file is missing.');
        }
        $this->ensureParentDirectoryExists($destKey);
        $destAbs = $this->absolutePath($destKey);
        if (is_file($destAbs)) {
            @unlink($destAbs);
        }
        if ($isPhpUploadedFile && is_uploaded_file($localSourcePath)) {
            if (!@move_uploaded_file($localSourcePath, $destAbs)) {
                throw new \RuntimeException('Failed to store upload.');
            }
        } elseif (!@rename($localSourcePath, $destAbs)) {
            if (!@copy($localSourcePath, $destAbs)) {
                throw new \RuntimeException('Failed to store uploaded file.');
            }
        }
        if (!is_file($destAbs)) {
            throw new \RuntimeException('Stored file not found after write.');
        }
    }

    public function renameKey(StorageKey $from, StorageKey $to): void
    {
        $fromAbs = $this->absolutePath($from);
        $toAbs = $this->absolutePath($to);
        $this->ensureParentDirectoryExists($to);
        if (!@rename($fromAbs, $toAbs)) {
            throw new \RuntimeException('Failed to rename storage object.');
        }
    }

    public function copyKeyThenDeleteSource(StorageKey $from, StorageKey $to): void
    {
        $fromAbs = $this->absolutePath($from);
        $toAbs = $this->absolutePath($to);
        if (!is_file($fromAbs)) {
            throw new \RuntimeException('Source storage file is missing.');
        }
        $this->ensureParentDirectoryExists($to);
        if (!@copy($fromAbs, $toAbs)) {
            throw new \RuntimeException('Failed to copy storage object.');
        }
        if (!@unlink($fromAbs)) {
            throw new \RuntimeException('Failed to remove source after copy.');
        }
    }

    public function deleteDirectoryTree(StorageKey $directoryKey): array
    {
        $dirAbs = $this->absolutePath($directoryKey);
        $rootReal = realpath($this->rootFor($directoryKey->volume()));
        if ($rootReal === false) {
            return ['Storage volume root is not accessible.'];
        }
        $dirReal = realpath($dirAbs);
        if ($dirReal === false || !is_dir($dirReal)) {
            return [];
        }
        if (!$this->isStrictPathUnderRoot($dirReal, $rootReal) && $dirReal !== $rootReal) {
            return ['Refusing directory delete outside allowed root: ' . $dirAbs];
        }

        return $this->recursiveDeleteDirectoryContents($dirReal);
    }

    public function deletePublicDirectoryTreeIfUnderPrefix(StorageKey $directoryKey, StorageKey $prefixKey): array
    {
        if ($directoryKey->volume() !== StorageKey::VOLUME_PUBLIC || $prefixKey->volume() !== StorageKey::VOLUME_PUBLIC) {
            return ['deletePublicDirectoryTreeIfUnderPrefix requires public volume keys.'];
        }
        $prefixAbs = $this->absolutePath($prefixKey);
        $dirAbs = $this->absolutePath($directoryKey);
        $prefixReal = realpath($prefixAbs);
        if ($prefixReal === false || !is_dir($prefixReal)) {
            return [];
        }
        $dirReal = realpath($dirAbs);
        if ($dirReal === false || !is_dir($dirReal)) {
            return [];
        }
        $prefixNorm = rtrim(str_replace('\\', '/', $prefixReal), '/');
        $dirNorm = str_replace('\\', '/', $dirReal);
        if (!str_starts_with($dirNorm, $prefixNorm . '/') && $dirNorm !== $prefixNorm) {
            return ['Refusing directory delete outside allowed root: ' . $dirAbs];
        }

        return $this->recursiveDeleteDirectoryContents($dirReal);
    }

    public function listImmediateChildNames(StorageKey $directoryKey): array
    {
        $dirAbs = $this->absolutePath($directoryKey);
        if (!is_dir($dirAbs)) {
            return [];
        }
        $names = scandir($dirAbs);
        if ($names === false) {
            return [];
        }
        $out = [];
        foreach ($names as $n) {
            if ($n !== '.' && $n !== '..') {
                $out[] = $n;
            }
        }

        return $out;
    }

    private function rootFor(string $volume): string
    {
        return match ($volume) {
            StorageKey::VOLUME_DOCUMENTS => $this->documentsRoot,
            StorageKey::VOLUME_MEDIA_QUARANTINE => $this->quarantineRoot,
            StorageKey::VOLUME_PUBLIC => $this->publicRoot,
            StorageKey::VOLUME_STORAGE => $this->storageRoot,
            default => throw new \InvalidArgumentException('Unknown storage volume: ' . $volume),
        };
    }

    private function absolutePath(StorageKey $key): string
    {
        $root = $this->rootFor($key->volume());
        $tail = str_replace('/', DIRECTORY_SEPARATOR, $key->relativePosixPath());

        return $root . DIRECTORY_SEPARATOR . $tail;
    }

    private function isStrictPathUnderRoot(string $pathReal, string $rootReal): bool
    {
        $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
        $pathNorm = str_replace('\\', '/', $pathReal);

        return $pathNorm === $rootNorm || str_starts_with($pathNorm, $rootNorm . '/');
    }

    /**
     * @return list<string>
     */
    private function recursiveDeleteDirectoryContents(string $dirReal): array
    {
        $warnings = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fileInfo) {
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                if (!@rmdir($path)) {
                    $warnings[] = 'rmdir failed: ' . $path;
                }
            } elseif (!@unlink($path)) {
                $warnings[] = 'unlink failed: ' . $path;
            }
        }
        if (!@rmdir($dirReal)) {
            $warnings[] = 'rmdir(final) failed: ' . $dirReal;
        }

        return $warnings;
    }
}
