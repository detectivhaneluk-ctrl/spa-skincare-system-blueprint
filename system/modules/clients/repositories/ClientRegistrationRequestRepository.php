<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Kernel\TenantContext;
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

    // -------------------------------------------------------------------------
    // Canonical TenantContext-first methods (FOUNDATION-A7 PHASE-4, BIG-07)
    // -------------------------------------------------------------------------

    /**
     * Canonical: find registration request by id, scoped to resolved tenant organization.
     *
     * @return array<string, mixed>|null
     */
    public function findOwnedRegistration(TenantContext $ctx, int $id): ?array
    {
        $ctx->requireResolvedTenant();
        $scope = $this->orgScope->clientRegistrationRequestTenantExistsClause('r');
        $params = array_merge([$id], $scope['params']);
        return $this->db->fetchOne(
            'SELECT r.*, c.first_name AS linked_client_first_name, c.last_name AS linked_client_last_name
             FROM client_registration_requests r
             LEFT JOIN clients c ON c.id = r.linked_client_id
             WHERE r.id = ?' . $scope['sql'],
            $params
        ) ?: null;
    }

    /**
     * Canonical: list registration requests, scoped to resolved tenant organization.
     *
     * @return list<array<string, mixed>>
     */
    public function listOwnedRegistrations(TenantContext $ctx, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $ctx->requireResolvedTenant();
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

    /**
     * Canonical: count registration requests, scoped to resolved tenant organization.
     */
    public function countOwnedRegistrations(TenantContext $ctx, array $filters = []): int
    {
        $ctx->requireResolvedTenant();
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

    /**
     * Canonical: create a registration request, validated against resolved tenant scope.
     */
    public function mutateCreateOwnedRegistration(TenantContext $ctx, array $data): int
    {
        $ctx->requireResolvedTenant();
        $allowed = ['branch_id', 'full_name', 'phone', 'email', 'notes', 'source', 'status', 'linked_client_id', 'created_by'];
        $this->db->insert('client_registration_requests', array_intersect_key($data, array_flip($allowed)));
        return $this->db->lastInsertId();
    }

    /**
     * Canonical: update a registration request, scoped to resolved tenant organization.
     */
    public function mutateUpdateOwnedRegistration(TenantContext $ctx, int $id, array $data): void
    {
        $ctx->requireResolvedTenant();
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
