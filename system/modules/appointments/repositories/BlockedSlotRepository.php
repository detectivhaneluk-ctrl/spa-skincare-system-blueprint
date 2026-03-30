<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class BlockedSlotRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT bs.*, st.first_name AS staff_first_name, st.last_name AS staff_last_name
             FROM appointment_blocked_slots bs
             LEFT JOIN staff st ON st.id = bs.staff_id
             WHERE bs.id = ? AND bs.deleted_at IS NULL',
            [$id]
        );
    }

    public function create(array $data): int
    {
        $allowed = [
            'branch_id',
            'staff_id',
            'title',
            'block_date',
            'start_time',
            'end_time',
            'notes',
            'created_by',
        ];
        $this->db->insert('appointment_blocked_slots', array_intersect_key($data, array_flip($allowed)));

        return (int) $this->db->lastInsertId();
    }

    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE appointment_blocked_slots SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public function listForDate(string $date, ?int $branchId = null): array
    {
        $sql = 'SELECT bs.*, st.first_name AS staff_first_name, st.last_name AS staff_last_name
                FROM appointment_blocked_slots bs
                LEFT JOIN staff st ON st.id = bs.staff_id
                WHERE bs.deleted_at IS NULL
                  AND bs.block_date = ?';
        $params = [$date];
        [$sql, $params] = $this->appendBlockedSlotBranchTenantClause($sql, $params, $branchId);
        $sql .= ' ORDER BY bs.start_time ASC, bs.id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<int, array{id:int,staff_id:int|null,title:string,block_date:string,start_time:string,end_time:string,notes:string|null}>
     */
    public function listForStaffAndDate(int $staffId, string $date, ?int $branchId = null): array
    {
        $sql = 'SELECT id, staff_id, title, block_date, start_time, end_time, notes
                FROM appointment_blocked_slots
                WHERE deleted_at IS NULL
                  AND block_date = ?
                  AND (staff_id = ? OR staff_id IS NULL)';
        $params = [$date, $staffId];
        [$sql, $params] = $this->appendBlockedSlotBranchTenantClause($sql, $params, $branchId);
        $sql .= ' ORDER BY start_time ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<int, array<int, array{id:int,staff_id:int,title:string,start_at:string,end_at:string,notes:string|null}>>
     */
    public function listGroupedByStaffForDate(string $date, ?int $branchId = null): array
    {
        $sql = 'SELECT id, staff_id, title, notes, block_date, start_time, end_time
                FROM appointment_blocked_slots
                WHERE deleted_at IS NULL
                  AND block_date = ?';
        $params = [$date];
        [$sql, $params] = $this->appendBlockedSlotBranchTenantClause($sql, $params, $branchId);
        $sql .= ' ORDER BY staff_id, start_time';
        $rows = $this->db->fetchAll($sql, $params);
        $grouped = [];
        foreach ($rows as $row) {
            $sid = isset($row['staff_id']) ? (int) $row['staff_id'] : 0;
            if ($sid <= 0) {
                continue;
            }
            if (!isset($grouped[$sid])) {
                $grouped[$sid] = [];
            }
            $grouped[$sid][] = [
                'id' => (int) $row['id'],
                'staff_id' => $sid,
                'title' => (string) ($row['title'] ?? 'Blocked'),
                'start_at' => $date . ' ' . substr((string) ($row['start_time'] ?? '00:00:00'), 0, 8),
                'end_at' => $date . ' ' . substr((string) ($row['end_time'] ?? '00:00:00'), 0, 8),
                'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            ];
        }

        return $grouped;
    }

    /**
     * @param list<mixed> $params
     * @return array{0: string, 1: list<mixed>}
     */
    private function appendBlockedSlotBranchTenantClause(string $sql, array $params, ?int $branchId): array
    {
        if ($branchId !== null && $branchId > 0) {
            $union = $this->orgScope->settingsBackedCatalogUnionBranchRowOrGlobalNullFromOperationBranchClause('bs', $branchId);
            $sql .= ' AND (' . $union['sql'] . ')';

            return [$sql, array_merge($params, $union['params'])];
        }
        $global = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('bs');
        $sql .= $global['sql'];

        return [$sql, array_merge($params, $global['params'])];
    }
}
