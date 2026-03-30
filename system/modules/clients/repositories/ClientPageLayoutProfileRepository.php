<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;

final class ClientPageLayoutProfileRepository
{
    public function __construct(private Database $db)
    {
    }

    public function findByOrgAndKey(int $organizationId, string $profileKey): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM client_page_layout_profiles WHERE organization_id = ? AND profile_key = ? LIMIT 1',
            [$organizationId, $profileKey]
        );

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrganization(int $organizationId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM client_page_layout_profiles WHERE organization_id = ? ORDER BY profile_key ASC',
            [$organizationId]
        );
    }

    public function create(int $organizationId, string $profileKey, string $displayLabel, bool $isRuntimeConsumed): int
    {
        $this->db->insert('client_page_layout_profiles', [
            'organization_id' => $organizationId,
            'profile_key' => $profileKey,
            'display_label' => $displayLabel,
            'is_runtime_consumed' => $isRuntimeConsumed ? 1 : 0,
        ]);

        return $this->db->lastInsertId();
    }
}
