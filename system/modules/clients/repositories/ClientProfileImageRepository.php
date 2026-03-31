<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationRepositoryScope;

final class ClientProfileImageRepository
{
    private const TABLE = 'client_profile_images';

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function isTableReady(): bool
    {
        return $this->tableExists(self::TABLE);
    }

    /**
     * Same media bridge expectation as gift-card library rows (migration 103 + media_asset_id column).
     */
    public function isMediaLibraryReady(): bool
    {
        if (!$this->isTableReady() || !$this->tableExists('media_assets')) {
            return false;
        }
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'media_asset_id'",
            [self::TABLE]
        );

        return (int) ($row['c'] ?? 0) >= 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActiveForClientInBranch(int $clientId, int $branchId, int $limit = 200): array
    {
        if (!$this->isTableReady()) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        if ($this->isMediaLibraryReady()) {
            return $this->db->fetchAll(
                "SELECT i.*,
                        ma.status AS media_asset_status,
                        ma.organization_id AS media_organization_id,
                        ma.original_filename AS media_original_filename,
                        ma.mime_detected AS media_mime_detected,
                        ma.bytes_original AS media_bytes_original,
                        vp.relative_path AS media_primary_relative_path
                 FROM client_profile_images i
                 LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
                 LEFT JOIN media_asset_variants vp
                   ON vp.media_asset_id = ma.id AND vp.is_primary = 1
                 WHERE i.client_id = ?
                   AND i.branch_id = ?
                   AND i.deleted_at IS NULL" . $frag['sql'] . "
                 ORDER BY i.created_at DESC, i.id DESC
                 LIMIT ?",
                array_merge([$clientId, $branchId], $frag['params'], [$limit])
            );
        }

        return $this->db->fetchAll(
            "SELECT i.*
             FROM client_profile_images i
             WHERE i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'] . "
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT ?",
            array_merge([$clientId, $branchId], $frag['params'], [$limit])
        );
    }

