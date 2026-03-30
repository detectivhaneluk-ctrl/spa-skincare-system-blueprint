<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use Modules\Sales\Services\SalesTenantScope;

final class CashMovementRepository
{
    public function __construct(
        private Database $db,
        private SalesTenantScope $tenantScope
    )
    {
    }

    public function create(array $data): int
    {
        $this->db->insert('cash_movements', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function listBySession(int $sessionId, int $limit = 100): array
    {
        $limit = max(1, (int) $limit);
        $scope = $this->tenantScope->cashMovementClause('cm');
        return $this->db->fetchAll(
            'SELECT cm.*, u.name AS created_by_name
             FROM cash_movements cm
             LEFT JOIN users u ON u.id = cm.created_by
             WHERE cm.register_session_id = ?' . $scope['sql'] . '
             ORDER BY cm.created_at DESC, cm.id DESC
             LIMIT ?',
            array_merge([$sessionId], $scope['params'], [$limit])
        );
    }

    public function sumBySessionAndType(int $sessionId, string $type): float
    {
        $scope = $this->tenantScope->cashMovementClause('cash_movements');
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM cash_movements
             WHERE register_session_id = ?
               AND type = ?' . $scope['sql'],
            array_merge([$sessionId, $type], $scope['params'])
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'register_session_id',
            'branch_id',
            'type',
            'amount',
            'reason',
            'notes',
            'created_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
