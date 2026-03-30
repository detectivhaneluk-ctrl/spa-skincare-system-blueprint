<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;

final class ServiceCategoryRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM service_categories WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public function list(?int $branchId = null): array
    {
        $sql = 'SELECT * FROM service_categories WHERE deleted_at IS NULL';
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND (branch_id = ? OR branch_id IS NULL)';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY sort_order, name';

        return $this->db->fetchAll($sql, $params);
    }

    public function create(array $data): int
    {
        $this->db->insert('service_categories', $this->normalize($data));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($this->normalize($data)));
        $vals = array_values($this->normalize($data));
        $vals[] = $id;
        $this->db->query('UPDATE service_categories SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    public function softDelete(int $id): void
    {
        $this->db->query('UPDATE service_categories SET deleted_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * True when walking ancestors from {@param $startParentId} reaches {@param $needleId} (cycle if needle is the category being edited).
     */
    public function ancestorChainContainsId(?int $startParentId, int $needleId): bool
    {
        if ($startParentId === null) {
            return false;
        }
        $current = $startParentId;
        $guard = 0;
        while ($current !== null && $guard++ < 64) {
            if ($current === $needleId) {
                return true;
            }
            $row = $this->find($current);
            if ($row === null) {
                return false;
            }
            $pb = $row['parent_id'] ?? null;
            $current = ($pb !== null && $pb !== '') ? (int) $pb : null;
        }

        return false;
    }

    /**
     * @throws \InvalidArgumentException when parent is missing, self-parent, or introduces a cycle
     */
    public function assertValidParentAssignment(?int $categoryId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($parentId <= 0) {
            throw new \InvalidArgumentException('Invalid parent category.');
        }
        if ($categoryId !== null && $parentId === $categoryId) {
            throw new \InvalidArgumentException('Service category cannot be its own parent.');
        }
        $parent = $this->find($parentId);
        if ($parent === null) {
            throw new \InvalidArgumentException('Parent category not found.');
        }
        if ($categoryId !== null && $this->ancestorChainContainsId($parentId, $categoryId)) {
            throw new \InvalidArgumentException('Invalid parent: would create a cycle in service categories.');
        }
    }

    private function normalize(array $data): array
    {
        $allowed = ['name', 'sort_order', 'branch_id', 'parent_id'];
        $out = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('parent_id', $out)) {
            if ($out['parent_id'] === '' || $out['parent_id'] === null) {
                $out['parent_id'] = null;
            } else {
                $out['parent_id'] = (int) $out['parent_id'];
            }
        }

        return $out;
    }
}