    /**
     * Same columns as {@see listActiveForClientInBranch} but restricted to given image ids (polling delta).
     *
     * @param list<int> $imageIds
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveEnrichedForClientInBranchByIds(int $clientId, int $branchId, array $imageIds): array
    {
        if (!$this->isTableReady() || $imageIds === []) {
            return [];
        }
        $ids = [];
        foreach ($imageIds as $raw) {
            $n = (int) $raw;
            if ($n > 0) {
                $ids[$n] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        if (count($ids) > 50) {
            $ids = array_slice($ids, 0, 50);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        if ($this->isMediaLibraryReady()) {
            return $this->db->fetchAll(
                "SELECT i.*,
                        ma.status AS media_asset_status,
                        ma.organization_id AS media_organization_id,
                        ma.original_filename AS media_original_filename,
                        ma.mime_detected AS media_mime_detected,
                        ma.bytes_original AS media_bytes_original,
                        vp.relative_path AS media_primary_relative_path
                 FROM client_profile_images i
                 LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
                 LEFT JOIN media_asset_variants vp
                   ON vp.media_asset_id = ma.id AND vp.is_primary = 1
                 WHERE i.client_id = ?
                   AND i.branch_id = ?
                   AND i.deleted_at IS NULL
                   AND i.id IN ($placeholders)" . $frag['sql'] . '
                 ORDER BY i.id ASC',
                array_merge([$clientId, $branchId], $ids, $frag['params'])
            );
        }

        return $this->db->fetchAll(
            "SELECT i.*
             FROM client_profile_images i
             WHERE i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL
               AND i.id IN ($placeholders)" . $frag['sql'] . '
             ORDER BY i.id ASC',
            array_merge([$clientId, $branchId], $ids, $frag['params'])
        );
    }

    /**
     * Single-row fetch with the same joins as {@see listActiveForClientInBranch} (for post-upload response).
     *
     * @return array<string, mixed>|null
     */
    public function findActiveEnrichedForClientImageInBranch(int $imageId, int $clientId, int $branchId): ?array
    {
        if (!$this->isTableReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        if ($this->isMediaLibraryReady()) {
            $row = $this->db->fetchOne(
                "SELECT i.*,
                        ma.status AS media_asset_status,
                        ma.organization_id AS media_organization_id,
                        ma.original_filename AS media_original_filename,
                        ma.mime_detected AS media_mime_detected,
                        ma.bytes_original AS media_bytes_original,
                        vp.relative_path AS media_primary_relative_path
                 FROM client_profile_images i
                 LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
                 LEFT JOIN media_asset_variants vp
                   ON vp.media_asset_id = ma.id AND vp.is_primary = 1
                 WHERE i.id = ?
                   AND i.client_id = ?
                   AND i.branch_id = ?
                   AND i.deleted_at IS NULL" . $frag['sql'] . '
                 LIMIT 1',
                array_merge([$imageId, $clientId, $branchId], $frag['params'])
            );

            return $row ?: null;
        }
        $row = $this->db->fetchOne(
            "SELECT i.*
             FROM client_profile_images i
             WHERE i.id = ?
               AND i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'] . '
             LIMIT 1',
            array_merge([$imageId, $clientId, $branchId], $frag['params'])
        );

        return $row ?: null;
    }

    public function findActiveForClientInBranch(int $imageId, int $clientId, int $branchId): ?array
    {
        if (!$this->isTableReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');

        return $this->db->fetchOne(
            "SELECT i.*
             FROM client_profile_images i
             WHERE i.id = ?
               AND i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'] . '
             LIMIT 1',
            array_merge([$imageId, $clientId, $branchId], $frag['params'])
        );
    }

    /**
     * Latest ready primary variant URL path segment (relative_path from DB), or null.
     * Rule for staff sidebar avatar: newest active row whose media asset is ready with a primary variant.
     */
    public function findLatestReadyPrimaryRelativePathForClient(int $clientId, int $branchId): ?string
    {
        if (!$this->isTableReady() || !$this->isMediaLibraryReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        $row = $this->db->fetchOne(
            "SELECT vp.relative_path AS rel
             FROM client_profile_images i
             INNER JOIN media_assets ma ON ma.id = i.media_asset_id AND ma.status = 'ready'
             INNER JOIN media_asset_variants vp ON vp.media_asset_id = ma.id AND vp.is_primary = 1
             WHERE i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL
               AND vp.relative_path IS NOT NULL
               AND vp.relative_path != ''" . $frag['sql'] . "
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT 1",
            array_merge([$clientId, $branchId], $frag['params'])
        );
        if ($row === null) {
            return null;
        }
        $rel = trim((string) ($row['rel'] ?? ''));

        return $rel !== '' ? $rel : null;
    }

    public function create(array $data): int
    {
        $this->assertTableReady();
        $this->db->insert(self::TABLE, $this->normalizeImage($data));

        return (int) $this->db->lastInsertId();
    }

    public function softDeleteInBranch(int $imageId, int $clientId, int $branchId, ?int $userId): int
    {
        $this->assertTableReady();
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('i');
        $stmt = $this->db->query(
            "UPDATE client_profile_images i
             SET i.deleted_at = NOW(),
                 i.is_active = 0,
                 i.updated_by = ?
             WHERE i.id = ?
               AND i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL" . $frag['sql'],
            array_merge([$userId, $imageId, $clientId, $branchId], $frag['params'])
        );

        return $stmt->rowCount();
    }

    // -------------------------------------------------------------------------
    // FOUNDATION-A4: Canonical TenantContext-scoped API
    // Methods below derive branch_id and organization_id exclusively from
    // TenantContext::requireResolvedTenant(). No caller-supplied raw IDs for scope.
    // -------------------------------------------------------------------------

    /**
     * Load a single active client profile image owned by the resolved tenant.
     * Minimal row — use loadVisibleEnrichedImage when media join data is needed.
     */
    public function loadVisibleImage(TenantContext $ctx, int $imageId, int $clientId): ?array
    {
        if (!$this->isTableReady()) {
            return null;
        }
        $scope = $ctx->requireResolvedTenant();

        return $this->db->fetchOne(
            "SELECT i.*
             FROM client_profile_images i
             WHERE i.id = ?
               AND i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM branches b
                   WHERE b.id = i.branch_id AND b.organization_id = ? AND b.deleted_at IS NULL
               )
             LIMIT 1",
            [$imageId, $clientId, $scope['branch_id'], $scope['organization_id']]
        );
    }

    /**
     * Load a single active client profile image with full media joins (for post-upload response).
     */
    public function loadVisibleEnrichedImage(TenantContext $ctx, int $imageId, int $clientId): ?array
    {
        if (!$this->isTableReady()) {
            return null;
        }
        $scope = $ctx->requireResolvedTenant();
        $existsClause = "AND EXISTS (
               SELECT 1 FROM branches b
               WHERE b.id = i.branch_id AND b.organization_id = ? AND b.deleted_at IS NULL
           )";
        if ($this->isMediaLibraryReady()) {
            $row = $this->db->fetchOne(
                "SELECT i.*,
                        ma.status AS media_asset_status,
                        ma.organization_id AS media_organization_id,
                        ma.original_filename AS media_original_filename,
                        ma.mime_detected AS media_mime_detected,
                        ma.bytes_original AS media_bytes_original,
                        vp.relative_path AS media_primary_relative_path
                 FROM client_profile_images i
                 LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
                 LEFT JOIN media_asset_variants vp
                   ON vp.media_asset_id = ma.id AND vp.is_primary = 1
                 WHERE i.id = ?
                   AND i.client_id = ?
                   AND i.branch_id = ?
                   AND i.deleted_at IS NULL
                   {$existsClause}
                 LIMIT 1",
                [$imageId, $clientId, $scope['branch_id'], $scope['organization_id']]
            );

            return $row ?: null;
        }

        return $this->db->fetchOne(
            "SELECT i.*
             FROM client_profile_images i
             WHERE i.id = ?
               AND i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL
               {$existsClause}
             LIMIT 1",
            [$imageId, $clientId, $scope['branch_id'], $scope['organization_id']]
        ) ?: null;
    }

    /**
     * Load a just-uploaded media asset and validate it belongs to the tenant's branch.
     * Replaces the direct DB fetchOne in the service upload flow (FOUNDATION-A3).
     * Returns null when asset is not found or branch does not match.
     */
    public function loadUploadedMediaAssetInScope(TenantContext $ctx, int $mediaAssetId): ?array
    {
        $scope = $ctx->requireResolvedTenant();

        return $this->db->fetchOne(
            "SELECT id, branch_id, original_filename, stored_basename, mime_detected, bytes_original
             FROM media_assets
             WHERE id = ? AND branch_id = ?
             LIMIT 1",
            [$mediaAssetId, $scope['branch_id']]
        );
    }

    /**
     * Soft-delete a client profile image owned by the resolved tenant.
     * Returns rows affected (0 on already-deleted or race condition).
     */
    public function deleteOwned(TenantContext $ctx, int $imageId, int $clientId, ?int $userId): int
    {
        $this->assertTableReady();
        $scope = $ctx->requireResolvedTenant();
        $stmt = $this->db->query(
            "UPDATE client_profile_images i
             SET i.deleted_at = NOW(),
                 i.is_active = 0,
                 i.updated_by = ?
             WHERE i.id = ?
               AND i.client_id = ?
               AND i.branch_id = ?
               AND i.deleted_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM branches b
                   WHERE b.id = i.branch_id AND b.organization_id = ? AND b.deleted_at IS NULL
               )",
            [$userId, $imageId, $clientId, $scope['branch_id'], $scope['organization_id']]
        );

        return $stmt->rowCount();
    }

    private function assertTableReady(): void
    {
        if (!$this->isTableReady()) {
            throw new \RuntimeException('client_profile_images storage is not initialized (migration 118).');
        }
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function normalizeImage(array $data): array
    {
        $allowed = [
            'branch_id',
            'client_id',
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
