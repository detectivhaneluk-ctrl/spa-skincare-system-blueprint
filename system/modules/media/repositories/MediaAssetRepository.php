<?php

declare(strict_types=1);

namespace Modules\Media\Repositories;

use Core\App\Database;

final class MediaAssetRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        return $this->db->insert('media_assets', $row);
    }
}
