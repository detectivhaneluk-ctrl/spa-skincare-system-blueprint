<?php

declare(strict_types=1);

namespace Modules\Staff\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Working hours per staff, by day of week (0=Sunday .. 6=Saturday).
 * Branch is implied by staff.branch_id; no branch_id on rows.
 * Tenant scope is enforced via INNER JOIN on {@code staff} with
 * {@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()}.
 */
final class StaffScheduleRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Working intervals for one staff on one day of week (0=Sun .. 6=Sat). Used by availability logic.
     * @return list<array{start:string,end:string}>
     */
    public function listByStaffAndDay(int $staffId, int $dayOfWeek): array
    {
        $dayOfWeek = max(0, min(6, $dayOfWeek));
        $rows = $this->db->fetchAll(
            'SELECT start_time, end_time FROM staff_schedules WHERE staff_id = ? AND day_of_week = ? ORDER BY start_time ASC',
            [$staffId, $dayOfWeek]
        );
        $out = [];
        foreach ($rows as $r) {
            if (empty($r['start_time']) || empty($r['end_time'])) {
                continue;
            }
            $start = substr((string) $r['start_time'], 0, 8);
            $end = substr((string) $r['end_time'], 0, 8);
            if ($end <= $start) {
                continue;
            }
            $out[] = ['start' => $start, 'end' => $end];
        }
        return $out;
    }

    /**
     * @return list<array{id:int,staff_id:int,day_of_week:int,start_time:string,end_time:string,lunch_start_time:string|null,lunch_end_time:string|null}>
     */
    public function listByStaff(int $staffId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, staff_id, day_of_week, start_time, end_time, lunch_start_time, lunch_end_time
             FROM staff_schedules
             WHERE staff_id = ?
             ORDER BY day_of_week ASC, start_time ASC',
            [$staffId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'               => (int) $r['id'],
                'staff_id'         => (int) $r['staff_id'],
                'day_of_week'      => (int) $r['day_of_week'],
                'start_time'       => $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 8) : '',
                'end_time'         => $r['end_time'] !== null ? substr((string) $r['end_time'], 0, 8) : '',
                'lunch_start_time' => $r['lunch_start_time'] !== null ? substr((string) $r['lunch_start_time'], 0, 8) : null,
                'lunch_end_time'   => $r['lunch_end_time'] !== null ? substr((string) $r['lunch_end_time'], 0, 8) : null,
            ];
        }
        return $out;
    }

    /**
     * Replaces the entire weekly schedule for a staff member atomically.
     * Off-days must not be present in $days (no row = no work that day).
     * Tenant safety is guaranteed by the caller (controller/service must validate staffId belongs
     * to the resolved tenant before calling this method).
     *
     * @param list<array{day_of_week:int,start_time:string,end_time:string,lunch_start_time?:string|null,lunch_end_time?:string|null}> $days
     */
    public function replaceWeekForStaff(int $staffId, array $days): void
    {
        $this->db->query('DELETE FROM staff_schedules WHERE staff_id = ?', [$staffId]);
        foreach ($days as $day) {
            $row = [
                'staff_id'    => $staffId,
                'day_of_week' => (int) $day['day_of_week'],
                'start_time'  => $day['start_time'],
                'end_time'    => $day['end_time'],
            ];
            if (array_key_exists('lunch_start_time', $day) && $day['lunch_start_time'] !== null && $day['lunch_start_time'] !== '') {
                $row['lunch_start_time'] = $day['lunch_start_time'];
            }
            if (array_key_exists('lunch_end_time', $day) && $day['lunch_end_time'] !== null && $day['lunch_end_time'] !== '') {
                $row['lunch_end_time'] = $day['lunch_end_time'];
            }
            $this->db->insert('staff_schedules', $row);
        }
    }

    /**
     * Tenant-safe id read: row must belong to the resolved tenant org via the parent {@code staff} record.
     */
    public function find(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');

        return $this->db->fetchOne(
            'SELECT ss.* FROM staff_schedules ss
             INNER JOIN staff s ON s.id = ss.staff_id AND s.deleted_at IS NULL
             WHERE ss.id = ?' . $frag['sql'],
            array_merge([$id], $frag['params'])
        ) ?: null;
    }

    public function create(array $data): int
    {
        $allowed = ['staff_id', 'day_of_week', 'start_time', 'end_time', 'lunch_start_time', 'lunch_end_time'];
        $payload = array_intersect_key($data, array_flip($allowed));
        $this->db->insert('staff_schedules', $payload);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Tenant-safe update: only mutates rows whose parent staff record belongs to the resolved tenant org.
     */
    public function update(int $id, array $data): void
    {
        $allowed = ['day_of_week', 'start_time', 'end_time', 'lunch_start_time', 'lunch_end_time'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (empty($payload)) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sets = [];
        $params = [];
        foreach ($payload as $k => $v) {
            $sets[] = "ss.{$k} = ?";
            $params[] = $v;
        }
        $params[] = $id;
        $params = array_merge($params, $frag['params']);
        $this->db->query(
            'UPDATE staff_schedules ss
             INNER JOIN staff s ON s.id = ss.staff_id AND s.deleted_at IS NULL
             SET ' . implode(', ', $sets) . '
             WHERE ss.id = ?' . $frag['sql'],
            $params
        );
    }

    /**
     * Tenant-safe delete: only deletes rows whose parent staff record belongs to the resolved tenant org.
     */
    public function delete(int $id): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $this->db->query(
            'DELETE ss FROM staff_schedules ss
             INNER JOIN staff s ON s.id = ss.staff_id AND s.deleted_at IS NULL
             WHERE ss.id = ?' . $frag['sql'],
            array_merge([$id], $frag['params'])
        );
    }
}
