<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Tenant supplier semantics:
 *
 * | Class | Entry points |
 * | --- | --- |
 * | **1. Strict branch-owned** | {@see findInTenantScope}, {@see listInTenantScope}, {@see countInTenantScope}, {@see updateInTenantScope}, {@see softDeleteInTenantScope} |
 * | **3–4. Deprecated unscoped / id-only** | {@see find}, {@see list}, {@see count}, {@see update}, {@see softDelete} — **tenant modules must not call** (FND-TNT-23 readonly gate) |
 */
final class SupplierRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    )
    {
    }

    /**
     * @deprecated No org scope — tooling only. Prefer {@see findInTenantScope}.
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        $sql = 'SELECT * FROM suppliers WHERE id = ?';
        if (!$withTrashed) {
            $sql .= ' AND deleted_at IS NULL';
        }
        return $this->db->fetchOne($sql, [$id]);
    }

    public function findInTenantScope(int $id, int $branchId, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT s.* FROM suppliers s WHERE s.id = ? AND s.branch_id = ?';
        if (!$withTrashed) {
            $sql .= ' AND s.deleted_at IS NULL';
        }
        $sql .= $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id, $branchId], $frag['params']));
    }

    /**
     * @deprecated No org EXISTS. Prefer {@see listInTenantScope}.
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT * FROM suppliers WHERE deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (name LIKE ? OR contact_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $params = array_merge($params, [$q, $q, $q, $q]);
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $sql .= ' ORDER BY name LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function listInTenantScope(array $filters = [], int $branchId = 0, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT s.* FROM suppliers s WHERE s.deleted_at IS NULL AND s.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (s.name LIKE ? OR s.contact_name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';
            $params = array_merge($params, [$q, $q, $q, $q]);
        }

        $sql .= ' ORDER BY s.name LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @deprecated No org EXISTS. Prefer {@see countInTenantScope}.
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM suppliers WHERE deleted_at IS NULL';
        $params = [];

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (name LIKE ? OR contact_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
            $params = array_merge($params, [$q, $q, $q, $q]);
        }
        if (!empty($filters['branch_scope']) && $filters['branch_scope'] === 'global') {
            $sql .= ' AND branch_id IS NULL';
        } elseif (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }

        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function countInTenantScope(array $filters = [], int $branchId = 0): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $sql = 'SELECT COUNT(*) AS c FROM suppliers s WHERE s.deleted_at IS NULL AND s.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);

        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND (s.name LIKE ? OR s.contact_name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';
            $params = array_merge($params, [$q, $q, $q, $q]);
        }

        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('suppliers', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    /**
     * @deprecated Id-only WHERE — not tenant-safe. Prefer {@see updateInTenantScope}.
     */
    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) {
            return;
        }
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE suppliers SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    public function updateInTenantScope(int $id, int $branchId, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals[] = $branchId;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query(
            'UPDATE suppliers s SET ' . implode(', ', $cols) . ' WHERE s.id = ? AND s.branch_id = ?' . $frag['sql'],
            $vals
        );
    }

    /**
     * @deprecated Id-only WHERE — not tenant-safe. Prefer {@see softDeleteInTenantScope}.
     */
    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE suppliers SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    public function softDeleteInTenantScope(int $id, int $branchId): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $this->db->query(
            'UPDATE suppliers s SET s.deleted_at = NOW() WHERE s.id = ? AND s.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'name', 'contact_name', 'phone', 'email', 'address', 'notes',
            'branch_id', 'created_by', 'updated_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
