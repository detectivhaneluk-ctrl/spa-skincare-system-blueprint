<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\Database;
use Core\Errors\AccessDeniedException;
use Core\Errors\SafeDomainException;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Modules\Clients\Repositories\ClientProfileImageRepository;
use Modules\Marketing\Services\MarketingGiftCardTemplateService;
use Modules\Media\Services\MediaAssetUploadService;
use Modules\Media\Services\MediaImageLibraryStatusPayloadBuilder;

/**
 * FOUNDATION-A5: Pilot-lane rewrite.
 * - No direct db->fetchOne / fetchAll / query for protected operations (FOUNDATION-A3).
 * - All protected data access through canonical TenantContext-scoped repository methods (FOUNDATION-A4).
 * - BranchContext replaced by TenantContext from RequestContextHolder.
 * - Database retained for transaction management ONLY (not for data queries).
 * - Business behavior preserved; architecture replaced.
 *
 * Primary sidebar photo rule: newest active client_profile_images row (by created_at, id) whose linked
 * media_assets.status is ready and a primary media_asset_variants.relative_path exists; otherwise no URL (placeholder).
 */
final class ClientProfileImageService
{
    public function __construct(
        private Database $db,
        private ClientProfileImageRepository $repo,
        private MediaAssetUploadService $mediaUpload,
        private RequestContextHolder $contextHolder,
        private MarketingGiftCardTemplateService $marketingGiftTemplateService,
        private MediaImageLibraryStatusPayloadBuilder $statusPayloadBuilder,
    ) {
    }

    public function isLibraryStorageReady(): bool
    {
        return $this->repo->isTableReady();
    }

