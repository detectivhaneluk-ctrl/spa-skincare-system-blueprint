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
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $row = $this->db->fetchOne(
            'SELECT s.*, c.name AS category_name FROM services s
             LEFT JOIN service_categories c ON s.category_id = c.id AND c.deleted_at IS NULL
             WHERE s.id = ? AND s.deleted_at IS NULL AND (' . $frag['sql'] . ')',
            array_merge([$id], $frag['params'])
        );
        if (!$row) return null;
        $row['staff_ids'] = array_column($this->db->fetchAll('SELECT staff_id FROM service_staff WHERE service_id = ?', [$id]), 'staff_id');
        $row['staff_group_ids'] = $this->serviceStaffGroups->listLinkedStaffGroupIds($id);
        $row['room_ids'] = array_column($this->db->fetchAll('SELECT room_id FROM service_rooms WHERE service_id = ?', [$id]), 'room_id');
        $row['equipment_ids'] = array_column($this->db->fetchAll('SELECT equipment_id FROM service_equipment WHERE service_id = ?', [$id]), 'equipment_id');
        $row['product_rows'] = $this->findProductRows($id);
        return $row;
    }

    /**
     * Returns the product rows linked to this service (joined with product name/sku for display).
     *
     * @return list<array{product_id:int,quantity_used:string,unit_cost_snapshot:string|null,name:string,sku:string}>
     */
    public function findProductRows(int $serviceId): array
    {
        return $this->db->fetchAll(
            'SELECT sp.product_id, sp.quantity_used, sp.unit_cost_snapshot,
                    p.name, p.sku, p.cost_price
             FROM service_products sp
             INNER JOIN products p ON p.id = sp.product_id AND p.deleted_at IS NULL
             WHERE sp.service_id = ?
             ORDER BY p.name',
            [$serviceId]
        );
    }

    /**
     * Replaces the full set of product assignments for a service (Step 2).
     * Only touches service_products — never touches service_staff, service_rooms, etc.
     *
     * @param list<array{product_id:int, quantity_used:float, unit_cost_snapshot:float|null}> $rows
     */
    public function syncProducts(int $serviceId, array $rows): void
    {
        $this->db->query('DELETE FROM service_products WHERE service_id = ?', [$serviceId]);
        $seen = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $qty = max(0.001, round((float) ($row['quantity_used'] ?? 1), 3));
            $snapshot = isset($row['unit_cost_snapshot']) && $row['unit_cost_snapshot'] !== null
                ? round((float) $row['unit_cost_snapshot'], 2)
                : null;
            $this->db->insert('service_products', [
                'service_id'          => $serviceId,
                'product_id'          => $productId,
                'quantity_used'       => number_format($qty, 3, '.', ''),
                'unit_cost_snapshot'  => $snapshot,
            ]);
        }
    }

    public function list(?int $categoryId = null, ?int $branchId = null, bool $trashOnly = false): array
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $delClause = $trashOnly ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL';
        $sql = 'SELECT s.*, c.name AS category_name,
                    (SELECT COUNT(*) FROM service_staff ss WHERE ss.service_id = s.id) AS staff_count,
                    (SELECT COUNT(*) FROM service_rooms sr WHERE sr.service_id = s.id) AS room_count,
                    (SELECT COUNT(*) FROM service_products sp WHERE sp.service_id = s.id) AS product_count
                FROM services s
                LEFT JOIN service_categories c ON s.category_id = c.id AND c.deleted_at IS NULL
                WHERE ' . $delClause;
        $params = [];
        if ($categoryId !== null) {
            $sql .= ' AND s.category_id = ?';
            $params[] = $categoryId;
        }
        if ($branchId !== null) {
            $sql .= ' AND (s.branch_id = ? OR s.branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' AND (' . $frag['sql'] . ')';
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY c.sort_order, c.name, s.name';
        // WAVE-07B: display-only service catalog list — replica-eligible.
        // Writes (create/update/delete) redirect to next request; ServiceRepository::find() stays primary.
        return $this->db->forRead()->fetchAll($sql, $params);
    }

    /**
     * Active catalog rows; branch filter matches {@see self::list()} ({@code OR branch_id IS NULL} for global rows).
     */
    public function count(?int $branchId = null, bool $trashOnly = false): int
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $delClause = $trashOnly ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL';
        $sql = 'SELECT COUNT(*) AS c FROM services s WHERE ' . $delClause;
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (s.branch_id = ? OR s.branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' AND (' . $frag['sql'] . ')';
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
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
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
            $this->db->query(
                'UPDATE services s SET ' . implode(', ', $cols) . ' WHERE s.id = ? AND s.deleted_at IS NULL AND (' . $frag['sql'] . ')',
                $vals
            );
        }
        if ($mappings['staff_ids'] !== null) $this->syncStaff($id, $mappings['staff_ids']);
        if ($mappings['staff_group_ids'] !== null) {
            $this->serviceStaffGroups->replaceLinksForService($id, $mappings['staff_group_ids']);
        }
        if ($mappings['room_ids'] !== null) $this->syncRooms($id, $mappings['room_ids']);
        if ($mappings['equipment_ids'] !== null) $this->syncEquipment($id, $mappings['equipment_ids']);
    }

    /**
     * Returns the first live service row with $sku, optionally excluding one id (for update checks).
     * Tenant-scoped.
     */
    public function findBySkuExcluding(string $sku, ?int $excludeId): ?array
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $sql = 'SELECT s.id FROM services s WHERE s.sku = ? AND s.deleted_at IS NULL AND (' . $frag['sql'] . ')';
        $params = array_merge([$sku], $frag['params']);
        if ($excludeId !== null) {
            $sql .= ' AND s.id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Move one active service to trash (tenant-scoped). Sets purge_after_at from caller.
     *
     * @param non-empty-string $purgeAfterAtMysql Format Y-m-d H:i:s in app timezone context from service layer
     */
    public function trash(int $id, ?int $deletedBy, string $purgeAfterAtMysql): int
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $params = array_merge([$deletedBy, $purgeAfterAtMysql, $id], $frag['params']);
        $stmt = $this->db->query(
            'UPDATE services s SET s.deleted_at = NOW(), s.deleted_by = ?, s.purge_after_at = ? '
            . 'WHERE s.id = ? AND s.deleted_at IS NULL AND (' . $frag['sql'] . ')',
            $params
        );

        return (int) $stmt->rowCount();
    }

    /**
     * @param list<int> $ids
     * @param non-empty-string $purgeAfterAtMysql
     * @return int Rows updated
     */
    public function bulkTrash(array $ids, ?int $deletedBy, string $purgeAfterAtMysql): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return 0;
        }
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([$deletedBy, $purgeAfterAtMysql], $ids, $frag['params']);
        $stmt = $this->db->query(
            'UPDATE services s SET s.deleted_at = NOW(), s.deleted_by = ?, s.purge_after_at = ? '
            . 'WHERE s.id IN (' . $ph . ') AND s.deleted_at IS NULL AND (' . $frag['sql'] . ')',
            $params
        );

        return (int) $stmt->rowCount();
    }

    /**
     * Trashed row for the same shape as {@see find()} (mappings included).
     */
    public function findTrashed(int $id): ?array
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $row = $this->db->fetchOne(
            'SELECT s.*, c.name AS category_name FROM services s
             LEFT JOIN service_categories c ON s.category_id = c.id AND c.deleted_at IS NULL
             WHERE s.id = ? AND s.deleted_at IS NOT NULL AND (' . $frag['sql'] . ')',
            array_merge([$id], $frag['params'])
        );
        if (!$row) {
            return null;
        }
        $row['staff_ids'] = array_column($this->db->fetchAll('SELECT staff_id FROM service_staff WHERE service_id = ?', [$id]), 'staff_id');
        $row['staff_group_ids'] = $this->serviceStaffGroups->listLinkedStaffGroupIds($id);
        $row['room_ids'] = array_column($this->db->fetchAll('SELECT room_id FROM service_rooms WHERE service_id = ?', [$id]), 'room_id');
        $row['equipment_ids'] = array_column($this->db->fetchAll('SELECT equipment_id FROM service_equipment WHERE service_id = ?', [$id]), 'equipment_id');
        $row['product_rows'] = $this->findProductRows($id);

        return $row;
    }

    public function restore(int $id): int
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $params = array_merge([$id], $frag['params']);
        $stmt = $this->db->query(
            'UPDATE services s SET s.deleted_at = NULL, s.deleted_by = NULL, s.purge_after_at = NULL '
            . 'WHERE s.id = ? AND s.deleted_at IS NOT NULL AND (' . $frag['sql'] . ')',
            $params
        );

        return (int) $stmt->rowCount();
    }

    public function countAppointmentSeriesForService(int $serviceId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM appointment_series WHERE service_id = ?',
            [$serviceId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Physical delete of a trashed row only, tenant-scoped.
     */
    public function hardDeleteTrashed(int $id): int
    {
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $params = array_merge([$id], $frag['params']);
        $stmt = $this->db->query(
            'DELETE s FROM services s WHERE s.id = ? AND s.deleted_at IS NOT NULL AND (' . $frag['sql'] . ')',
            $params
        );

        return (int) $stmt->rowCount();
    }

    /**
     * Cron/CLI: expired trashed rows in current tenant scope. Does not delete — returns candidate ids.
     *
     * @return list<int>
     */
    public function listTrashedIdsEligibleForPurge(\DateTimeInterface $now, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $frag = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('s');
        $nowStr = $now->format('Y-m-d H:i:s');
        $sql = 'SELECT s.id FROM services s WHERE s.deleted_at IS NOT NULL AND s.purge_after_at IS NOT NULL '
            . 'AND s.purge_after_at <= ? AND (' . $frag['sql'] . ') ORDER BY s.purge_after_at ASC, s.id ASC LIMIT ' . $limit;
        $rows = $this->db->fetchAll($sql, array_merge([$nowStr], $frag['params']));

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'service_type',
            'category_id',
            'name', 'description', 'sku', 'barcode',
            'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
            'processing_time_required', 'add_on', 'requires_two_staff_members',
            'applies_to_employee', 'applies_to_room', 'requires_equipment',
            'price', 'vat_rate_id', 'is_active',
            'show_in_online_menu', 'staff_fee_mode', 'staff_fee_value',
            'allow_on_gift_voucher_sale', 'billing_code',
            'branch_id', 'created_by', 'updated_by',
        ];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('description', $out)) {
            $d = $out['description'];
            if ($d === null || (is_string($d) && trim($d) === '')) {
                $out['description'] = null;
            } elseif (is_string($d)) {
                $out['description'] = trim($d);
            }
        }
        // Enforce: staff_fee_value must be NULL when mode=none
        if (array_key_exists('staff_fee_mode', $out) && ($out['staff_fee_mode'] ?? 'none') === 'none') {
            $out['staff_fee_value'] = null;
        }
        // Normalize nullable string columns
        foreach (['sku', 'barcode', 'billing_code'] as $col) {
            if (array_key_exists($col, $out)) {
                $v = $out[$col];
                $out[$col] = ($v === null || (is_string($v) && trim($v) === '')) ? null : trim((string) $v);
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

    /**
     * Returns every service id currently linked to $staffId via service_staff.
     *
     * @return list<int>
     */
    public function listAssignedServiceIdsForStaff(int $staffId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT service_id FROM service_staff WHERE staff_id = ? ORDER BY service_id',
            [$staffId]
        );

        return array_map(static fn (array $r): int => (int) $r['service_id'], $rows);
    }

    /**
     * Replaces the full set of service assignments for a staff member.
     *
     * Only IDs that appear in the tenant-scoped service catalog for $branchId are accepted;
     * any submitted id not in the valid set is silently skipped (fail-closed, no cross-tenant leak).
     *
     * @param list<int> $serviceIds
     */
    public function replaceAssignedServicesForStaff(int $staffId, array $serviceIds, ?int $branchId): void
    {
        $validRows  = $this->list(null, $branchId);
        $validIdSet = array_flip(array_column($validRows, 'id'));

        $this->db->query('DELETE FROM service_staff WHERE staff_id = ?', [$staffId]);
        $seen = [];
        foreach ($serviceIds as $raw) {
            $sid = (int) $raw;
            if ($sid <= 0 || !isset($validIdSet[$sid]) || isset($seen[$sid])) {
                continue;
            }
            $seen[$sid] = true;
            $this->db->insert('service_staff', ['service_id' => $sid, 'staff_id' => $staffId]);
        }
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
