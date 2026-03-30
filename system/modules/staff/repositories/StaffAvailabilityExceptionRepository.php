<?php

declare(strict_types=1);

namespace Modules\Staff\Repositories;

use Core\App\Database;

/**
 * Date-specific availability overrides for staff (BKM-006).
 *
 * kind:
 * - closed: full day off (start_time/end_time ignored)
 * - open: working segment for that calendar date (replaces weekly schedule for the day when any open row exists)
 * - unavailable: subtract this window from the effective working intervals (weekly or open override)
 *
 * branch_id NULL = applies in all branch contexts; otherwise row applies when querying with matching branch_id (see listForStaffAndDate).
 */
final class StaffAvailabilityExceptionRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array{kind:string,start_time:?string,end_time:?string}>
     */
    public function listForStaffAndDate(int $staffId, string $date, ?int $branchId = null): array
    {
        $sql = 'SELECT kind, start_time, end_time
                FROM staff_availability_exceptions
                WHERE deleted_at IS NULL
                  AND staff_id = ?
                  AND exception_date = ?';
        $params = [$staffId, $date];
        if ($branchId !== null) {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = $branchId;
        } else {
            $sql .= ' AND branch_id IS NULL';
        }
        $sql .= ' ORDER BY id ASC';

        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $kind = strtolower(trim((string) ($r['kind'] ?? '')));
            if ($kind === '') {
                continue;
            }
            $st = $r['start_time'] !== null && (string) $r['start_time'] !== ''
                ? substr((string) $r['start_time'], 0, 8)
                : null;
            $en = $r['end_time'] !== null && (string) $r['end_time'] !== ''
                ? substr((string) $r['end_time'], 0, 8)
                : null;
            $out[] = [
                'kind' => $kind,
                'start_time' => $st,
                'end_time' => $en,
            ];
        }

        return $out;
    }
}
