<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class MarketingGiftCardTemplateRepository
{
    private const TABLE_TEMPLATES = 'marketing_gift_card_templates';
    private const TABLE_IMAGES = 'marketing_gift_card_images';

    private ?bool $storageReady = null;

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function isStorageReady(): bool
    {
        if ($this->storageReady !== null) {
            return $this->storageReady;
        }
        $this->storageReady = $this->tableExists(self::TABLE_TEMPLATES) && $this->tableExists(self::TABLE_IMAGES);

        return $this->storageReady;
    }

    public function isMediaPipelinePresent(): bool
    {
        return $this->tableExists('media_assets');
    }

    public function isMediaBridgeReady(): bool
    {
        // Do not cache: repository is a PHP-FPM singleton; a false result before migrations
        // 103/105 would stick until workers restart, blocking uploads after migrate.
        if (!$this->isStorageReady() || !$this->isMediaPipelinePresent()) {
            return false;
        }
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'media_asset_id'",
            [self::TABLE_IMAGES]
        );

        return (int) ($row['c'] ?? 0) >= 1;
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function listActiveTemplatesForBranch(int $branchId, int $limit, int $offset): array
    {
        if (!$this->isStorageReady()) {
            return ['rows' => [], 'total' => 0];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');
        $totalRow = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM marketing_gift_card_templates t
             WHERE t.branch_id = ?
               AND t.deleted_at IS NULL" . $frag['sql'],
            array_merge([$branchId], $frag['params'])
        );
        $rows = $this->db->fetchAll(
            "SELECT t.*,
                    CASE WHEN t.image_id IS NULL THEN 0 ELSE 1 END AS has_image
             FROM marketing_gift_card_templates t
             WHERE t.branch_id = ?
               AND t.deleted_at IS NULL" . $frag['sql'] . "
             ORDER BY t.name ASC, t.id DESC
             LIMIT ? OFFSET ?",
            array_merge([$branchId], $frag['params'], [$limit, $offset])
        );

        return [
            'rows' => $rows,
            'total' => (int) ($totalRow['c'] ?? 0),
        ];
    }

    public function findActiveTemplateForBranch(int $templateId, int $branchId): ?array
    {
        if (!$this->isStorageReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');

        return $this->db->fetchOne(
            "SELECT t.*
             FROM marketing_gift_card_templates t
             WHERE t.id = ?
               AND t.branch_id = ?
               AND t.deleted_at IS NULL" . $frag['sql'] . '
             LIMIT 1',
            array_merge([$templateId, $branchId], $frag['params'])
        );
    }

    public function createTemplate(array $data): int
    {
        $this->assertStorageReady();
        $this->db->insert(self::TABLE_TEMPLATES, $this->normalizeTemplate($data));

        return (int) $this->db->lastInsertId();
    }

    public function updateTemplateInBranch(int $templateId, int $branchId, array $data): void
    {
        $this->assertStorageReady();
        $normalized = $this->normalizeTemplate($data);
        if ($normalized === []) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');
        $assign = [];
        $params = [];
        foreach ($normalized as $k => $v) {
            $assign[] = 't.' . $k . ' = ?';
            $params[] = $v;
        }
        $params[] = $templateId;
        $params[] = $branchId;
        $this->db->query(
            'UPDATE marketing_gift_card_templates t
             SET ' . implode(', ', $assign) . '
             WHERE t.id = ?
               AND t.branch_id = ?
               AND t.deleted_at IS NULL' . $frag['sql'],
            array_merge($params, $frag['params'])
        );
    }

    public function archiveTemplateInBranch(int $templateId, int $branchId, ?int $userId): void
    {
        $this->assertStorageReady();
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');
        $this->db->query(
            "UPDATE marketing_gift_card_templates t
             SET t.deleted_at = NOW(),
                 t.is_active = 0,
                 t.updated_by = ?
             WHERE t.id = ?
               AND t.branch_id = ?
               AND t.deleted_at IS NULL" . $frag['sql'],
            array_merge([$userId, $templateId, $branchId], $frag['params'])
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActiveImagesForBranch(int $branchId, int $limit = 200): array
    {
        if (!$this->isStorageReady()) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        if ($this->isMediaBridgeReady()) {
            return $this->db->fetchAll(
                "SELECT i.*,
                        ma.status AS media_asset_status,
                        ma.organization_id AS media_organization_id,
                        ma.original_filename AS media_original_filename,
                        ma.mime_detected AS media_mime_detected,
                        ma.bytes_original AS media_bytes_original,
                        vp.relative_path AS media_primary_relative_path
                 FROM marketing_gift_card_images i
                 LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
                 LEFT JOIN media_asset_variants vp
                   ON vp.media_asset_id = ma.id AND vp.is_primary = 1
                 WHERE i.branch_id = ?
                   AND i.deleted_at IS NULL" . $frag['sql'] . "
                 ORDER BY i.created_at DESC, i.id DESC
                 LIMIT ?",
                array_merge([$branchId], $frag['params'], [$limit])
            );
        }

        return $this->db->fetchAll(
            "SELECT i.*
             FROM marketing_gift_card_images i
             WHERE i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'] . "
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT ?",
            array_merge([$branchId], $frag['params'], [$limit])
        );
    }

    public function findActiveImageForBranch(int $imageId, int $branchId): ?array
    {
        if (!$this->isStorageReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');

        return $this->db->fetchOne(
            "SELECT i.*
             FROM marketing_gift_card_images i
             WHERE i.id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'] . '
             LIMIT 1',
            array_merge([$imageId, $branchId], $frag['params'])
        );
    }

    public function findActiveSelectableImageForBranch(int $imageId, int $branchId): ?array
    {
        if (!$this->isStorageReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        if ($this->isMediaBridgeReady()) {
            return $this->db->fetchOne(
                "SELECT i.*
                 FROM marketing_gift_card_images i
                 LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
                 WHERE i.id = ?
                   AND i.branch_id = ?
                   AND i.deleted_at IS NULL
                   AND (
                     i.media_asset_id IS NULL
                     OR ma.status = 'ready'
                   )" . $frag['sql'] . '
                 LIMIT 1',
                array_merge([$imageId, $branchId], $frag['params'])
            );
        }

        return $this->findActiveImageForBranch($imageId, $branchId);
    }

    public function createImage(array $data): int
    {
        $this->assertStorageReady();
        $this->db->insert(self::TABLE_IMAGES, $this->normalizeImage($data));

        return (int) $this->db->lastInsertId();
    }

    /**
     * Soft-deletes one active library image row. Returns rows affected (0 if already deleted / race).
     */
    public function softDeleteImageInBranch(int $imageId, int $branchId, ?int $userId): int
    {
        $this->assertStorageReady();
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        $stmt = $this->db->query(
            "UPDATE marketing_gift_card_images i
             SET i.deleted_at = NOW(),
                 i.is_active = 0,
                 i.updated_by = ?
             WHERE i.id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'],
            array_merge([$userId, $imageId, $branchId], $frag['params'])
        );

        return $stmt->rowCount();
    }

    /**
     * Archived templates may still reference a library image; clear image_id before the image is soft-deleted.
     *
     * @return int Rows updated
     */
    public function clearArchivedTemplateImageIdForLibraryImage(int $imageId, int $branchId, ?int $userId): int
    {
        $this->assertStorageReady();
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');
        $stmt = $this->db->query(
            "UPDATE marketing_gift_card_templates t
             SET t.image_id = NULL,
                 t.updated_by = ?
             WHERE t.branch_id = ?
               AND t.image_id = ?
               AND t.deleted_at IS NOT NULL" . $frag['sql'],
            array_merge([$userId, $branchId, $imageId], $frag['params'])
        );

        return $stmt->rowCount();
    }

    public function countActiveImagesByMediaAssetId(int $mediaAssetId): int
    {
        if (!$this->isStorageReady()) {
            return 0;
        }
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM marketing_gift_card_images i
             WHERE i.media_asset_id = ?
               AND i.deleted_at IS NULL",
            [$mediaAssetId]
        );
        $c = (int) ($row['c'] ?? 0);
        if (!$this->tableExists('client_profile_images')) {
            return $c;
        }
        $row2 = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM client_profile_images c
             WHERE c.media_asset_id = ?
               AND c.deleted_at IS NULL",
            [$mediaAssetId]
        );

        return $c + (int) ($row2['c'] ?? 0);
    }

    /**
     * Marks in-flight queue rows as terminal when the corresponding marketing image was deleted
     * and no active marketing image references the asset anymore.
     *
     * @return array{jobs_failed:int,asset_failed:int}
     */
    public function failQueueRowsForDeletedLibraryAsset(int $mediaAssetId, string $reason): array
    {
        $msg = mb_substr($reason, 0, 1900);
        $jobsTarget = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM media_jobs
             WHERE media_asset_id = ?
               AND job_type = ?
               AND status IN ('pending','processing')",
            [$mediaAssetId, \Modules\Media\Services\MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
        );
        $this->db->query(
            "UPDATE media_jobs
             SET status='failed', locked_at=NULL, error_message=?, updated_at=NOW()
             WHERE media_asset_id = ?
               AND job_type = ?
               AND status IN ('pending','processing')",
            [$msg, $mediaAssetId, \Modules\Media\Services\MediaAssetUploadService::JOB_TYPE_PROCESS_PHOTO]
        );
        $jobsFailed = (int) ($jobsTarget['c'] ?? 0);

        $assetTarget = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM media_assets
             WHERE id = ?
               AND status IN ('pending','processing')",
            [$mediaAssetId]
        );
        $this->db->query(
            "UPDATE media_assets
             SET status='failed', updated_at=NOW()
             WHERE id = ?
               AND status IN ('pending','processing')",
            [$mediaAssetId]
        );
        $assetFailed = (int) ($assetTarget['c'] ?? 0);

        return ['jobs_failed' => $jobsFailed, 'asset_failed' => $assetFailed];
    }

    /**
     * Deletes orphan media asset row (if no active marketing image references remain) and returns
     * enough metadata for caller-side filesystem cleanup.
     *
     * @return array{
     *   deleted:bool,
     *   asset_id:int,
     *   organization_id:int|null,
     *   branch_id:int|null,
     *   stored_basename:string|null,
     *   variant_paths:list<string>
     * }
     */
    public function hardDeleteOrphanMediaAssetForLibrary(int $mediaAssetId): array
    {
        $result = [
            'deleted' => false,
            'asset_id' => $mediaAssetId,
            'organization_id' => null,
            'branch_id' => null,
            'stored_basename' => null,
            'variant_paths' => [],
        ];
        if ($mediaAssetId <= 0) {
            return $result;
        }
        if ($this->countActiveImagesByMediaAssetId($mediaAssetId) > 0) {
            return $result;
        }

        $asset = $this->db->fetchOne(
            'SELECT id, organization_id, branch_id, stored_basename FROM media_assets WHERE id = ? LIMIT 1',
            [$mediaAssetId]
        );
        if ($asset === null) {
            return $result;
        }

        $variantRows = $this->db->fetchAll(
            'SELECT relative_path FROM media_asset_variants WHERE media_asset_id = ?',
            [$mediaAssetId]
        );
        $variantPaths = [];
        foreach ($variantRows as $row) {
            $p = isset($row['relative_path']) ? trim((string) $row['relative_path']) : '';
            if ($p !== '') {
                $variantPaths[] = $p;
            }
        }

        $deleteStmt = $this->db->query('DELETE FROM media_assets WHERE id = ? LIMIT 1', [$mediaAssetId]);
        $deleted = $deleteStmt->rowCount() > 0;

        return [
            'deleted' => $deleted,
            'asset_id' => $mediaAssetId,
            'organization_id' => isset($asset['organization_id']) ? (int) $asset['organization_id'] : null,
            'branch_id' => isset($asset['branch_id']) ? (int) $asset['branch_id'] : null,
            'stored_basename' => isset($asset['stored_basename']) ? (string) $asset['stored_basename'] : null,
            'variant_paths' => $variantPaths,
        ];
    }

    public function activeTemplateCountUsingImageInBranch(int $imageId, int $branchId): int
    {
        if (!$this->isStorageReady()) {
            return 0;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('t');
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM marketing_gift_card_templates t
             WHERE t.branch_id = ?
               AND t.image_id = ?
               AND t.deleted_at IS NULL" . $frag['sql'],
            array_merge([$branchId, $imageId], $frag['params'])
        );

        return (int) ($row['c'] ?? 0);
    }

    private function assertStorageReady(): void
    {
        if (!$this->isStorageReady()) {
            throw new \DomainException('Marketing gift card template storage is not initialized. Run migrations.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeTemplate(array $data): array
    {
        $allowed = [
            'branch_id',
            'name',
            'clone_source_template_id',
            'sell_in_store_enabled',
            'sell_online_enabled',
            'image_id',
            'is_active',
            'created_by',
            'updated_by',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeImage(array $data): array
    {
        $allowed = [
            'branch_id',
            'media_asset_id',
            'title',
            'storage_path',
            'filename',
            'mime_type',
            'size_bytes',
            'is_active',
            'created_by',
            'updated_by',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    private function tableExists(string $tableName): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$tableName]
        );

        return isset($row['ok']) && (int) $row['ok'] === 1;
    }
}

