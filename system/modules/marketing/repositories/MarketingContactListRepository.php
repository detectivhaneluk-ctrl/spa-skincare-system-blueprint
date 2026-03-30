<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class MarketingContactListRepository
{
    private const TABLE_LISTS = 'marketing_contact_lists';
    private const TABLE_MEMBERS = 'marketing_contact_list_members';

    private ?bool $storageReady = null;

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function isStorageReady(): bool
    {
        if ($this->storageReady !== null) {
            return $this->storageReady;
        }
        $lists = $this->tableExists(self::TABLE_LISTS);
        $members = $this->tableExists(self::TABLE_MEMBERS);
        $this->storageReady = $lists && $members;

        return $this->storageReady;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveForBranch(int $branchId): array
    {
        if (!$this->isStorageReady()) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = "SELECT l.*
                FROM marketing_contact_lists l
                WHERE l.archived_at IS NULL
                  AND l.branch_id = ?" . $frag['sql'] . "
                ORDER BY l.name ASC, l.id ASC";
        $params = array_merge([$branchId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    public function findActiveForBranch(int $listId, int $branchId): ?array
    {
        if (!$this->isStorageReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = "SELECT l.*
                FROM marketing_contact_lists l
                WHERE l.id = ?
                  AND l.branch_id = ?
                  AND l.archived_at IS NULL" . $frag['sql'] . '
                LIMIT 1';
        $params = array_merge([$listId, $branchId], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    public function create(int $branchId, string $name, ?int $userId): int
    {
        if (!$this->isStorageReady()) {
            throw new \DomainException('Marketing Contact Lists storage is not initialized. Run migrations.');
        }
        $this->db->insert('marketing_contact_lists', [
            'branch_id' => $branchId,
            'name' => $name,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function rename(int $listId, int $branchId, string $name, ?int $userId): void
    {
        if (!$this->isStorageReady()) {
            throw new \DomainException('Marketing Contact Lists storage is not initialized. Run migrations.');
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = "UPDATE marketing_contact_lists l
                SET l.name = ?, l.updated_by = ?
                WHERE l.id = ?
                  AND l.branch_id = ?
                  AND l.archived_at IS NULL" . $frag['sql'];
        $params = array_merge([$name, $userId, $listId, $branchId], $frag['params']);
        $this->db->query($sql, $params);
    }

    public function archive(int $listId, int $branchId, ?int $userId): void
    {
        if (!$this->isStorageReady()) {
            throw new \DomainException('Marketing Contact Lists storage is not initialized. Run migrations.');
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = "UPDATE marketing_contact_lists l
                SET l.archived_at = NOW(), l.archived_by = ?, l.updated_by = ?
                WHERE l.id = ?
                  AND l.branch_id = ?
                  AND l.archived_at IS NULL" . $frag['sql'];
        $params = array_merge([$userId, $userId, $listId, $branchId], $frag['params']);
        $this->db->query($sql, $params);
    }

    /**
     * @param list<int> $clientIds
     */
    public function addMembers(int $listId, int $branchId, array $clientIds, ?int $userId): void
    {
        if (!$this->isStorageReady()) {
            throw new \DomainException('Marketing Contact Lists storage is not initialized. Run migrations.');
        }
        $clientIds = $this->normalizeIds($clientIds);
        if ($clientIds === []) {
            return;
        }

        foreach ($clientIds as $clientId) {
            $lfrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
            $cfrag = $this->orgScope->clientMarketingBranchScopedOrBranchlessTenantMemberClause('c', $branchId);
            $sql = "INSERT INTO marketing_contact_list_members (list_id, client_id, created_by)
                    SELECT l.id, c.id, ?
                    FROM marketing_contact_lists l
                    INNER JOIN clients c ON c.id = ?
                    WHERE l.id = ?
                      AND l.branch_id = ?
                      AND l.archived_at IS NULL
                      AND c.deleted_at IS NULL
                      AND c.merged_into_client_id IS NULL" . $lfrag['sql'] . $cfrag['sql'] . '
                    ON DUPLICATE KEY UPDATE created_by = created_by';
            $params = array_merge([$userId, $clientId, $listId, $branchId], $lfrag['params'], $cfrag['params']);
            $this->db->query($sql, $params);
        }
    }

    /**
     * @param list<int> $clientIds
     */
    public function removeMembers(int $listId, int $branchId, array $clientIds): void
    {
        if (!$this->isStorageReady()) {
            throw new \DomainException('Marketing Contact Lists storage is not initialized. Run migrations.');
        }
        $clientIds = $this->normalizeIds($clientIds);
        if ($clientIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $lfrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = "DELETE m
                FROM marketing_contact_list_members m
                INNER JOIN marketing_contact_lists l ON l.id = m.list_id
                WHERE m.list_id = ?
                  AND m.client_id IN ({$placeholders})
                  AND l.branch_id = ?
                  AND l.archived_at IS NULL" . $lfrag['sql'];
        $params = array_merge([$listId], $clientIds, [$branchId], $lfrag['params']);
        $this->db->query($sql, $params);
    }

    /**
     * @return array<int, int>
     */
    /**
     * Contact lists that include this client (current branch, non-archived lists).
     *
     * @return list<array{list_id: int, list_name: string, member_since: string|null}>
     */
    public function listMembershipsForClient(int $clientId, int $branchId): array
    {
        if (!$this->isStorageReady() || $clientId <= 0 || $branchId <= 0) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = 'SELECT l.id AS list_id, l.name AS list_name, m.created_at AS member_since
                FROM marketing_contact_list_members m
                INNER JOIN marketing_contact_lists l ON l.id = m.list_id
                WHERE m.client_id = ?
                  AND l.branch_id = ?
                  AND l.archived_at IS NULL' . $frag['sql'] . '
                ORDER BY l.name ASC, l.id ASC';
        $params = array_merge([$clientId, $branchId], $frag['params']);
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'list_id' => (int) ($row['list_id'] ?? 0),
                'list_name' => (string) ($row['list_name'] ?? ''),
                'member_since' => isset($row['member_since']) && $row['member_since'] !== ''
                    ? (string) $row['member_since']
                    : null,
            ];
        }

        return $out;
    }

    public function memberCountsByListIds(int $branchId, array $listIds): array
    {
        if (!$this->isStorageReady()) {
            return [];
        }
        $listIds = $this->normalizeIds($listIds);
        if ($listIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($listIds), '?'));
        $lfrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
        $sql = "SELECT l.id AS list_id, COUNT(c.id) AS c
                FROM marketing_contact_lists l
                LEFT JOIN marketing_contact_list_members m ON m.list_id = l.id
                LEFT JOIN clients c ON c.id = m.client_id AND c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
                WHERE l.branch_id = ?
                  AND l.archived_at IS NULL
                  AND l.id IN ({$placeholders})" . $lfrag['sql'] . '
                GROUP BY l.id';
        $params = array_merge([$branchId], $listIds, $lfrag['params']);
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row['list_id'] ?? 0)] = (int) ($row['c'] ?? 0);
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $v = (int) $id;
            if ($v > 0) {
                $out[] = $v;
            }
        }
        $out = array_values(array_unique($out));

        return $out;
    }

    private function tableExists(string $tableName): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$tableName]
        );

        return isset($row['ok']) && (int) $row['ok'] === 1;
    }
}

