<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use Modules\Sales\Services\SalesTenantScope;

final class RegisterSessionRepository
{
    public function __construct(
        private Database $db,
        private SalesTenantScope $tenantScope
    )
    {
    }

    public function find(int $id): ?array
    {
        $scope = $this->tenantScope->registerSessionClause('rs');
        return $this->db->fetchOne(
            'SELECT rs.*,
                    ob.name AS opened_by_name,
                    cb.name AS closed_by_name,
                    b.name AS branch_name
             FROM register_sessions rs
             INNER JOIN branches b ON b.id = rs.branch_id
             INNER JOIN users ob ON ob.id = rs.opened_by
             LEFT JOIN users cb ON cb.id = rs.closed_by
             WHERE rs.id = ?' . $scope['sql'],
            array_merge([$id], $scope['params'])
        );
    }

    public function findForUpdate(int $id): ?array
    {
        $scope = $this->tenantScope->registerSessionClause('rs');
        return $this->db->fetchOne(
            'SELECT rs.* FROM register_sessions rs WHERE rs.id = ?' . $scope['sql'] . ' FOR UPDATE',
            array_merge([$id], $scope['params'])
        );
    }

    public function findOpenByBranch(int $branchId): ?array
    {
        $scope = $this->tenantScope->registerSessionClause('rs');
        return $this->db->fetchOne(
            "SELECT rs.* FROM register_sessions rs
             WHERE rs.branch_id = ?
               AND rs.status = 'open'
               {$scope['sql']}
             ORDER BY rs.id DESC
             LIMIT 1",
            array_merge([$branchId], $scope['params'])
        );
    }

    public function findOpenByBranchForUpdate(int $branchId): ?array
    {
        $scope = $this->tenantScope->registerSessionClause('rs');
        return $this->db->fetchOne(
            "SELECT rs.* FROM register_sessions rs
             WHERE rs.branch_id = ?
               AND rs.status = 'open'
               {$scope['sql']}
             ORDER BY rs.id DESC
             LIMIT 1
             FOR UPDATE",
            array_merge([$branchId], $scope['params'])
        );
    }

    public function create(array $data): int
    {
        $this->db->insert('register_sessions', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(fn ($k) => $k . ' = ?', array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $scope = $this->tenantScope->registerSessionClause('register_sessions');
        $sql = 'UPDATE register_sessions SET ' . implode(', ', $cols) . ' WHERE id = ?' . $scope['sql'];
        $this->db->query($sql, array_merge($vals, $scope['params']));
    }

    public function listRecent(?int $branchId = null, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
        $sql = 'SELECT rs.*,
                       ob.name AS opened_by_name,
                       cb.name AS closed_by_name,
                       b.name AS branch_name
                FROM register_sessions rs
                INNER JOIN branches b ON b.id = rs.branch_id
                INNER JOIN users ob ON ob.id = rs.opened_by
                LEFT JOIN users cb ON cb.id = rs.closed_by
                WHERE 1=1';
        $params = [];
        $scope = $this->tenantScope->registerSessionClause('rs');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        if ($branchId !== null) {
            $sql .= ' AND rs.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY rs.opened_at DESC, rs.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function count(?int $branchId = null): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM register_sessions WHERE 1=1';
        $params = [];
        $scope = $this->tenantScope->registerSessionClause('register_sessions');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'branch_id',
            'opened_by',
            'closed_by',
            'opened_at',
            'closed_at',
            'opening_cash_amount',
            'closing_cash_amount',
            'expected_cash_amount',
            'variance_amount',
            'status',
            'notes',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
