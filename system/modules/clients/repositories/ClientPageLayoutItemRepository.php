<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;

final class ClientPageLayoutItemRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProfileId(int $profileId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM client_page_layout_items WHERE profile_id = ? ORDER BY position ASC, id ASC',
            [$profileId]
        );
    }

    public function deleteByProfileId(int $profileId): void
    {
        $this->db->query('DELETE FROM client_page_layout_items WHERE profile_id = ?', [$profileId]);
    }

    /**
     * @param list<array{field_key:string, position:int, is_enabled:int}> $rows
     */
    public function insertRows(int $profileId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?)';
            $params[] = $profileId;
            $params[] = (string) $row['field_key'];
            $params[] = (int) $row['position'];
            $params[] = !empty($row['is_enabled']) ? 1 : 0;
        }
        $sql = 'INSERT INTO client_page_layout_items (profile_id, field_key, position, is_enabled) VALUES '
            . implode(', ', $values);
        $this->db->query($sql, $params);
    }
}
