<?php

declare(strict_types=1);

namespace Modules\Media\Repositories;

use Core\App\Database;

/**
 * Variant rows are written by the Node image worker; PHP reads for future delivery surfaces.
 */
final class MediaAssetVariantRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByAssetId(int $mediaAssetId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM media_asset_variants WHERE media_asset_id = ? ORDER BY variant_kind ASC, width ASC, format ASC',
            [$mediaAssetId]
        );
    }
}
