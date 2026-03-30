<?php

declare(strict_types=1);

namespace Modules\Packages\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ClientPackageRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cp');

        return $this->db->fetchOne(
            'SELECT cp.*,
                    p.name AS package_name,
                    p.total_sessions AS package_total_sessions,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name
             FROM client_packages cp
             INNER JOIN packages p ON p.id = cp.package_id
             INNER JOIN clients c ON c.id = cp.client_id
             WHERE cp.id = ? AND cp.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdateInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cp');

        return $this->db->fetchOne(
            'SELECT * FROM client_packages cp
             WHERE cp.id = ? AND cp.branch_id = ?' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInTenantScope(array $filters, int $branchId, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cp');
        $sql = 'SELECT cp.*,
                       p.name AS package_name,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM client_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN clients c ON c.id = cp.client_id
                WHERE cp.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);
        if (!empty($filters['status'])) {
            $sql .= ' AND cp.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (p.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (array_key_exists('client_id', $filters) && $filters['client_id']) {
            $sql .= ' AND cp.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (array_key_exists('package_id', $filters) && $filters['package_id']) {
            $sql .= ' AND cp.package_id = ?';
            $params[] = (int) $filters['package_id'];
        }
        $sql .= ' ORDER BY cp.created_at DESC, cp.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function countInTenantScope(array $filters, int $branchId): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cp');
        $sql = 'SELECT COUNT(*) AS c
                FROM client_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN clients c ON c.id = cp.client_id
                WHERE cp.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);
        if (!empty($filters['status'])) {
            $sql .= ' AND cp.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (p.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (array_key_exists('client_id', $filters) && $filters['client_id']) {
            $sql .= ' AND cp.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (array_key_exists('package_id', $filters) && $filters['package_id']) {
            $sql .= ' AND cp.package_id = ?';
            $params[] = (int) $filters['package_id'];
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT cp.*,
                    p.name AS package_name,
                    p.total_sessions AS package_total_sessions,
                    c.first_name AS client_first_name,
                    c.last_name AS client_last_name
             FROM client_packages cp
             INNER JOIN packages p ON p.id = cp.package_id
             INNER JOIN clients c ON c.id = cp.client_id
             WHERE cp.id = ?',
            [$id]
        );
    }

    public function findForUpdate(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM client_packages WHERE id = ? FOR UPDATE', [$id]);
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT cp.*,
                       p.name AS package_name,
                       c.first_name AS client_first_name,
                       c.last_name AS client_last_name
                FROM client_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN clients c ON c.id = cp.client_id
                WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND cp.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (p.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND cp.branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND cp.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (array_key_exists('client_id', $filters) && $filters['client_id']) {
            $sql .= ' AND cp.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (array_key_exists('package_id', $filters) && $filters['package_id']) {
            $sql .= ' AND cp.package_id = ?';
            $params[] = (int) $filters['package_id'];
        }

        $sql .= ' ORDER BY cp.created_at DESC, cp.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c
                FROM client_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN clients c ON c.id = cp.client_id
                WHERE 1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND cp.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (p.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND cp.branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND cp.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (array_key_exists('client_id', $filters) && $filters['client_id']) {
            $sql .= ' AND cp.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (array_key_exists('package_id', $filters) && $filters['package_id']) {
            $sql .= ' AND cp.package_id = ?';
            $params[] = (int) $filters['package_id'];
        }
        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('client_packages', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE client_packages SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * Legacy helper — prefer {@see listEligibleForClientInTenantScope} for tenant surfaces.
     */
    public function listEligibleForClient(int $clientId, ?int $branchContext = null): array
    {
        if ($branchContext === null || $branchContext <= 0) {
            return [];
        }

        return $this->listEligibleForClientInTenantScope($clientId, $branchContext);
    }

    /**
     * Active client packages for a client at operation branch, tenant org-scoped via client branch.
     *
     * @return list<array<string, mixed>>
     */
    public function listEligibleForClientInTenantScope(int $clientId, int $branchContextId): array
    {
        if ($clientId <= 0 || $branchContextId <= 0) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cl');

        return $this->db->fetchAll(
            'SELECT cp.id AS client_package_id,
                       cp.package_id,
                       cp.branch_id,
                       cp.status,
                       cp.assigned_sessions,
                       cp.remaining_sessions,
                       cp.expires_at,
                       p.name AS package_name
                FROM client_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN clients cl ON cl.id = cp.client_id AND cl.deleted_at IS NULL
                    AND cl.merged_into_client_id IS NULL' . $frag['sql'] . '
                WHERE cp.client_id = ?
                  AND cp.status = ?
                  AND cp.branch_id = ?
                ORDER BY cp.expires_at IS NULL ASC, cp.expires_at ASC, cp.id ASC',
            array_merge([$clientId, 'active', $branchContextId], $frag['params'])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByClientIdInBranchTenantScope(int $clientId, int $branchContextId, int $limit = 100): array
    {
        if ($clientId <= 0 || $branchContextId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cl');

        return $this->db->fetchAll(
            'SELECT cp.id,
                    p.name AS package_name,
                    cp.status,
                    cp.assigned_sessions,
                    cp.remaining_sessions,
                    cp.expires_at,
                    cp.created_at
             FROM client_packages cp
             INNER JOIN packages p ON p.id = cp.package_id
             INNER JOIN clients cl ON cl.id = cp.client_id AND cl.deleted_at IS NULL
                 AND cl.merged_into_client_id IS NULL' . $frag['sql'] . '
             WHERE cp.client_id = ?
               AND cp.branch_id = ?
             ORDER BY cp.created_at DESC, cp.id DESC
             LIMIT ' . $limit,
            array_merge([$clientId, $branchContextId], $frag['params'])
        );
    }

    /**
     * @return array<string, int>
     */
    public function aggregateSummaryByClientInBranchTenantScope(int $clientId, int $branchContextId): array
    {
        if ($clientId <= 0 || $branchContextId <= 0) {
            return [
                'total' => 0,
                'active' => 0,
                'used' => 0,
                'expired' => 0,
                'cancelled' => 0,
                'total_remaining_sessions' => 0,
            ];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cl');
        $row = $this->db->fetchOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN cp.status = \'active\' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN cp.status = \'used\' THEN 1 ELSE 0 END) AS used,
                SUM(CASE WHEN cp.status = \'expired\' THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN cp.status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled,
                COALESCE(SUM(cp.remaining_sessions), 0) AS total_remaining_sessions
             FROM client_packages cp
             INNER JOIN clients cl ON cl.id = cp.client_id AND cl.deleted_at IS NULL
                 AND cl.merged_into_client_id IS NULL' . $frag['sql'] . '
             WHERE cp.client_id = ?
               AND cp.branch_id = ?',
            array_merge([$clientId, $branchContextId], $frag['params'])
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'used' => (int) ($row['used'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'total_remaining_sessions' => (int) ($row['total_remaining_sessions'] ?? 0),
        ];
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'package_id', 'client_id', 'branch_id', 'assigned_sessions', 'remaining_sessions',
            'assigned_at', 'starts_at', 'expires_at', 'status', 'notes', 'package_snapshot_json',
            'created_by', 'updated_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
