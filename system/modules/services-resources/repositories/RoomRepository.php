<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class RoomRepository
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
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('r');

        return $this->db->fetchOne(
            'SELECT r.* FROM rooms r WHERE r.id = ? AND r.deleted_at IS NULL AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
    }

    public function list(?int $branchId = null): array
    {
        $sql = 'SELECT * FROM rooms WHERE deleted_at IS NULL';
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
        $this->db->insert('rooms', $this->normalize($data));

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
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('r');
        $cols = array_map(fn (string $k): string => "r.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $tenant['params']);
        $this->db->query(
            'UPDATE rooms r SET ' . implode(', ', $cols) . ' WHERE r.id = ? AND (' . $tenant['sql'] . ')',
            $vals
        );
    }

    /**
     * Tenant-safe soft delete: only soft-deletes rows that belong to the resolved tenant org.
     */
    public function softDelete(int $id): void
    {
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('r');
        $this->db->query(
            'UPDATE rooms r SET r.deleted_at = NOW() WHERE r.id = ? AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
    }

    private function normalize(array $data): array
    {
        $allowed = ['name', 'code', 'is_active', 'maintenance_mode', 'branch_id'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (isset($out['is_active'])) $out['is_active'] = $out['is_active'] ? 1 : 0;
        if (isset($out['maintenance_mode'])) $out['maintenance_mode'] = $out['maintenance_mode'] ? 1 : 0;
        return $out;
    }
}
