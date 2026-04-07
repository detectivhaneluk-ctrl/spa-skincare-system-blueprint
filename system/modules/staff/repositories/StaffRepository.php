<?php

declare(strict_types=1);

namespace Modules\Staff\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class StaffRepository
{
    public function __construct(private Database $db, private OrganizationRepositoryScope $orgScope)
    {
    }

    public function findByUserId(int $userId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        return $this->db->fetchOne(
            'SELECT * FROM staff s WHERE s.user_id = ? AND s.deleted_at IS NULL' . $frag['sql'] . ' ORDER BY s.id ASC LIMIT 1',
            array_merge([$userId], $frag['params'])
        );
    }

    public function find(int $id, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT * FROM staff s WHERE s.id = ?';
        if (!$withTrashed) {
            $sql .= ' AND s.deleted_at IS NULL';
        }
        $sql .= $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id], $frag['params']));
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0, bool $trashOnly = false): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $limit = (int) $limit;
        $offset = (int) $offset;
        $del = $trashOnly ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL';
        $sql = 'SELECT s.*, u.name as user_name FROM staff s LEFT JOIN users u ON s.user_id = u.id WHERE ' . $del;
        $params = [];
        if (!$trashOnly && !empty($filters['active'])) {
            $sql .= ' AND s.is_active = 1';
        }
        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null) {
            $sql .= ' AND s.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY s.last_name, s.first_name LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        // WAVE-07B: display-only staff list — replica-eligible.
        // AvailabilityService does not use StaffRepository. Writes → redirect. find() stays primary.
        return $this->db->forRead()->fetchAll($sql, $params);
    }

    public function count(array $filters = [], bool $trashOnly = false): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $del = $trashOnly ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL';
        $sql = 'SELECT COUNT(*) AS c FROM staff s WHERE ' . $del;
        $params = [];
        if (!$trashOnly && !empty($filters['active'])) {
            $sql .= ' AND s.is_active = 1';
        }
        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null) {
            $sql .= ' AND s.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        // WAVE-07B: display count companion — replica-eligible for same reason as list().
        $row = $this->db->forRead()->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('staff', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('staff');
        $cols = [];
        $vals = [];
        foreach ($this->normalize($data) as $k => $v) {
            $cols[] = "{$k} = ?";
            $vals[] = $v;
        }
        if ($cols === []) {
            return;
        }
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query(
            'UPDATE staff SET ' . implode(', ', $cols) . ' WHERE id = ? AND deleted_at IS NULL' . $frag['sql'],
            $vals
        );
    }

    /**
     * @param non-empty-string $purgeAfterAtMysql
     */
    public function trash(int $id, ?int $deletedBy, string $purgeAfterAtMysql): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('staff');
        $params = array_merge([$deletedBy, $purgeAfterAtMysql, $id], $frag['params']);
        $stmt = $this->db->query(
            'UPDATE staff SET deleted_at = NOW(), deleted_by = ?, purge_after_at = ? WHERE id = ? AND deleted_at IS NULL'
            . $frag['sql'],
            $params
        );

        return (int) $stmt->rowCount();
    }

    /**
     * @param list<int> $ids
     * @param non-empty-string $purgeAfterAtMysql
     */
    public function bulkTrash(array $ids, ?int $deletedBy, string $purgeAfterAtMysql): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0)));
        if ($ids === []) {
            return 0;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('staff');
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([$deletedBy, $purgeAfterAtMysql], $ids, $frag['params']);
        $stmt = $this->db->query(
            'UPDATE staff SET deleted_at = NOW(), deleted_by = ?, purge_after_at = ? WHERE id IN (' . $ph . ') AND deleted_at IS NULL'
            . $frag['sql'],
            $params
        );

        return (int) $stmt->rowCount();
    }

    public function findTrashed(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT s.*, u.name as user_name FROM staff s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ? AND s.deleted_at IS NOT NULL';
        $sql .= $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id], $frag['params']));
    }

    public function restore(int $id): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('staff');
        $params = array_merge([$id], $frag['params']);
        $stmt = $this->db->query(
            'UPDATE staff SET deleted_at = NULL, deleted_by = NULL, purge_after_at = NULL WHERE id = ? AND deleted_at IS NOT NULL'
            . $frag['sql'],
            $params
        );

        return (int) $stmt->rowCount();
    }

    /**
     * Another non-deleted staff row already linked to this user_id (restore conflict check).
     */
    public function existsLiveStaffWithUserIdExcluding(?int $userId, int $excludeStaffId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $row = $this->db->fetchOne(
            'SELECT 1 AS x FROM staff s WHERE s.user_id = ? AND s.id != ? AND s.deleted_at IS NULL'
            . $frag['sql'] . ' LIMIT 1',
            array_merge([$userId, $excludeStaffId], $frag['params'])
        );

        return $row !== null;
    }

    public function countAppointmentSeriesForStaff(int $staffId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM appointment_series WHERE staff_id = ?',
            [$staffId]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function countPayrollCommissionLinesForStaff(int $staffId): int
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS c FROM payroll_commission_lines WHERE staff_id = ?',
                [$staffId]
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($row['c'] ?? 0);
    }

    public function hardDeleteTrashed(int $id): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $params = array_merge([$id], $frag['params']);
        $stmt = $this->db->query(
            'DELETE s FROM staff s WHERE s.id = ? AND s.deleted_at IS NOT NULL' . $frag['sql'],
            $params
        );

        return (int) $stmt->rowCount();
    }

    /**
     * @return list<int>
     */
    public function listTrashedIdsEligibleForPurge(\DateTimeInterface $now, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $nowStr = $now->format('Y-m-d H:i:s');
        $sql = 'SELECT s.id FROM staff s WHERE s.deleted_at IS NOT NULL AND s.purge_after_at IS NOT NULL '
            . 'AND s.purge_after_at <= ?' . $frag['sql'] . ' ORDER BY s.purge_after_at ASC, s.id ASC LIMIT ' . $limit;
        $rows = $this->db->fetchAll($sql, array_merge([$nowStr], $frag['params']));

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function normalize(array $data): array
    {
        $allowed = [
            // original columns
            'user_id', 'first_name', 'last_name', 'phone', 'email', 'job_title', 'is_active',
            'branch_id', 'created_by', 'updated_by',
            // Step 1 onboarding columns (migration 129)
            'display_name', 'gender', 'staff_type', 'onboarding_step',
            'employment_end_date', 'create_login_requested', 'max_appointments_per_day',
            'photo_media_asset_id', 'signature_media_asset_id',
            'profile_description', 'employee_notes', 'license_number', 'license_expiration_date',
            'service_type_id', 'street_1', 'street_2', 'city', 'postal_code', 'country',
            'home_phone', 'mobile_phone', 'preferred_phone', 'sms_opt_in',
            // Step 2 compensation/benefits columns (migration 130)
            'primary_group_id', 'pay_type', 'pay_type_classes', 'pay_type_products',
            'vacation_days', 'sick_days', 'personal_days',
            'employee_number', 'has_dependents', 'is_exempt',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if (isset($out['is_active'])) {
            $out['is_active'] = $out['is_active'] ? 1 : 0;
        }
        return $out;
    }
}
