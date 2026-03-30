<?php

declare(strict_types=1);

namespace Modules\Media\Services;

use Core\App\Application;
use Core\App\Config;
use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Core\Storage\Contracts\StorageProviderInterface;
use Core\Storage\StorageKey;
use Modules\Media\Repositories\MediaAssetRepository;
use Modules\Media\Repositories\MediaJobRepository;

/**
 * Upload gateway: validate, quarantine on disk, persist pending row, enqueue job row (no re-encode in HTTP request).
 */
final class MediaAssetUploadService
{
    public const JOB_TYPE_PROCESS_PHOTO = 'process_photo_variants_v1';

    public function __construct(
        private Database $db,
        private Config $config,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private MediaImageSignatureValidator $signature,
        private MediaAssetRepository $assets,
        private MediaJobRepository $jobs,
        private MediaUploadWorkerDevTrigger $devWorkerTrigger,
        private StorageProviderInterface $storage,
    ) {
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{asset_id:int,status:string,stored_basename:string}
     */
    public function acceptUpload(array $file): array
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Branch context is required to upload media.');
        }
        $orgId = $this->organizationContext->getCurrentOrganizationId();
        if ($orgId === null || $orgId <= 0) {
            throw new \DomainException('Organization context is required to upload media.');
        }

        $pdo = $this->db->connection();
        if ($pdo->inTransaction()) {
            throw new \DomainException(
                'Media upload cannot run inside an existing database transaction; commit or roll back first, then upload.'
            );
        }

        if (!isset($file['tmp_name'], $file['name'], $file['size']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            throw new \InvalidArgumentException('Image file is required.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $maxBytes = (int) $this->config->get('media.max_upload_bytes', 12582912);
        $size = (int) $file['size'];
        if ($size <= 0 || $size > $maxBytes) {
            throw new \InvalidArgumentException('File exceeds the maximum upload size.');
        }

        $tmpPath = (string) $file['tmp_name'];
        $sigFh = fopen($tmpPath, 'rb');
        if ($sigFh === false) {
            throw new \RuntimeException('Cannot read upload for validation.');
        }
        try {
            $validated = $this->signature->validateFromStream($sigFh, (string) $file['name']);
        } finally {
            fclose($sigFh);
        }
        $ext = $validated['extension'];
        $mime = $validated['mime'];

        $info = @getimagesize($tmpPath);
        if ($info === false || !isset($info[0], $info[1]) || $info[0] < 1 || $info[1] < 1) {
            throw new \InvalidArgumentException('Could not read image dimensions (file may be corrupt).');
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $mp = ($w * $h) / 1_000_000.0;
        $maxMp = (float) $this->config->get('media.max_megapixels', 40.0);
        if ($mp > $maxMp) {
            throw new \InvalidArgumentException('Image exceeds maximum megapixel limit.');
        }

        $uuid = $this->newUuidV4();
        $storedBasename = $uuid . '.' . $ext;
        $stagingKey = StorageKey::mediaQuarantine($orgId, $branchId, $storedBasename . '.incoming');
        $finalKey = StorageKey::mediaQuarantine($orgId, $branchId, $storedBasename);
        $this->storage->ensureParentDirectoryExists($stagingKey);
        if ($this->storage->fileExists($stagingKey)) {
            $this->storage->deleteFileIfExists($stagingKey);
        }
        $this->storage->importLocalFile($tmpPath, $stagingKey, true);
        try {
            $checksum = $this->storage->computeSha256HexForKey($stagingKey);
        } catch (\RuntimeException $e) {
            $this->storage->deleteFileIfExists($stagingKey);
            throw new \RuntimeException('Failed to checksum upload.', 0, $e);
        }
        $bytes = $this->storage->fileSizeOrFail($stagingKey);

        $userId = Application::container()->get(\Core\Auth\SessionAuth::class)->id();

        try {
            $result = $this->transactional(function () use (
                $orgId,
                $branchId,
                $file,
                $mime,
                $w,
                $h,
                $bytes,
                $checksum,
                $userId,
                $storedBasename
            ): array {
                $assetId = $this->assets->insert([
                    'organization_id' => $orgId,
                    'branch_id' => $branchId,
                    'original_filename' => mb_substr((string) $file['name'], 0, 255),
                    'stored_basename' => $storedBasename,
                    'mime_detected' => $mime,
                    'width' => $w,
                    'height' => $h,
                    'bytes_original' => $bytes,
                    'status' => 'pending',
                    'checksum' => $checksum,
                    'created_by' => $userId,
                ]);
                $this->jobs->enqueue($assetId, self::JOB_TYPE_PROCESS_PHOTO);

                return [
                    'asset_id' => $assetId,
                    'status' => 'pending',
                    'stored_basename' => $storedBasename,
                ];
            });
        } catch (\Throwable $e) {
            $this->storage->deleteFileIfExists($stagingKey);
            throw $e;
        }

        try {
            $this->storage->renameKey($stagingKey, $finalKey);
            if (!$this->storage->fileExists($finalKey)) {
                throw new \RuntimeException('Quarantine file missing after finalize.');
            }
        } catch (\Throwable $e) {
            // Rows are already committed; keep staging bytes for operator recovery (do not unlink staging here).
            throw $e;
        }

        $this->devWorkerTrigger->maybeSpawnAfterUpload((int) $result['asset_id']);

        \slog('info', 'critical_path.media', 'media_upload_accepted', [
            'asset_id' => (int) $result['asset_id'],
            'branch_id' => $branchId,
            'organization_id' => $orgId,
        ]);

        return $result;
    }

    private function newUuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $fn();
            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
