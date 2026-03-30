<?php

declare(strict_types=1);

namespace Core\Permissions;

use Core\App\Database;
use PDOException;

/**
 * Pivot: extra permissions granted to users via active staff-group membership (`staff.user_id`).
 */
final class StaffGroupPermissionRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Permission codes from active, non-deleted groups the user's linked staff row belongs to,
     * scoped by branch context (matches staff-group semantics elsewhere):
     * - context branch null: only groups with staff_groups.branch_id IS NULL
     * - context branch N: groups with branch_id IS NULL OR branch_id = N
     *
     * @return list<string>
     */
    public function listPermissionCodesForUserInBranchScope(int $userId, ?int $branchContextId): array
    {
        $sql = 'SELECT DISTINCT p.code
                FROM staff st
                INNER JOIN staff_group_members sgm ON sgm.staff_id = st.id
                INNER JOIN staff_groups sg ON sg.id = sgm.staff_group_id
                    AND sg.deleted_at IS NULL AND sg.is_active = 1
                INNER JOIN staff_group_permissions sgp ON sgp.staff_group_id = sg.id
                INNER JOIN permissions p ON p.id = sgp.permission_id
                WHERE st.user_id = ? AND st.deleted_at IS NULL AND st.is_active = 1';
        $params = [$userId];
        if ($branchContextId === null) {
            $sql .= ' AND sg.branch_id IS NULL';
        } else {
            $sql .= ' AND (sg.branch_id IS NULL OR sg.branch_id = ?)';
            $params[] = $branchContextId;
        }
        $sql .= ' ORDER BY p.code';
        try {
            $rows = $this->db->fetchAll($sql, $params);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') === '42S02') {
                return [];
            }
            throw $e;
        }

        return array_values(array_map(static fn (array $r): string => (string) $r['code'], $rows));
    }

    /**
     * @param list<int> $permissionIds
     */
    public function replaceLinksForGroup(int $staffGroupId, array $permissionIds): void
    {
        $this->db->query('DELETE FROM staff_group_permissions WHERE staff_group_id = ?', [$staffGroupId]);
        $seen = [];
        foreach ($permissionIds as $raw) {
            $pid = (int) $raw;
            if ($pid <= 0 || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $this->db->insert('staff_group_permissions', [
                'staff_group_id' => $staffGroupId,
                'permission_id' => $pid,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    public function listPermissionIdsForGroup(int $staffGroupId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT permission_id FROM staff_group_permissions WHERE staff_group_id = ? ORDER BY permission_id',
            [$staffGroupId]
        );

        return array_map(static fn (array $r): int => (int) $r['permission_id'], $rows);
    }

    /**
     * Full permission catalog for admin assignment UI (canonical `permissions` table).
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function listPermissionCatalog(): array
    {
        $rows = $this->db->fetchAll('SELECT id, code, name FROM permissions ORDER BY code ASC');

        return array_values(array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
            ];
        }, $rows));
    }
}
