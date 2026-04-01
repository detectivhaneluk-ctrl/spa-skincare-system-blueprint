<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ServiceRepository
{
    public function __construct(
        private Database $db,
        private ServiceStaffGroupRepository $serviceStaffGroups,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function find(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $row = $this->db->fetchOne(
            'SELECT s.*, c.name AS category_name FROM services s
             LEFT JOIN service_categories c ON s.category_id = c.id AND c.deleted_at IS NULL
             WHERE s.id = ? AND s.deleted_at IS NULL' . $frag['sql'],
            array_merge([$id], $frag['params'])
        );
        if (!$row) return null;
        $row['staff_ids'] = array_column($this->db->fetchAll('SELECT staff_id FROM service_staff WHERE service_id = ?', [$id]), 'staff_id');
        $row['staff_group_ids'] = $this->serviceStaffGroups->listLinkedStaffGroupIds($id);
        $row['room_ids'] = array_column($this->db->fetchAll('SELECT room_id FROM service_rooms WHERE service_id = ?', [$id]), 'room_id');
        $row['equipment_ids'] = array_column($this->db->fetchAll('SELECT equipment_id FROM service_equipment WHERE service_id = ?', [$id]), 'equipment_id');
        return $row;
    }

    public function list(?int $categoryId = null, ?int $branchId = null): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT s.*, c.name AS category_name FROM services s
                LEFT JOIN service_categories c ON s.category_id = c.id AND c.deleted_at IS NULL
                WHERE s.deleted_at IS NULL';
        $params = [];
        if ($categoryId !== null) {
            $sql .= ' AND s.category_id = ?';
            $params[] = $categoryId;
        }
        if ($branchId !== null) {
            $sql .= ' AND (s.branch_id = ? OR s.branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY c.sort_order, c.name, s.name';
        // WAVE-07B: display-only service catalog list — replica-eligible.
        // Writes (create/update/delete) redirect to next request; ServiceRepository::find() stays primary.
        return $this->db->forRead()->fetchAll($sql, $params);
    }

    /**
     * Active catalog rows; branch filter matches {@see self::list()} ({@code OR branch_id IS NULL} for global rows).
     */
    public function count(?int $branchId = null): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT COUNT(*) AS c FROM services s WHERE s.deleted_at IS NULL';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (s.branch_id = ? OR s.branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $row = $this->db->forRead()->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $mappings = [
            'staff_ids' => $data['staff_ids'] ?? [],
            'staff_group_ids' => $data['staff_group_ids'] ?? [],
            'room_ids' => $data['room_ids'] ?? [],
            'equipment_ids' => $data['equipment_ids'] ?? [],
        ];
        unset($data['staff_ids'], $data['staff_group_ids'], $data['room_ids'], $data['equipment_ids']);
        $this->db->insert('services', $this->normalize($data));
        $id = $this->db->lastInsertId();
        $this->syncMappings($id, $mappings);
        return $id;
    }

    public function update(int $id, array $data): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $mappings = [
            'staff_ids' => $data['staff_ids'] ?? null,
            'staff_group_ids' => $data['staff_group_ids'] ?? null,
            'room_ids' => $data['room_ids'] ?? null,
            'equipment_ids' => $data['equipment_ids'] ?? null,
        ];
        unset($data['staff_ids'], $data['staff_group_ids'], $data['room_ids'], $data['equipment_ids']);
        $norm = $this->normalize($data);
        if (!empty($norm)) {
            $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
            $vals = array_values($norm);
            $vals[] = $id;
            $vals = array_merge($vals, $frag['params']);
            $this->db->query('UPDATE services s SET ' . implode(', ', $cols) . ' WHERE s.id = ?' . $frag['sql'], $vals);
        }
        if ($mappings['staff_ids'] !== null) $this->syncStaff($id, $mappings['staff_ids']);
        if ($mappings['staff_group_ids'] !== null) {
            $this->serviceStaffGroups->replaceLinksForService($id, $mappings['staff_group_ids']);
        }
        if ($mappings['room_ids'] !== null) $this->syncRooms($id, $mappings['room_ids']);
        if ($mappings['equipment_ids'] !== null) $this->syncEquipment($id, $mappings['equipment_ids']);
    }

    public function softDelete(int $id): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $params = array_merge([$id], $frag['params']);
        $this->db->query('UPDATE services s SET s.deleted_at = NOW() WHERE s.id = ?' . $frag['sql'], $params);
    }

    private function normalize(array $data): array
    {
        $allowed = ['category_id', 'name', 'description', 'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'price', 'vat_rate_id', 'is_active', 'branch_id', 'created_by', 'updated_by'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('description', $out)) {
            $d = $out['description'];
            if ($d === null || (is_string($d) && trim($d) === '')) {
                $out['description'] = null;
            } elseif (is_string($d)) {
                $out['description'] = trim($d);
            }
        }

        return $out;
    }

    private function syncMappings(int $serviceId, array $mappings): void
    {
        $this->syncStaff($serviceId, $mappings['staff_ids'] ?? []);
        $this->serviceStaffGroups->replaceLinksForService($serviceId, $mappings['staff_group_ids'] ?? []);
        $this->syncRooms($serviceId, $mappings['room_ids'] ?? []);
        $this->syncEquipment($serviceId, $mappings['equipment_ids'] ?? []);
    }

    private function syncStaff(int $serviceId, array $ids): void
    {
        $this->db->query('DELETE FROM service_staff WHERE service_id = ?', [$serviceId]);
        foreach (array_filter(array_map('intval', $ids)) as $staffId) {
            $this->db->insert('service_staff', ['service_id' => $serviceId, 'staff_id' => $staffId]);
        }
    }

    private function syncRooms(int $serviceId, array $ids): void
    {
        $this->db->query('DELETE FROM service_rooms WHERE service_id = ?', [$serviceId]);
        foreach (array_filter(array_map('intval', $ids)) as $roomId) {
            $this->db->insert('service_rooms', ['service_id' => $serviceId, 'room_id' => $roomId]);
        }
    }

    private function syncEquipment(int $serviceId, array $ids): void
    {
        $this->db->query('DELETE FROM service_equipment WHERE service_id = ?', [$serviceId]);
        foreach (array_filter(array_map('intval', $ids)) as $eqId) {
            $this->db->insert('service_equipment', ['service_id' => $serviceId, 'equipment_id' => $eqId]);
        }
    }
}
