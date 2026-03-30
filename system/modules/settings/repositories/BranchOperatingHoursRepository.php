<?php

declare(strict_types=1);

namespace Modules\Settings\Repositories;

use Core\App\Database;

final class BranchOperatingHoursRepository
{
    private ?bool $tableAvailable = null;

    public function __construct(private Database $db)
    {
    }

    public function isTableAvailable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['branch_operating_hours']
        );
        $this->tableAvailable = $row !== null;

        return $this->tableAvailable;
    }

    /**
     * @return list<array{day_of_week:int,start_time:?string,end_time:?string}>
     */
    public function listByBranch(int $branchId): array
    {
        if (!$this->isTableAvailable()) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT day_of_week, start_time, end_time
             FROM branch_operating_hours
             WHERE branch_id = ?
             ORDER BY day_of_week ASC',
            [$branchId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'day_of_week' => (int) ($row['day_of_week'] ?? 0),
                'start_time' => isset($row['start_time']) && $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 8) : null,
                'end_time' => isset($row['end_time']) && $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 8) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{day_of_week:int,start_time:?string,end_time:?string}|null
     */
    public function findByBranchAndDay(int $branchId, int $dayOfWeek): ?array
    {
        if (!$this->isTableAvailable()) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT day_of_week, start_time, end_time
             FROM branch_operating_hours
             WHERE branch_id = ? AND day_of_week = ?
             LIMIT 1',
            [$branchId, $dayOfWeek]
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'day_of_week' => (int) ($row['day_of_week'] ?? 0),
            'start_time' => isset($row['start_time']) && $row['start_time'] !== null ? substr((string) $row['start_time'], 0, 8) : null,
            'end_time' => isset($row['end_time']) && $row['end_time'] !== null ? substr((string) $row['end_time'], 0, 8) : null,
        ];
    }

    /**
     * @param array<int,array{start_time:?string,end_time:?string}> $weeklyMap
     */
    public function replaceWeeklyMap(int $branchId, array $weeklyMap): void
    {
        if (!$this->isTableAvailable()) {
            throw new \RuntimeException('Opening Hours storage is unavailable. Apply migration 092_create_branch_operating_hours_table.sql.');
        }
        foreach ($weeklyMap as $dayOfWeek => $row) {
            $this->db->query(
                'INSERT INTO branch_operating_hours (branch_id, day_of_week, start_time, end_time, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    start_time = VALUES(start_time),
                    end_time = VALUES(end_time),
                    updated_at = NOW()',
                [
                    $branchId,
                    (int) $dayOfWeek,
                    $row['start_time'],
                    $row['end_time'],
                ]
            );
        }
    }
}