    public function isMediaBackedUploadReady(): bool
    {
        return $this->repo->isMediaLibraryReady();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listImages(int $clientId, int $branchId): array
    {
        return $this->enrichRows($this->repo->listActiveForClientInBranch($clientId, $branchId));
    }

    /**
     * @return array{
     *   poll_mode: 'delta'|'full',
     *   removed_image_ids: list<int>,
     *   images: list<array<string,mixed>>,
     *   worker_hint: array<string,mixed>
     * }
     */
    public function buildClientPhotoPollStatusPayload(int $clientId, int $branchId, array $requestedImageIds): array
    {
        $requested = [];
        foreach ($requestedImageIds as $raw) {
            $n = (int) $raw;
            if ($n > 0) {
                $requested[$n] = true;
            }
        }
        $requestedList = array_keys($requested);
        if ($requestedList === []) {
            $full = $this->buildImageLibraryStatusPayload($clientId, $branchId);

            return array_merge($full, [
                'poll_mode' => 'full',
                'removed_image_ids' => [],
            ]);
        }

        return $this->buildClientPhotoPollDeltaPayload($clientId, $branchId, $requestedList);
    }

    /**
     * Polling subset only: does not load the full client image library table.
     *
     * @param list<int> $requestedList
     *
     * @return array{
     *   poll_mode: 'delta',
     *   removed_image_ids: list<int>,
     *   images: list<array<string,mixed>>,
     *   worker_hint: array<string,mixed>
     * }
     */
    private function buildClientPhotoPollDeltaPayload(int $clientId, int $branchId, array $requestedList): array
    {
        if (count($requestedList) > 50) {
            $requestedList = array_slice($requestedList, 0, 50);
        }
        $raw = $this->repo->listActiveEnrichedForClientInBranchByIds($clientId, $branchId, $requestedList);
        $enriched = $this->enrichRows($raw);
        $present = [];
        foreach ($enriched as $row) {
            $rid = (int) ($row['id'] ?? 0);
            if ($rid > 0) {
                $present[$rid] = true;
            }
        }
        $removed = [];
        foreach ($requestedList as $rid) {
            if (!isset($present[$rid])) {
                $removed[] = $rid;
            }
        }
        $built = $this->statusPayloadBuilder->buildForEnrichedImages($enriched);

        return array_merge($built, [
            'poll_mode' => 'delta',
            'removed_image_ids' => $removed,
        ]);
    }

    /**
     * @return array{images:list<array<string,mixed>>, worker_hint:array<string,mixed>}
     */
    public function buildImageLibraryStatusPayload(int $clientId, int $branchId): array
    {
        return $this->statusPayloadBuilder->buildForEnrichedImages($this->listImages($clientId, $branchId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentImageRowById(int $imageId, int $clientId, int $branchId): ?array
    {
        $ctx = $this->requireTenantContext($branchId);
        $row = $this->repo->loadVisibleEnrichedImage($ctx, $imageId, $clientId);
        if ($row === null) {
            return null;
        }

        return $this->enrichImageRow($row);
    }

    public function resolveSidebarPhotoPublicUrl(int $clientId, int $branchId): ?string
    {
        $rel = $this->repo->findLatestReadyPrimaryRelativePathForClient($clientId, $branchId);
        if ($rel === null || $rel === '') {
            return null;
        }

        return '/' . ltrim($rel, '/');
    }

    public function uploadImage(int $branchId, int $clientId, array $file, ?string $title, ?int $userId): int
    {
        $ctx = $this->requireTenantContext($branchId);
        if (!$this->repo->isMediaLibraryReady()) {
            throw new SafeDomainException(
                'PHOTO_LIBRARY_NOT_READY',
                'Photo uploads are not available on this server yet.',
                'media pipeline / client_profile_images not ready',
                409
            );
        }

        $accepted = $this->mediaUpload->acceptUpload($file);
        $assetId = (int) ($accepted['asset_id'] ?? 0);
        if ($assetId <= 0) {
            throw new SafeDomainException(
                'PHOTO_UPLOAD_FAILED',
                'Image upload could not be started.',
                'Media upload did not return an asset id.',
                422
            );
        }

        // Canonical scoped load: repository validates branch ownership via TenantContext.
        $asset = $this->repo->loadUploadedMediaAssetInScope($ctx, $assetId);
        if ($asset === null) {
            throw new SafeDomainException(
                'PHOTO_UPLOAD_FAILED',
                'Uploaded file could not be associated with this branch.',
                'Uploaded media asset branch mismatch',
                422
            );
        }

        $logicalPath = 'media/assets/' . $assetId;

        return $this->transactional(function () use ($branchId, $clientId, $title, $userId, $assetId, $asset, $logicalPath): int {
            return $this->repo->create([
                'branch_id' => $branchId,
                'client_id' => $clientId,
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
     * @return array{flash_type: 'success'|'warning', flash_message: string, filesystem_warnings: list<string>}
     */
    public function softDeleteImage(int $branchId, int $clientId, int $imageId, ?int $userId): array
    {
        $ctx = $this->requireTenantContext($branchId);
        $image = $this->repo->loadVisibleImage($ctx, $imageId, $clientId);
        if ($image === null) {
            throw new SafeDomainException(
                'PHOTO_NOT_FOUND',
                'Photo is already deleted or missing.',
                'softDelete: row missing',
                404
            );
        }
        $mediaAssetId = isset($image['media_asset_id']) && $image['media_asset_id'] !== null && $image['media_asset_id'] !== ''
            ? (int) $image['media_asset_id']
            : 0;

        $this->transactional(function () use ($ctx, $imageId, $clientId, $userId): void {
            $affected = $this->repo->deleteOwned($ctx, $imageId, $clientId, $userId);
            if ($affected < 1) {
                throw new SafeDomainException(
                    'PHOTO_NOT_FOUND',
                    'Photo is already deleted or missing.',
                    'softDelete: no row updated',
                    404
                );
            }
        });

        $fsWarnings = [];
        if ($mediaAssetId > 0) {
            $fsWarnings = $this->marketingGiftTemplateService->purgeOrphanMediaAssetIfUnreferenced(
                $mediaAssetId,
                'deleted_from_client_profile_library'
            );
        }

        $msg = 'Photo removed from this client library.';
        $flashType = 'success';
        if ($fsWarnings !== []) {
            $flashType = 'warning';
            $msg .= ' Some files could not be removed from disk; check server logs.';
        }

        return [
            'flash_type' => $flashType,
            'flash_message' => $msg,
            'filesystem_warnings' => $fsWarnings,
        ];
    }

    /**
     * @param list<array<string,mixed>> $raw
     *
     * @return list<array<string,mixed>>
     */
    private function enrichRows(array $raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            $out[] = $this->enrichImageRow($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     *
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
        ]);
    }

    private function normalizeTitle(string $title): ?string
    {
        $trim = trim($title);
        if ($trim === '') {
            return null;
        }

        return mb_substr($trim, 0, 160);
    }

    /**
     * Obtain TenantContext and assert that the caller-supplied branchId matches the resolved scope.
     * Defense-in-depth: the canonical repo methods use context-derived branch, not the parameter.
     */
    private function requireTenantContext(int $branchId): TenantContext
    {
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        if ($scope['branch_id'] !== $branchId) {
            throw new AccessDeniedException('Branch context does not match the requested branch.');
        }

        return $ctx;
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
