<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Storage\Contracts\StorageProviderInterface;
use Core\Storage\StorageKey;
use Modules\Marketing\Repositories\MarketingGiftCardTemplateRepository;
use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaImageLibraryStatusPayloadBuilder;

final class MarketingGiftCardTemplateService
{
    private const GC_IMAGE_DELETE_LOG_PREFIX = '[gc-image-delete]';

    public function __construct(
        private Database $db,
        private MarketingGiftCardTemplateRepository $repo,
        private MediaAssetUploadService $mediaUpload,
        private BranchContext $branchContext,
        private MediaImageLibraryStatusPayloadBuilder $statusPayloadBuilder,
        private StorageProviderInterface $storage,
    ) {
    }

    public function isStorageReady(): bool
    {
        return $this->repo->isStorageReady();
    }

    /**
     * Gift-card image uploads require migration 105 + media pipeline tables (103).
     */
    public function isMediaBackedImageUploadReady(): bool
    {
        return $this->repo->isMediaBridgeReady();
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function listTemplatesForIndex(int $branchId, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $result = $this->repo->listActiveTemplatesForBranch($branchId, $limit, $offset);
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'sell_in_store_enabled' => (bool) ($row['sell_in_store_enabled'] ?? false),
                'sell_online_enabled' => (bool) ($row['sell_online_enabled'] ?? false),
                'is_editable' => true,
                'is_deletable' => true,
                'has_image' => (bool) ($row['has_image'] ?? false),
            ];
        }

