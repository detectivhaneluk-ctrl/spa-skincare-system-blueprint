<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;

final class RoomRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM rooms WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public function list(?int $branchId = null): array
    {
        $sql = 'SELECT * FROM rooms WHERE deleted_at IS NULL';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY name';
        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->db->insert('rooms', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) return;
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE rooms SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE rooms SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    private function normalize(array $data): array
    {
        $allowed = ['name', 'code', 'is_active', 'maintenance_mode', 'branch_id'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (isset($out['is_active'])) $out['is_active'] = $out['is_active'] ? 1 : 0;
        if (isset($out['maintenance_mode'])) $out['maintenance_mode'] = $out['maintenance_mode'] ? 1 : 0;
        return $out;
    }
}
