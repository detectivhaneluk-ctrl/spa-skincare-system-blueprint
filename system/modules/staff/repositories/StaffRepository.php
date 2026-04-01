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

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT s.*, u.name as user_name FROM staff s LEFT JOIN users u ON s.user_id = u.id WHERE s.deleted_at IS NULL';
        $params = [];
        if (!empty($filters['active'])) {
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

    public function count(array $filters = []): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT COUNT(*) AS c FROM staff s WHERE s.deleted_at IS NULL';
        $params = [];
        if (!empty($filters['active'])) {
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
        $this->db->query('UPDATE staff SET ' . implode(', ', $cols) . ' WHERE id = ?' . $frag['sql'], $vals);
    }

    public function softDelete(int $id): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('staff');
        $params = array_merge([$id], $frag['params']);
        $this->db->query('UPDATE staff SET deleted_at = NOW() WHERE id = ?' . $frag['sql'], $params);
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'user_id', 'first_name', 'last_name', 'phone', 'email', 'job_title', 'is_active',
            'branch_id', 'created_by', 'updated_by',
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
