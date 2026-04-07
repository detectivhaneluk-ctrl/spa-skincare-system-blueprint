<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class ServiceCategoryRepository
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
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('sc');

        return $this->db->fetchOne(
            'SELECT sc.* FROM service_categories sc WHERE sc.id = ? AND sc.deleted_at IS NULL AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
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

    /**
     * Tenant-safe update: only mutates rows that belong to the resolved tenant org.
     */
    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('sc');
        $cols = array_map(fn (string $k): string => "sc.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $tenant['params']);
        $this->db->query(
            'UPDATE service_categories sc SET ' . implode(', ', $cols) . ' WHERE sc.id = ? AND (' . $tenant['sql'] . ')',
            $vals
        );
    }

    /**
     * Tenant-safe soft delete: only soft-deletes rows that belong to the resolved tenant org.
     */
    public function softDelete(int $id): void
    {
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('sc');
        $this->db->query(
            'UPDATE service_categories sc SET sc.deleted_at = NOW() WHERE sc.id = ? AND (' . $tenant['sql'] . ')',
            array_merge([$id], $tenant['params'])
        );
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

    /**
     * Like list() but annotates each row with:
     *   - service_count  INT  number of live services assigned to this category
     *   - child_count    INT  number of live direct children
     *
     * One query using scalar subqueries — no N+1.
     */
    public function listWithCounts(?int $branchId = null): array
    {
        $params = [];
        $branchClause = '';
        if ($branchId !== null) {
            $branchClause = ' AND (sc.branch_id = ? OR sc.branch_id IS NULL)';
            $params[] = $branchId;
        }

        $sql = "
            SELECT sc.*,
                (SELECT COUNT(*) FROM services s
                    WHERE s.category_id = sc.id
                      AND s.deleted_at IS NULL) AS service_count,
                (SELECT COUNT(*) FROM service_categories ch
                    WHERE ch.parent_id = sc.id
                      AND ch.deleted_at IS NULL) AS child_count
            FROM service_categories sc
            WHERE sc.deleted_at IS NULL{$branchClause}
            ORDER BY sc.sort_order, sc.name
        ";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Return null if category can be safely deleted, or a human-readable reason string if blocked.
     */
    public function getDeleteBlockReason(int $id): ?string
    {
        $childCount = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM service_categories WHERE parent_id = ? AND deleted_at IS NULL',
            [$id]
        )['n'] ?? 0);

        if ($childCount > 0) {
            return "Cannot delete: this category has {$childCount} child " . ($childCount === 1 ? 'category' : 'categories') . '. Remove or re-parent children first.';
        }

        $svcCount = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS n FROM services WHERE category_id = ? AND deleted_at IS NULL',
            [$id]
        )['n'] ?? 0);

        if ($svcCount > 0) {
            return "Cannot delete: {$svcCount} " . ($svcCount === 1 ? 'service is' : 'services are') . ' assigned to this category. Reassign them first.';
        }

        return null;
    }

    /**
     * Update only the sort_order of a category (tenant-safe).
     */
    public function updateSortOrder(int $id, int $sortOrder): void
    {
        $tenant = $this->orgScope->taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('sc');
        $this->db->query(
            'UPDATE service_categories sc SET sc.sort_order = ? WHERE sc.id = ? AND (' . $tenant['sql'] . ')',
            array_merge([$sortOrder, $id], $tenant['params'])
        );
    }

    /**
     * Build a flat ordered list of all categories with computed `depth` and `path` for display.
     * Ordering: root → children → grandchildren (DFS preorder), stable within siblings by sort_order then name.
     *
     * @param array<int,array<string,mixed>> $allRows  All live rows keyed by id (from list()).
     * @return list<array<string,mixed>>  Same rows with added keys: depth (int), path (string).
     */
    public function buildTreeFlat(array $allRows): array
    {
        // Index by id
        $byId = [];
        foreach ($allRows as $r) {
            $byId[(int) $r['id']] = $r;
        }

        // Group children by parent_id
        $children = []; // parent_id (or 0 for roots) => [child rows]
        foreach ($byId as $r) {
            $pid = isset($r['parent_id']) && $r['parent_id'] !== null && $r['parent_id'] !== '' ? (int) $r['parent_id'] : 0;
            $children[$pid][] = $r;
        }

        // Sort each group by sort_order then name
        foreach ($children as &$grp) {
            usort($grp, fn ($a, $b) =>
                ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0)
                ?: strcmp($a['name'] ?? '', $b['name'] ?? '')
            );
        }
        unset($grp);

        // DFS traversal
        $result = [];
        $this->dfsTree($children, 0, 0, [], $result);

        return $result;
    }

    /**
     * Return ancestor chain (root first) for a given category ID.
     *
     * @param array<int,array<string,mixed>> $byId  All rows indexed by id.
     * @return list<array<string,mixed>>
     */
    public function ancestorChain(int $categoryId, array $byId): array
    {
        $chain = [];
        $current = $byId[$categoryId] ?? null;
        $guard = 0;
        while ($current !== null && $guard++ < 64) {
            array_unshift($chain, $current);
            $pid = isset($current['parent_id']) && $current['parent_id'] !== null && $current['parent_id'] !== '' ? (int) $current['parent_id'] : null;
            $current = ($pid !== null && isset($byId[$pid])) ? $byId[$pid] : null;
        }
        return $chain;
    }

    /**
     * Return descendant IDs (all depths) for given category ID — used to exclude from parent picker.
     *
     * @param array<int,array<string,mixed>> $byId
     * @return list<int>
     */
    public function descendantIds(int $categoryId, array $byId): array
    {
        $result = [];
        $stack = [$categoryId];
        $guard = 0;
        while (!empty($stack) && $guard++ < 1000) {
            $cur = array_pop($stack);
            foreach ($byId as $id => $row) {
                $pid = isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null;
                if ($pid === $cur) {
                    $result[] = $id;
                    $stack[] = $id;
                }
            }
        }
        return $result;
    }

    private function dfsTree(array $children, int $parentId, int $depth, array $ancestorNames, array &$result): void
    {
        if (!isset($children[$parentId])) {
            return;
        }
        foreach ($children[$parentId] as $row) {
            $id = (int) $row['id'];
            $pathNames = array_merge($ancestorNames, [$row['name'] ?? '']);
            $row['depth'] = $depth;
            $row['path'] = implode(' › ', $pathNames);
            $result[] = $row;
            $this->dfsTree($children, $id, $depth + 1, $pathNames, $result);
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
