<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;
use Core\Kernel\TenantContext;
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
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('bs');

        return $this->db->fetchOne(
            'SELECT bs.*, st.first_name AS staff_first_name, st.last_name AS staff_last_name
             FROM appointment_blocked_slots bs
             LEFT JOIN staff st ON st.id = bs.staff_id
             WHERE bs.id = ? AND bs.deleted_at IS NULL AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
    }

    /**
     * Canonical scoped retrieval — requires resolved TenantContext (fail-closed).
     * Scopes to the resolved branch_id AND org. Eliminates "find then assertBranchMatch" anti-pattern.
     *
     * @throws \Core\Kernel\UnresolvedTenantContextException when context not resolved
     */
    public function loadOwned(TenantContext $ctx, int $id): ?array
    {
        ['branch_id' => $branchId] = $ctx->requireResolvedTenant();
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('bs');

        return $this->db->fetchOne(
            'SELECT bs.*, st.first_name AS staff_first_name, st.last_name AS staff_last_name
             FROM appointment_blocked_slots bs
             LEFT JOIN staff st ON st.id = bs.staff_id
             WHERE bs.id = ? AND bs.branch_id = ? AND bs.deleted_at IS NULL AND (' . $tenant['sql'] . ')',
            array_merge([$id, $branchId], $tenant['params'])
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
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('bs');
        $this->db->query(
            'UPDATE appointment_blocked_slots bs SET deleted_at = NOW() WHERE bs.id = ? AND bs.deleted_at IS NULL AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
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
        $sql = 'SELECT bs.id, bs.staff_id, bs.title, bs.block_date, bs.start_time, bs.end_time, bs.notes
                FROM appointment_blocked_slots bs
                WHERE bs.deleted_at IS NULL
                  AND bs.block_date = ?
                  AND (bs.staff_id = ? OR bs.staff_id IS NULL)';
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
        $sql = 'SELECT bs.id, bs.staff_id, bs.title, bs.notes, bs.block_date, bs.start_time, bs.end_time
                FROM appointment_blocked_slots bs
                WHERE bs.deleted_at IS NULL
                  AND bs.block_date = ?';
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
     * Count blocked-slot rows per block_date in an inclusive date range (branch + tenant scope).
     *
     * @return array<string,int> YYYY-MM-DD => count
     */
    public function countByBlockDateInRange(string $fromDate, string $toDate, ?int $branchId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) !== 1) {
            return [];
        }

        $sql = 'SELECT bs.block_date AS d, COUNT(*) AS c
                FROM appointment_blocked_slots bs
                WHERE bs.deleted_at IS NULL
                  AND bs.block_date >= ?
                  AND bs.block_date <= ?';
        $params = [$fromDate, $toDate];
        [$sql, $params] = $this->appendBlockedSlotBranchTenantClause($sql, $params, $branchId);
        $sql .= ' GROUP BY bs.block_date';

        $rows = $this->db->forRead()->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $d = (string) ($row['d'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1) {
                $out[$d] = (int) ($row['c'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Blocked slot time ranges for an inclusive calendar date range (branch + tenant scope).
     *
     * @return list<array{block_date:string,start_time:string,end_time:string}>
     */
    public function listTimeRowsInDateRange(string $fromDate, string $toDate, ?int $branchId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) !== 1) {
            return [];
        }

        $sql = 'SELECT bs.block_date, bs.start_time, bs.end_time
                FROM appointment_blocked_slots bs
                WHERE bs.deleted_at IS NULL
                  AND bs.block_date >= ?
                  AND bs.block_date <= ?';
        $params = [$fromDate, $toDate];
        [$sql, $params] = $this->appendBlockedSlotBranchTenantClause($sql, $params, $branchId);
        $sql .= ' ORDER BY bs.block_date ASC, bs.start_time ASC, bs.id ASC';

        $rows = $this->db->forRead()->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $bd = (string) ($row['block_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) !== 1) {
                continue;
            }
            $out[] = [
                'block_date' => $bd,
                'start_time' => (string) ($row['start_time'] ?? '00:00:00'),
                'end_time' => (string) ($row['end_time'] ?? '23:59:59'),
            ];
        }

        return $out;
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
