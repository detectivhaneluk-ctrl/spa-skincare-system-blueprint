<?php

declare(strict_types=1);

namespace Modules\Packages\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Core\Repository\RepositoryContractGuard;

final class PackageRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Package usable from a branch: branch-owned row for pinned branch + org only (NULL-branch rows excluded).
     */
    public function findInTenantScope(int $id, int $branchId, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT p.* FROM packages p WHERE p.id = ?
            AND p.branch_id IS NOT NULL AND p.branch_id = ?' . $frag['sql'];
        if (!$withTrashed) {
            $sql .= ' AND p.deleted_at IS NULL';
        }
        $params = array_merge([$id, $branchId], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInTenantScope(array $filters, int $branchId, int $limit = 50, int $offset = 0): array
    {
        $limit = (int) $limit;
        $offset = (int) $offset;
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT p.* FROM packages p WHERE p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND p.name LIKE ?';
            $params[] = $q;
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = ?';
            $params[] = $filters['status'];
        }
        $sql .= ' ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function countInTenantScope(array $filters, int $branchId): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT COUNT(*) AS c FROM packages p WHERE p.deleted_at IS NULL AND p.branch_id = ?' . $frag['sql'];
        $params = array_merge([$branchId], $frag['params']);
        if (!empty($filters['search'])) {
            $q = '%' . trim((string) $filters['search']) . '%';
            $sql .= ' AND p.name LIKE ?';
            $params[] = $q;
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = ?';
            $params[] = $filters['status'];
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function updateInTenantScope(int $id, int $branchId, array $data): void
    {
        $norm = $this->normalize($data);
        if ($norm === []) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $cols = array_map(fn ($k) => "p.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals[] = $branchId;
        $this->db->query(
            'UPDATE packages p SET ' . implode(', ', $cols) . ' WHERE p.id = ? AND p.branch_id IS NOT NULL AND p.branch_id = ?' . $frag['sql'],
            array_merge($vals, $frag['params'])
        );
    }

    public function softDeleteInTenantScope(int $id, int $branchId): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $this->db->query(
            'UPDATE packages p SET p.deleted_at = NOW() WHERE p.id = ? AND p.branch_id IS NOT NULL AND p.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveAssignableInTenantScope(int $branchId, int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('p');
        $sql = 'SELECT p.* FROM packages p
                WHERE p.deleted_at IS NULL AND p.status = ?
                  AND p.branch_id IS NOT NULL AND p.branch_id = ?' . $frag['sql'] . '
                ORDER BY p.name ASC LIMIT ' . $limit;
        $params = array_merge(['active', $branchId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Compatibility-only legacy generic read. Mixed-semantics primary-key reads are locked fail-closed;
     * use {@see findInTenantScope()} or {@see findBranchOwnedPublicPurchasable()}.
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('PackageRepository::find', ['findInTenantScope', 'findBranchOwnedPublicPurchasable']);
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('PackageRepository::list', ['listInTenantScope']);
    }

    public function count(array $filters = []): int
    {
        RepositoryContractGuard::denyMixedSemanticsApi('PackageRepository::count', ['countInTenantScope']);
    }

    public function create(array $data): int
    {
        $this->db->insert('packages', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        RepositoryContractGuard::denyMixedSemanticsApi('PackageRepository::update', ['updateInTenantScope']);
    }

    public function softDelete(int $id): void
    {
        RepositoryContractGuard::denyMixedSemanticsApi('PackageRepository::softDelete', ['softDeleteInTenantScope']);
    }

    /**
     * Active packages eligible for anonymous public commerce: branch-owned only, explicit flag, positive price.
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicPurchasableForBranch(int $branchId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, name, description, total_sessions, validity_days, price, branch_id
                FROM packages
                WHERE deleted_at IS NULL
                  AND status = ?
                  AND public_online_eligible = 1
                  AND price IS NOT NULL
                  AND price > 0
                  AND branch_id = ?';
        $params = ['active', $branchId];
        $sql .= ' ORDER BY name ASC, id ASC LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Anonymous public purchase validation: branch-owned, active, online-eligible, priced (no org-context dependency).
     *
     * @return array<string, mixed>|null
     */
    public function findBranchOwnedPublicPurchasable(int $id, int $branchId): ?array
    {
        if ($id <= 0 || $branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM packages
             WHERE id = ?
               AND deleted_at IS NULL
               AND status = ?
               AND public_online_eligible = 1
               AND price IS NOT NULL AND price > 0
               AND branch_id = ?',
            [$id, 'active', $branchId]
        ) ?: null;
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'branch_id', 'name', 'description', 'status', 'total_sessions', 'validity_days',
            'price', 'public_online_eligible', 'created_by', 'updated_by', 'deleted_at',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
