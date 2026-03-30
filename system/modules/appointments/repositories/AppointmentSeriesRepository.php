<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;

final class AppointmentSeriesRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(array $data): int
    {
        $this->db->insert('appointment_series', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM appointment_series WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM appointment_series WHERE id = ? FOR UPDATE',
            [$id]
        );
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn (string $k): string => $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE appointment_series SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * @return list<string> Normalized Y-m-d H:i:s start_at values
     */
    public function listExistingStartAts(int $seriesId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT start_at FROM appointments WHERE series_id = ? AND deleted_at IS NULL ORDER BY start_at ASC',
            [$seriesId]
        );
        $out = [];
        foreach ($rows as $r) {
            $raw = (string) ($r['start_at'] ?? '');
            if ($raw === '') {
                continue;
            }
            $ts = strtotime($raw);
            if ($ts === false) {
                continue;
            }
            $out[] = date('Y-m-d H:i:s', $ts);
        }

        return $out;
    }

    public function countMaterializedOccurrences(int $seriesId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM appointments WHERE series_id = ? AND deleted_at IS NULL',
            [$seriesId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<int>
     */
    public function listCancellableAppointmentIds(int $seriesId, ?string $fromStartAtInclusive = null): array
    {
        $sql = 'SELECT id FROM appointments
                WHERE series_id = ? AND deleted_at IS NULL
                AND status IN (\'scheduled\',\'confirmed\',\'in_progress\')';
        $params = [$seriesId];
        if ($fromStartAtInclusive !== null && $fromStartAtInclusive !== '') {
            $sql .= ' AND start_at >= ?';
            $params[] = $fromStartAtInclusive;
        }
        $sql .= ' ORDER BY start_at ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) ($r['id'] ?? 0);
        }

        return array_values(array_filter($ids, fn (int $i): bool => $i > 0));
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'branch_id',
            'client_id',
            'service_id',
            'staff_id',
            'recurrence_type',
            'interval_weeks',
            'weekday',
            'start_date',
            'end_date',
            'occurrences_count',
            'start_time',
            'end_time',
            'status',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
