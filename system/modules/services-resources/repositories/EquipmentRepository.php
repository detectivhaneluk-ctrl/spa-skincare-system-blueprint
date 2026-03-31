<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class EquipmentRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Tenant-safe id read: row must belong to the resolved tenant org (branch-owned or org-global-null).
     */
    public function find(int $id): ?array
    {
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('eq');

        return $this->db->fetchOne(
            'SELECT eq.* FROM equipment eq WHERE eq.id = ? AND eq.deleted_at IS NULL AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
    }

    public function list(?int $branchId = null): array
    {
        $sql = 'SELECT * FROM equipment WHERE deleted_at IS NULL';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY name';

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->db->insert('equipment', $this->normalize($data));

        return $this->db->lastInsertId();
    }

    /**
     * Tenant-safe update: only mutates rows that belong to the resolved tenant org.
     */
    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('eq');
        $cols = array_map(fn (string $k): string => "eq.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $tenant['params']);
        $this->db->query(
            'UPDATE equipment eq SET ' . implode(', ', $cols) . ' WHERE eq.id = ? AND (' . $tenant['sql'] . ')',
            $vals
        );
    }

    /**
     * Tenant-safe soft delete: only soft-deletes rows that belong to the resolved tenant org.
     */
    public function softDelete(int $id): void
    {
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('eq');
        $this->db->query(
            'UPDATE equipment eq SET eq.deleted_at = NOW() WHERE eq.id = ? AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
    }

    private function normalize(array $data): array
    {
        $allowed = ['name', 'code', 'serial_number', 'is_active', 'maintenance_mode', 'branch_id'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (isset($out['is_active'])) $out['is_active'] = $out['is_active'] ? 1 : 0;
        if (isset($out['maintenance_mode'])) $out['maintenance_mode'] = $out['maintenance_mode'] ? 1 : 0;
        return $out;
    }
}
