<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ClientRegistrationRequestRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function find(int $id): ?array
    {
        $scope = $this->orgScope->clientRegistrationRequestTenantExistsClause('r');
        $params = array_merge([$id], $scope['params']);
        return $this->db->fetchOne(
            'SELECT r.*, c.first_name AS linked_client_first_name, c.last_name AS linked_client_last_name
             FROM client_registration_requests r
             LEFT JOIN clients c ON c.id = r.linked_client_id
             WHERE r.id = ?' . $scope['sql'],
            $params
        );
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $scope = $this->orgScope->clientRegistrationRequestTenantExistsClause('r');
        $sql = 'SELECT r.*, c.first_name AS linked_client_first_name, c.last_name AS linked_client_last_name
                FROM client_registration_requests r
                LEFT JOIN clients c ON c.id = r.linked_client_id
                WHERE 1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['source'])) {
            $sql .= ' AND r.source = ?';
            $params[] = $filters['source'];
        }
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND (r.branch_id = ? OR r.branch_id IS NULL)';
            $params[] = $filters['branch_id'];
        }
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        $sql .= ' ORDER BY r.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $scope = $this->orgScope->clientRegistrationRequestTenantExistsClause('r');
        $sql = 'SELECT COUNT(*) AS c FROM client_registration_requests r WHERE 1=1';
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['source'])) {
            $sql .= ' AND r.source = ?';
            $params[] = $filters['source'];
        }
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND (r.branch_id = ? OR r.branch_id IS NULL)';
            $params[] = $filters['branch_id'];
        }
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $allowed = ['branch_id', 'full_name', 'phone', 'email', 'notes', 'source', 'status', 'linked_client_id', 'created_by'];
        $this->db->insert('client_registration_requests', array_intersect_key($data, array_flip($allowed)));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $allowed = ['branch_id', 'full_name', 'phone', 'email', 'notes', 'source', 'status', 'linked_client_id'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if ($payload === []) {
            return;
        }
        $cols = [];
        $vals = [];
        foreach ($payload as $k => $v) {
            $cols[] = $k . ' = ?';
            $vals[] = $v;
        }
        $scope = $this->orgScope->clientRegistrationRequestTenantExistsClause('r');
        $vals = array_merge($vals, [$id], $scope['params']);
        $this->db->query(
            'UPDATE client_registration_requests r SET ' . implode(', ', $cols) . ' WHERE r.id = ?' . $scope['sql'],
            $vals
        );
    }
}