        return [
            'rows' => $rows,
            'total' => (int) ($result['total'] ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCloneCandidates(int $branchId): array
    {
        return $this->repo->listActiveTemplatesForBranch($branchId, 500, 0)['rows'] ?? [];
    }

    public function createTemplateFromRequest(
        int $branchId,
        string $name,
        ?int $cloneSourceTemplateId,
        ?int $userId
    ): int {
        $this->assertName($name);
        if ($cloneSourceTemplateId !== null && $cloneSourceTemplateId <= 0) {
            $cloneSourceTemplateId = null;
        }

        $payload = [
            'branch_id' => $branchId,
            'name' => trim($name),
            'clone_source_template_id' => null,
            'sell_in_store_enabled' => 1,
            'sell_online_enabled' => 1,
            'image_id' => null,
            'is_active' => 1,
            'created_by' => $userId,
            'updated_by' => $userId,
        ];

        if ($cloneSourceTemplateId !== null) {
            $source = $this->repo->findActiveTemplateForBranch($cloneSourceTemplateId, $branchId);
            if ($source === null) {
                throw new \DomainException('Clone source template not found in this branch.');
            }
            $payload['clone_source_template_id'] = (int) ($source['id'] ?? null);
            $payload['sell_in_store_enabled'] = !empty($source['sell_in_store_enabled']) ? 1 : 0;
            $payload['sell_online_enabled'] = !empty($source['sell_online_enabled']) ? 1 : 0;
            $payload['image_id'] = isset($source['image_id']) && $source['image_id'] !== null ? (int) $source['image_id'] : null;
        }

        return $this->repo->createTemplate($payload);
    }

    public function findTemplateForEdit(int $branchId, int $templateId): ?array
    {
        return $this->repo->findActiveTemplateForBranch($templateId, $branchId);
    }

    public function updateTemplateMetadata(
        int $branchId,
        int $templateId,
        string $name,
        bool $sellInStore,
        bool $sellOnline,
        ?int $imageId,
        ?int $userId
    ): void {
        $this->assertName($name);
        $template = $this->repo->findActiveTemplateForBranch($templateId, $branchId);
        if ($template === null) {
            throw new \DomainException('Gift card template not found.');
        }
        $currentImageId = isset($template['image_id']) && $template['image_id'] !== null ? (int) $template['image_id'] : null;
        if ($imageId !== null && $imageId > 0) {
            if ($currentImageId !== $imageId) {
                $image = $this->repo->findActiveSelectableImageForBranch($imageId, $branchId);
                if ($image === null) {
                    throw new \DomainException('Selected image is not available for templates (still processing, failed, or not found in this branch).');
                }
            }
        } else {
            $imageId = null;
        }
        $this->repo->updateTemplateInBranch($templateId, $branchId, [
            'name' => trim($name),
            'sell_in_store_enabled' => $sellInStore ? 1 : 0,
            'sell_online_enabled' => $sellOnline ? 1 : 0,
            'image_id' => $imageId,
            'updated_by' => $userId,
        ]);
    }

    public function archiveTemplate(int $branchId, int $templateId, ?int $userId): void
    {
        $template = $this->repo->findActiveTemplateForBranch($templateId, $branchId);
        if ($template === null) {
            throw new \DomainException('Gift card template is already archived or missing.');
        }
        $this->repo->archiveTemplateInBranch($templateId, $branchId, $userId);
    }

    /**
     * Full library view: legacy rows, media-backed pending/processing/ready/failed.
     *
     * @return list<array<string,mixed>>
     */
    public function listImages(int $branchId): array
    {
        $raw = $this->repo->listActiveImagesForBranch($branchId);
        $out = [];
        foreach ($raw as $row) {
            $out[] = $this->enrichImageRow($row);
        }

        return $out;
    }

    /**
     * JSON payload for /marketing/gift-card-templates/images/status: library rows + queue truth + worker hint.
     *
     * @return array{images:list<array<string,mixed>>, worker_hint:array<string,mixed>}
     */
    public function buildImageLibraryStatusPayload(int $branchId): array
    {
        return $this->statusPayloadBuilder->buildForEnrichedImages($this->listImages($branchId));
    }

    /**
     * Images that may be assigned as a template primary (ready media or legacy direct file).
     *
     * @return list<array<string,mixed>>
     */
    public function listSelectableTemplateImages(int $branchId): array
    {
        $all = $this->listImages($branchId);
        $out = [];
        foreach ($all as $row) {
            if (!empty($row['selectable_for_template'])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Selectable images plus the template's current image when it is not yet ready (honest display, stable saves).
     *
     * @return list<array<string,mixed>>
     */
    public function listImagesForTemplateEditForm(int $branchId, ?int $currentTemplateImageId): array
    {
        $selectable = $this->listSelectableTemplateImages($branchId);
        if ($currentTemplateImageId === null || $currentTemplateImageId <= 0) {
            return $selectable;
        }
        foreach ($selectable as $r) {
            if ((int) ($r['id'] ?? 0) === $currentTemplateImageId) {
                return $selectable;
            }
        }
        foreach ($this->listImages($branchId) as $r) {
            if ((int) ($r['id'] ?? 0) === $currentTemplateImageId) {
                return array_merge([$r], $selectable);
            }
        }

        return $selectable;
    }

    public function uploadImage(int $branchId, array $file, ?string $title, ?int $userId): int
    {
        $this->assertCurrentBranchMatches($branchId);
        if (!$this->repo->isMediaBridgeReady()) {
            throw new \DomainException('Gift card image uploads require migration 105 (media bridge) and migration 103 (media pipeline).');
        }

        $accepted = $this->mediaUpload->acceptUpload($file);
        $assetId = (int) ($accepted['asset_id'] ?? 0);
        if ($assetId <= 0) {
            throw new \DomainException('Media upload did not return an asset id.');
        }

        $asset = $this->db->fetchOne(
            'SELECT id, branch_id, original_filename, stored_basename, mime_detected, bytes_original
             FROM media_assets WHERE id = ? LIMIT 1',
            [$assetId]
        );
        if ($asset === null || (int) ($asset['branch_id'] ?? 0) !== $branchId) {
            throw new \DomainException('Uploaded media asset is not in this branch.');
        }

        $logicalPath = 'media/assets/' . $assetId;

        return $this->transactional(function () use ($branchId, $title, $userId, $assetId, $asset, $logicalPath): int {
            return $this->repo->createImage([
                'branch_id' => $branchId,
                'media_asset_id' => $assetId,
                'title' => $title !== null ? $this->normalizeTitle($title) : null,
                'storage_path' => $logicalPath,
                'filename' => (string) ($asset['stored_basename'] ?? ''),
                'mime_type' => (string) ($asset['mime_detected'] ?? 'application/octet-stream'),
                'size_bytes' => (int) ($asset['bytes_original'] ?? 0),
                'is_active' => 1,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        });
    }

    /**
     * Soft-deletes a gift-card library image, clears archived template pointers, purges orphan media assets, and cleans disk.
     *
     * @return array{
     *   flash_type: 'success'|'warning',
     *   flash_message: string,
     *   archived_templates_cleared: int,
     *   filesystem_warnings: list<string>,
     * }
     */
    public function softDeleteImage(int $branchId, int $imageId, ?int $userId): array
    {
        $image = $this->repo->findActiveImageForBranch($imageId, $branchId);
        if ($image === null) {
            throw new \DomainException('Image is already deleted or missing.');
        }
        $inUseCount = $this->repo->activeTemplateCountUsingImageInBranch($imageId, $branchId);
        if ($inUseCount > 0) {
            throw new \DomainException('Image is currently used by active templates. Replace template images first.');
        }
        $mediaAssetId = isset($image['media_asset_id']) && $image['media_asset_id'] !== null && $image['media_asset_id'] !== ''
            ? (int) $image['media_asset_id']
            : 0;
        $legacySnapshot = [
            'storage_path' => isset($image['storage_path']) ? (string) $image['storage_path'] : '',
            'filename' => isset($image['filename']) ? (string) $image['filename'] : '',
        ];
        $purgeMeta = null;
        $archivedCleared = 0;
        $this->transactional(function () use ($imageId, $branchId, $userId, $mediaAssetId, &$purgeMeta, &$archivedCleared): void {
            $archivedCleared = $this->repo->clearArchivedTemplateImageIdForLibraryImage($imageId, $branchId, $userId);
            $affected = $this->repo->softDeleteImageInBranch($imageId, $branchId, $userId);
            if ($affected < 1) {
                throw new \DomainException('Image is already deleted or missing.');
            }
            if ($mediaAssetId <= 0) {
                return;
            }
            $activeRefs = $this->repo->countActiveImagesByMediaAssetId($mediaAssetId);
            if ($activeRefs > 0) {
                return;
            }
            $this->repo->failQueueRowsForDeletedLibraryAsset(
                $mediaAssetId,
                'deleted_from_marketing_library'
            );
            $purgeMeta = $this->repo->hardDeleteOrphanMediaAssetForLibrary($mediaAssetId);
        });

        $fsWarnings = [];
        if ($mediaAssetId <= 0) {
            $legacyResult = $this->cleanupLegacyLibraryImageFiles($legacySnapshot);
            $fsWarnings = $legacyResult['warnings'];
            if ($legacyResult['note'] !== '') {
                $this->logGcImageDelete($legacyResult['note']);
            }
        } elseif (is_array($purgeMeta) && !empty($purgeMeta['deleted'])) {
            $fsWarnings = $this->cleanupDeletedMediaAssetFiles($purgeMeta);
        }

        foreach ($fsWarnings as $w) {
            $this->logGcImageDelete($w);
        }

        $msg = 'Image deleted from the library.';
        if ($archivedCleared > 0) {
            $msg .= ' Cleared image reference from ' . $archivedCleared . ' archived template(s).';
        }
        $flashType = 'success';
        if ($fsWarnings !== []) {
            $flashType = 'warning';
            $msg .= ' Some files could not be removed from disk; details are in the server log (' . self::GC_IMAGE_DELETE_LOG_PREFIX . ').';
        }

        return [
            'flash_type' => $flashType,
            'flash_message' => $msg,
            'archived_templates_cleared' => $archivedCleared,
            'filesystem_warnings' => $fsWarnings,
        ];
    }

    /**
     * When no active library rows (gift card or client profile) reference the asset, fail queue jobs, delete the media row, and clean filesystem.
     *
     * @return list<string> filesystem warnings
     */
    public function purgeOrphanMediaAssetIfUnreferenced(int $mediaAssetId, string $queueFailReason): array
    {
        if ($mediaAssetId <= 0) {
            return [];
        }
        if ($this->repo->countActiveImagesByMediaAssetId($mediaAssetId) > 0) {
            return [];
        }
        $this->repo->failQueueRowsForDeletedLibraryAsset($mediaAssetId, $queueFailReason);
        $purgeMeta = $this->repo->hardDeleteOrphanMediaAssetForLibrary($mediaAssetId);
        $warnings = [];
        if (is_array($purgeMeta) && !empty($purgeMeta['deleted'])) {
            $warnings = $this->cleanupDeletedMediaAssetFiles($purgeMeta);
            foreach ($warnings as $w) {
                $this->logGcImageDelete($w);
            }
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $purgeMeta
     *
     * @return list<string>
     */
    private function cleanupDeletedMediaAssetFiles(array $purgeMeta): array
    {
        $warnings = [];
        $orgId = isset($purgeMeta['organization_id']) ? (int) $purgeMeta['organization_id'] : 0;
        $branchId = isset($purgeMeta['branch_id']) ? (int) $purgeMeta['branch_id'] : 0;
        $assetId = isset($purgeMeta['asset_id']) ? (int) $purgeMeta['asset_id'] : 0;
        $storedBasename = isset($purgeMeta['stored_basename']) ? trim((string) $purgeMeta['stored_basename']) : '';

        $processedPrefixKey = StorageKey::publicMedia('media/processed');
        if (!$this->storage->isDirectory($processedPrefixKey)) {
            $warnings[] = 'Processed media root missing or unreadable: media/processed';
        }

        if ($orgId > 0 && $branchId > 0 && $storedBasename !== '') {
            $safeBase = basename($storedBasename);
            if ($safeBase !== '' && $safeBase === $storedBasename) {
                foreach ([$safeBase, $safeBase . '.incoming'] as $leaf) {
                    try {
                        $qk = StorageKey::mediaQuarantine($orgId, $branchId, $leaf);
                    } catch (\InvalidArgumentException) {
                        $warnings[] = 'Skipped quarantine cleanup: invalid leaf.';

                        continue;
                    }
                    if (!$this->storage->fileExists($qk)) {
                        continue;
                    }
                    if (!$this->storage->deleteFileIfExists($qk)) {
                        $warnings[] = 'Quarantine unlink failed: ' . $leaf;
                    }
                }
            } else {
                $warnings[] = 'Skipped quarantine cleanup: invalid stored_basename.';
            }
        }

        $variantPaths = is_array($purgeMeta['variant_paths'] ?? null) ? $purgeMeta['variant_paths'] : [];
        foreach ($variantPaths as $relPathRaw) {
            $relPath = trim((string) $relPathRaw);
            if ($relPath === '' || str_contains($relPath, '..')) {
                continue;
            }
            $normRel = str_replace('\\', '/', ltrim($relPath, '/'));
            try {
                $vk = StorageKey::publicMedia($normRel);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!$this->storage->isReadableFile($vk)) {
                continue;
            }
            if (!$this->storage->deleteFileIfExists($vk)) {
                $warnings[] = 'Variant unlink failed: ' . $normRel;
            }
        }

        if ($orgId > 0 && $branchId > 0 && $assetId > 0 && $this->storage->isDirectory($processedPrefixKey)) {
            $assetDirKey = StorageKey::publicMedia('media/processed/' . $orgId . '/' . $branchId . '/' . $assetId);
            $warnings = array_merge($warnings, $this->storage->deletePublicDirectoryTreeIfUnderPrefix($assetDirKey, $processedPrefixKey));
            $warnings = array_merge($warnings, $this->removeWorkerStagingDirsForAsset($orgId, $branchId, $assetId));
        }

        return $warnings;
    }

    /**
     * @param array{storage_path: string, filename: string} $legacySnapshot
     *
     * @return array{warnings: list<string>, note: string}
     */
    private function cleanupLegacyLibraryImageFiles(array $legacySnapshot): array
    {
        $warnings = [];
        $path = $legacySnapshot['storage_path'];
        $key = $this->legacyLibraryStorageKey($path);
        if ($key === null) {
            return [
                'warnings' => [],
                'note' => 'Legacy image has no resolvable on-disk path from storage_path; skipped filesystem cleanup.',
            ];
        }
        if (!$this->storage->fileExists($key)) {
            return ['warnings' => [], 'note' => ''];
        }
        if (!$this->storage->deleteFileIfExists($key)) {
            $warnings[] = 'Legacy file unlink failed for storage_path.';
        }

        return ['warnings' => $warnings, 'note' => ''];
    }

    private function legacyLibraryStorageKey(string $storagePath): ?StorageKey
    {
        $p = trim($storagePath);
        if ($p === '' || str_contains($p, '..')) {
            return null;
        }
        $norm = str_replace('\\', '/', ltrim($p, '/'));
        if (preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./-]*$#', $norm) !== 1) {
            return null;
        }
        try {
            if (str_starts_with($norm, 'storage/')) {
                $tail = substr($norm, strlen('storage/'));

                return $tail !== '' ? StorageKey::storageSubtree($tail) : null;
            }
            if (str_starts_with($norm, 'media/processed/') || str_starts_with($norm, 'media/assets/')) {
                return StorageKey::publicMedia($norm);
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function removeWorkerStagingDirsForAsset(int $orgId, int $branchId, int $assetId): array
    {
        $warnings = [];
        $processedPrefixKey = StorageKey::publicMedia('media/processed');
        $parentKey = StorageKey::publicMedia('media/processed/' . $orgId . '/' . $branchId);
        if (!$this->storage->isDirectory($parentKey)) {
            return $warnings;
        }
        $pattern = '/^__stg_' . preg_quote((string) $assetId, '/') . '_\d+$/';
        foreach ($this->storage->listImmediateChildNames($parentKey) as $name) {
            if (preg_match($pattern, $name) !== 1) {
                continue;
            }
            $stgKey = StorageKey::publicMedia('media/processed/' . $orgId . '/' . $branchId . '/' . $name);
            if (!$this->storage->isDirectory($stgKey)) {
                continue;
            }
            $warnings = array_merge($warnings, $this->storage->deletePublicDirectoryTreeIfUnderPrefix($stgKey, $processedPrefixKey));
        }

        return $warnings;
    }

    private function logGcImageDelete(string $message): void
    {
        slog('warning', 'marketing.gift_card_template.image_delete', $message, ['legacy_prefix' => self::GC_IMAGE_DELETE_LOG_PREFIX]);
    }

    private function assertName(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Template name is required.');
        }
        if (mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('Template name must be 160 characters or fewer.');
        }
    }

    private function normalizeTitle(string $title): ?string
    {
        $trim = trim($title);
        if ($trim === '') {
            return null;
        }

        return mb_substr($trim, 0, 160);
    }

    private function assertCurrentBranchMatches(int $branchId): void
    {
        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx === null || (int) $ctx !== $branchId) {
            throw new \DomainException('Branch context does not match the requested branch.');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function enrichImageRow(array $row): array
    {
        $mediaAssetId = array_key_exists('media_asset_id', $row) && $row['media_asset_id'] !== null && $row['media_asset_id'] !== ''
            ? (int) $row['media_asset_id']
            : null;
        if ($mediaAssetId === null || $mediaAssetId <= 0) {
            return array_merge($row, [
                'library_status' => 'legacy',
                'display_filename' => (string) ($row['filename'] ?? ''),
                'display_mime' => (string) ($row['mime_type'] ?? ''),
                'display_size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'public_variant_url' => null,
                'selectable_for_template' => true,
            ]);
        }

        $st = (string) ($row['media_asset_status'] ?? '');
        $libraryStatus = match ($st) {
            'ready' => 'ready',
            'failed' => 'failed',
            'processing' => 'processing',
            default => 'pending',
        };
        $rel = (string) ($row['media_primary_relative_path'] ?? '');
        $publicUrl = ($st === 'ready' && $rel !== '') ? ('/' . ltrim($rel, '/')) : null;
        $orig = (string) ($row['media_original_filename'] ?? '');

        return array_merge($row, [
            'library_status' => $libraryStatus,
            'display_filename' => $orig !== '' ? $orig : (string) ($row['filename'] ?? ''),
            'display_mime' => (string) ($row['media_mime_detected'] ?? $row['mime_type'] ?? ''),
            'display_size_bytes' => isset($row['media_bytes_original']) ? (int) $row['media_bytes_original'] : (int) ($row['size_bytes'] ?? 0),
            'public_variant_url' => $publicUrl,
            'selectable_for_template' => $st === 'ready',
        ]);
    }

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
