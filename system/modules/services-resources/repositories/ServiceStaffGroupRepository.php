<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;

/**
 * Pivot: which staff groups may perform a service (when at least one active linked group exists, enforcement applies).
 */
final class ServiceStaffGroupRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * All configured links (includes inactive/deleted groups — for admin read model).
     *
     * @return list<int>
     */
    public function listLinkedStaffGroupIds(int $serviceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT staff_group_id FROM service_staff_groups WHERE service_id = ? ORDER BY staff_group_id',
            [$serviceId]
        );

        return array_map(static fn (array $r): int => (int) $r['staff_group_id'], $rows);
    }

    /**
     * True when the service has at least one link to a non-deleted, active staff group (cheap EXISTS).
     */
    public function hasEnforceableStaffGroupLinks(int $serviceId): bool
    {
        if ($serviceId <= 0) {
            return false;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 FROM service_staff_groups ssg
             INNER JOIN staff_groups sg ON sg.id = ssg.staff_group_id
             WHERE ssg.service_id = ? AND sg.deleted_at IS NULL AND sg.is_active = 1
             LIMIT 1',
            [$serviceId]
        );

        return $row !== null;
    }

    /**
     * Staff is a member of at least one linked group that applies to the booking branch:
     * - branch_id null: only groups with sg.branch_id IS NULL
     * - branch_id set: sg.branch_id IS NULL (global group) OR sg.branch_id = branch
     */
    public function isStaffInApplicableLinkedGroups(int $serviceId, int $staffId, ?int $branchId): bool
    {
        $sql = 'SELECT 1 FROM service_staff_groups ssg
                INNER JOIN staff_groups sg ON sg.id = ssg.staff_group_id
                INNER JOIN staff_group_members sgm ON sgm.staff_group_id = sg.id AND sgm.staff_id = ?
                WHERE ssg.service_id = ?
                  AND sg.deleted_at IS NULL AND sg.is_active = 1';
        $params = [$staffId, $serviceId];
        if ($branchId === null) {
            $sql .= ' AND sg.branch_id IS NULL';
        } else {
            $sql .= ' AND (sg.branch_id IS NULL OR sg.branch_id = ?)';
            $params[] = $branchId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * Distinct staff ids that are members of at least one applicable linked active group, active staff rows only.
     *
     * @return list<int>
     */
    public function listAllowedStaffIdsForServiceBranch(int $serviceId, ?int $branchId): array
    {
        $sql = 'SELECT DISTINCT sgm.staff_id
                FROM service_staff_groups ssg
                INNER JOIN staff_groups sg ON sg.id = ssg.staff_group_id
                INNER JOIN staff_group_members sgm ON sgm.staff_group_id = sg.id
                INNER JOIN staff st ON st.id = sgm.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
                WHERE ssg.service_id = ?
                  AND sg.deleted_at IS NULL AND sg.is_active = 1';
        $params = [$serviceId];
        if ($branchId !== null) {
            $sql .= ' AND (st.branch_id IS NULL OR st.branch_id = ?)';
            $params[] = $branchId;
            $sql .= ' AND (sg.branch_id IS NULL OR sg.branch_id = ?)';
            $params[] = $branchId;
        } else {
            $sql .= ' AND sg.branch_id IS NULL AND st.branch_id IS NULL';
        }
        $sql .= ' ORDER BY sgm.staff_id';
        $rows = $this->db->fetchAll($sql, $params);

        return array_map(static fn (array $r): int => (int) $r['staff_id'], $rows);
    }

    /**
     * Replace all links for a service. Validates each group id exists and is not soft-deleted.
     *
     * @param list<int> $staffGroupIds
     */
    public function replaceLinksForService(int $serviceId, array $staffGroupIds): void
    {
        $this->db->query('DELETE FROM service_staff_groups WHERE service_id = ?', [$serviceId]);
        $seen = [];
        foreach ($staffGroupIds as $raw) {
            $gid = (int) $raw;
            if ($gid <= 0 || isset($seen[$gid])) {
                continue;
            }
            $seen[$gid] = true;
            $g = $this->db->fetchOne(
                'SELECT id FROM staff_groups WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
                [$gid]
            );
            if (!$g) {
                throw new \DomainException('Invalid staff group reference.');
            }
            $this->db->insert('service_staff_groups', [
                'service_id' => $serviceId,
                'staff_group_id' => $gid,
            ]);
        }
    }
}
