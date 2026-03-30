<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;

final class WaitlistRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT w.*, c.first_name AS client_first_name, c.last_name AS client_last_name,
                    s.name AS service_name, st.first_name AS staff_first_name, st.last_name AS staff_last_name
             FROM appointment_waitlist w
             LEFT JOIN clients c ON c.id = w.client_id
             LEFT JOIN services s ON s.id = w.service_id
             LEFT JOIN staff st ON st.id = w.preferred_staff_id
             WHERE w.id = ?',
            [$id]
        );
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT w.*, c.first_name AS client_first_name, c.last_name AS client_last_name,
                       s.name AS service_name, st.first_name AS staff_first_name, st.last_name AS staff_last_name
                FROM appointment_waitlist w
                LEFT JOIN clients c ON c.id = w.client_id
                LEFT JOIN services s ON s.id = w.service_id
                LEFT JOIN staff st ON st.id = w.preferred_staff_id
                WHERE 1=1';
        $params = [];

        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND w.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['date'])) {
            $sql .= ' AND w.preferred_date = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $st = array_values(array_filter($filters['status'], static fn ($s) => $s !== null && $s !== ''));
                if ($st !== []) {
                    $sql .= ' AND w.status IN (' . implode(',', array_fill(0, count($st), '?')) . ')';
                    foreach ($st as $s) {
                        $params[] = $s;
                    }
                }
            } else {
                $sql .= ' AND w.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['service_id'])) {
            $sql .= ' AND w.service_id = ?';
            $params[] = (int) $filters['service_id'];
        }
        if (!empty($filters['preferred_staff_id'])) {
            $sql .= ' AND w.preferred_staff_id = ?';
            $params[] = (int) $filters['preferred_staff_id'];
        }

        $sql .= ' ORDER BY w.preferred_date ASC, w.created_at ASC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointment_waitlist w WHERE 1=1';
        $params = [];
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND w.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['date'])) {
            $sql .= ' AND w.preferred_date = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $st = array_values(array_filter($filters['status'], static fn ($s) => $s !== null && $s !== ''));
                if ($st !== []) {
                    $sql .= ' AND w.status IN (' . implode(',', array_fill(0, count($st), '?')) . ')';
                    foreach ($st as $s) {
                        $params[] = $s;
                    }
                }
            } else {
                $sql .= ' AND w.status = ?';
                $params[] = $filters['status'];
            }
        }
        if (!empty($filters['service_id'])) {
            $sql .= ' AND w.service_id = ?';
            $params[] = (int) $filters['service_id'];
        }
        if (!empty($filters['preferred_staff_id'])) {
            $sql .= ' AND w.preferred_staff_id = ?';
            $params[] = (int) $filters['preferred_staff_id'];
        }
        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Count active (waiting, offered, or matched) waitlist entries for a client.
     * When $branchId is non-null, counts only rows with that exact branch_id.
     * When $branchId is null, no branch filter is applied (caller must scope if needed).
     */
    public function countActiveByClient(int $clientId, ?int $branchId): int
    {
        $sql = "SELECT COUNT(*) AS c FROM appointment_waitlist WHERE client_id = ? AND status IN ('waiting','offered','matched')";
        $params = [$clientId];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $allowed = [
            'branch_id',
            'client_id',
            'service_id',
            'preferred_staff_id',
            'preferred_date',
            'preferred_time_from',
            'preferred_time_to',
            'notes',
            'offer_started_at',
            'offer_expires_at',
            'status',
            'created_by',
            'matched_appointment_id',
        ];
        $payload = array_intersect_key($data, array_flip($allowed));
        $this->db->insert('appointment_waitlist', $payload);
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $allowed = ['status', 'matched_appointment_id', 'notes', 'offer_started_at', 'offer_expires_at', 'preferred_date', 'preferred_time_from', 'preferred_time_to', 'preferred_staff_id', 'service_id', 'client_id', 'branch_id'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if ($payload === []) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($payload as $k => $v) {
            $sets[] = $k . ' = ?';
            $vals[] = $v;
        }
        $vals[] = $id;
        $this->db->query('UPDATE appointment_waitlist SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    }

    /**
     * Oldest matching waitlist row for auto-offer after a slot frees.
     *
     * Canonical slot context (must match {@see existsOpenOfferForSlot}): `preferred_date` + branch
     * (when $branchId non-null: strict `branch_id = ?`; when null: `branch_id IS NULL` only) + service match
     * `(service_id IS NULL OR service_id = ?)` + staff match `(preferred_staff_id IS NULL OR preferred_staff_id = ?)`.
     * Time-of-day preferences are not part of this key (same as legacy slot-freed matching).
     *
     * @param int $excludeWaitlistId When > 0, skip this row (e.g. just-expired entry) so the chain advances.
     * @return array{id: int}|null
     */
    public function findFirstWaitingForAutoOffer(
        ?int $branchId,
        string $preferredDateYmd,
        int $serviceId,
        int $staffId,
        int $excludeWaitlistId = 0
    ): ?array {
        if ($serviceId <= 0 || $staffId <= 0) {
            return null;
        }
        $sql = "SELECT id FROM appointment_waitlist WHERE status = 'waiting' AND preferred_date = ?
                AND (service_id IS NULL OR service_id = ?)
                AND (preferred_staff_id IS NULL OR preferred_staff_id = ?)";
        $params = [$preferredDateYmd, $serviceId, $staffId];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        } else {
            $sql .= ' AND branch_id IS NULL';
        }
        if ($excludeWaitlistId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $excludeWaitlistId;
        }
        $sql .= ' ORDER BY created_at ASC LIMIT 1';

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * True when another entry already holds an open offer (`status = offered`) for the same canonical slot context:
     * `preferred_date`, same branch rule as {@see findFirstWaitingForAutoOffer}, and the same NULL-tolerant
     * service/staff equality rules.
     */
    public function existsOpenOfferForSlot(?int $branchId, string $preferredDateYmd, int $serviceId, int $staffId): bool
    {
        if ($serviceId <= 0 || $staffId <= 0) {
            return false;
        }
        $sql = "SELECT 1 AS x FROM appointment_waitlist WHERE status = 'offered' AND preferred_date = ?
                AND (service_id IS NULL OR service_id = ?)
                AND (preferred_staff_id IS NULL OR preferred_staff_id = ?)";
        $params = [$preferredDateYmd, $serviceId, $staffId];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        } else {
            $sql .= ' AND branch_id IS NULL';
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * @return list<array{id: int, branch_id: mixed}>
     */
    public function findExpiredOfferRows(?int $branchId): array
    {
        $sql = "SELECT id, branch_id FROM appointment_waitlist WHERE status = 'offered'
                AND offer_expires_at IS NOT NULL AND offer_expires_at < NOW()";
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY offer_expires_at ASC, id ASC';

        return $this->db->fetchAll($sql, $params);
    }
}
