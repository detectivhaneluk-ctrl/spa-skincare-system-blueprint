<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class AppointmentSeriesRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function create(array $data): int
    {
        $this->db->insert('appointment_series', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('asr');

        return $this->db->fetchOne(
            'SELECT asr.* FROM appointment_series asr WHERE asr.id = ?' . $frag['sql'],
            array_merge([$id], $frag['params'])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('asr');

        return $this->db->fetchOne(
            'SELECT asr.* FROM appointment_series asr WHERE asr.id = ?' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$id], $frag['params'])
        );
    }

    public function update(int $id, array $data): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('asr');
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn (string $k): string => $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query('UPDATE appointment_series asr SET ' . implode(', ', $cols) . ' WHERE asr.id = ?' . $frag['sql'], $vals);
    }

    /**
     * @return list<string> Normalized Y-m-d H:i:s start_at values
     */
    public function listExistingStartAts(int $seriesId): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('asr');
        $rows = $this->db->fetchAll(
            'SELECT a.start_at
             FROM appointments a
             INNER JOIN appointment_series asr ON asr.id = a.series_id
             WHERE a.series_id = ? AND a.deleted_at IS NULL' . $frag['sql'] . '
             ORDER BY a.start_at ASC',
            array_merge([$seriesId], $frag['params'])
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
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('asr');
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c
             FROM appointments a
             INNER JOIN appointment_series asr ON asr.id = a.series_id
             WHERE a.series_id = ? AND a.deleted_at IS NULL' . $frag['sql'],
            array_merge([$seriesId], $frag['params'])
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<int>
     */
    public function listCancellableAppointmentIds(int $seriesId, ?string $fromStartAtInclusive = null): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('asr');
        $sql = 'SELECT a.id
                FROM appointments a
                INNER JOIN appointment_series asr ON asr.id = a.series_id
                WHERE a.series_id = ? AND a.deleted_at IS NULL
                AND a.status IN (\'scheduled\',\'confirmed\',\'in_progress\')' . $frag['sql'];
        $params = [$seriesId];
        $params = array_merge($params, $frag['params']);
        if ($fromStartAtInclusive !== null && $fromStartAtInclusive !== '') {
            $sql .= ' AND a.start_at >= ?';
            $params[] = $fromStartAtInclusive;
        }
        $sql .= ' ORDER BY a.start_at ASC';
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
