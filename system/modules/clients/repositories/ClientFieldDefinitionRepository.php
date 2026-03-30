<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ClientFieldDefinitionRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Tenant-scoped definitions only (branch_id NULL rows excluded — no org FK in schema).
     *
     * @param int|null $branchId When set, include definitions for that branch only (must belong to resolved org).
     */
    public function list(?int $branchId = null, bool $onlyActive = false): array
    {
        $tenant = $this->orgScope->clientFieldDefinitionTenantBranchClause('d');
        $sql = 'SELECT d.* FROM client_field_definitions d WHERE d.deleted_at IS NULL';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND d.branch_id = ?';
            $params[] = $branchId;
        }
        if ($onlyActive) {
            $sql .= ' AND d.is_active = 1';
        }
        $sql .= $tenant['sql'];
        $params = array_merge($params, $tenant['params']);
        $sql .= ' ORDER BY d.sort_order ASC, d.id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    public function find(int $id): ?array
    {
        $tenant = $this->orgScope->clientFieldDefinitionTenantBranchClause('d');
        $params = array_merge([$id], $tenant['params']);

        return $this->db->fetchOne(
            'SELECT d.* FROM client_field_definitions d WHERE d.id = ? AND d.deleted_at IS NULL' . $tenant['sql'],
            $params
        ) ?: null;
    }

    public function create(array $data): int
    {
        $allowed = [
            'branch_id',
            'field_key',
            'label',
            'field_type',
            'options_json',
            'is_required',
            'is_active',
            'sort_order',
            'created_by',
            'updated_by',
        ];
        $this->db->insert('client_field_definitions', array_intersect_key($data, array_flip($allowed)));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $allowed = [
            'branch_id',
            'field_key',
            'label',
            'field_type',
            'options_json',
            'is_required',
            'is_active',
            'sort_order',
            'updated_by',
        ];
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
        $tenant = $this->orgScope->clientFieldDefinitionTenantBranchClause('d');
        $vals = array_merge($vals, [$id], $tenant['params']);
        $this->db->query(
            'UPDATE client_field_definitions d SET ' . implode(', ', $cols) . ' WHERE d.id = ? AND d.deleted_at IS NULL' . $tenant['sql'],
            $vals
        );
    }

    public function softDelete(int $id, ?int $updatedBy): void
    {
        $tenant = $this->orgScope->clientFieldDefinitionTenantBranchClause('d');
        $params = array_merge([$updatedBy, $id], $tenant['params']);
        $this->db->query(
            'UPDATE client_field_definitions d SET d.deleted_at = NOW(), d.updated_by = ? WHERE d.id = ? AND d.deleted_at IS NULL' . $tenant['sql'],
            $params
        );
    }
}
