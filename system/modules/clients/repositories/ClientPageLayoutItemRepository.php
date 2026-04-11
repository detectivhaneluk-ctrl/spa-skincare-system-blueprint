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
     * @param list<array{field_key:string, position:int, is_enabled:int, display_label?:string|null, is_required?:int|null, layout_span?:int}> $rows
     */
    public function insertRows(int $profileId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $values = [];
        $params = [];
        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, ?)';
            $params[] = $profileId;
            $params[] = (string) $row['field_key'];
            $params[] = (int) $row['position'];
            $params[] = !empty($row['is_enabled']) ? 1 : 0;
            $dl = $row['display_label'] ?? null;
            $params[] = ($dl !== null && trim((string) $dl) !== '') ? trim((string) $dl) : null;
            if (array_key_exists('is_required', $row) && $row['is_required'] !== null && $row['is_required'] !== '') {
                $params[] = !empty($row['is_required']) ? 1 : 0;
            } else {
                $params[] = null;
            }
            $span = (int) ($row['layout_span'] ?? 3);
            if ($span < 1) {
                $span = 1;
            }
            if ($span > 3) {
                $span = 3;
            }
            $params[] = $span;
        }
        $sql = 'INSERT INTO client_page_layout_items (profile_id, field_key, position, is_enabled, display_label, is_required, layout_span) VALUES '
            . implode(', ', $values);
        $this->db->query($sql, $params);
    }
}
