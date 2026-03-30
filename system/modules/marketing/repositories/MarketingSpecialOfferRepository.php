<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

final class MarketingSpecialOfferRepository
{
    private const TABLE = 'marketing_special_offers';
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
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [self::TABLE]
        );
        $this->storageReady = isset($row['ok']) && (int) $row['ok'] === 1;

        return $this->storageReady;
    }

    /**
     * @param array{name?:string,code?:string,origin?:string,adjustment_type?:string,offer_option?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function listForBranch(int $branchId, array $filters): array
    {
        if (!$this->isStorageReady()) {
            return [];
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('o');
        $sql = "SELECT o.*
                FROM marketing_special_offers o
                WHERE o.branch_id = ?
                  AND o.deleted_at IS NULL";
        $params = [$branchId];

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $sql .= ' AND o.name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        $code = trim((string) ($filters['code'] ?? ''));
        if ($code !== '') {
            $sql .= ' AND o.code LIKE ?';
            $params[] = '%' . $code . '%';
        }
        $origin = trim((string) ($filters['origin'] ?? ''));
        if ($origin !== '') {
            $sql .= ' AND o.origin = ?';
            $params[] = $origin;
        }
        $adj = trim((string) ($filters['adjustment_type'] ?? ''));
        if ($adj !== '') {
            $sql .= ' AND o.adjustment_type = ?';
            $params[] = $adj;
        }
        $opt = trim((string) ($filters['offer_option'] ?? ''));
        if ($opt !== '') {
            $sql .= ' AND o.offer_option = ?';
            $params[] = $opt;
        }

        $sql .= $frag['sql'] . ' ORDER BY o.sort_order ASC, o.id DESC LIMIT 500';
        $params = array_merge($params, $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->insert(self::TABLE, $data);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findInBranch(int $id, int $branchId): ?array
    {
        if (!$this->isStorageReady()) {
            return null;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('o');

        return $this->db->fetchOne(
            "SELECT o.*
             FROM marketing_special_offers o
             WHERE o.id = ?
               AND o.branch_id = ?
               AND o.deleted_at IS NULL" . $frag['sql'] . '
             LIMIT 1',
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    public function codeExistsInBranch(int $branchId, string $codeUpper, ?int $excludeId = null): bool
    {
        if (!$this->isStorageReady()) {
            return false;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('o');
        $sql = "SELECT o.id
                FROM marketing_special_offers o
                WHERE o.branch_id = ?
                  AND o.deleted_at IS NULL
                  AND UPPER(o.code) = ?";
        $params = [$branchId, $codeUpper];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND o.id <> ?';
            $params[] = $excludeId;
        }
        $sql .= $frag['sql'] . ' LIMIT 1';

        return $this->db->fetchOne($sql, array_merge($params, $frag['params'])) !== null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function updateInBranch(int $id, int $branchId, array $data): bool
    {
        if (!$this->isStorageReady()) {
            return false;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('o');
        $sql = "UPDATE marketing_special_offers o
                SET o.name = ?,
                    o.code = ?,
                    o.origin = ?,
                    o.adjustment_type = ?,
                    o.adjustment_value = ?,
                    o.offer_option = ?,
                    o.start_date = ?,
                    o.end_date = ?,
                    o.is_active = ?,
                    o.updated_by = ?
                WHERE o.id = ?
                  AND o.branch_id = ?
                  AND o.deleted_at IS NULL" . $frag['sql'];
        $params = [
            $data['name'] ?? '',
            $data['code'] ?? '',
            $data['origin'] ?? 'manual',
            $data['adjustment_type'] ?? 'percent',
            $data['adjustment_value'] ?? 0,
            $data['offer_option'] ?? 'all',
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            (int) ($data['is_active'] ?? 0),
            $data['updated_by'] ?? null,
            $id,
            $branchId,
        ];
        $result = $this->db->query($sql, array_merge($params, $frag['params']));

        return $result->rowCount() > 0;
    }

    public function setActiveInBranch(int $id, int $branchId, bool $active, ?int $userId): bool
    {
        if ($active) {
            throw new \InvalidArgumentException(
                'Special offers cannot be activated: no invoice/booking/checkout pricing consumer is wired (H-006 runtime truth).'
            );
        }
        if (!$this->isStorageReady()) {
            return false;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('o');
        $result = $this->db->query(
            "UPDATE marketing_special_offers o
             SET o.is_active = ?,
                 o.updated_by = ?
             WHERE o.id = ?
               AND o.branch_id = ?
               AND o.deleted_at IS NULL" . $frag['sql'],
            array_merge([(int) $active, $userId, $id, $branchId], $frag['params'])
        );

        return $result->rowCount() > 0;
    }

    public function nextSortOrder(int $branchId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(MAX(sort_order), 0) AS m FROM marketing_special_offers WHERE branch_id = ? AND deleted_at IS NULL',
            [$branchId]
        );

        return ((int) ($row['m'] ?? 0)) + 1;
    }

    public function softDeleteInBranch(int $id, int $branchId, ?int $userId): void
    {
        if (!$this->isStorageReady()) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('o');
        $this->db->query(
            "UPDATE marketing_special_offers o
             SET o.deleted_at = NOW(),
                 o.is_active = 0,
                 o.updated_by = ?
             WHERE o.id = ?
               AND o.branch_id = ?
               AND o.deleted_at IS NULL" . $frag['sql'],
            array_merge([$userId, $id, $branchId], $frag['params'])
        );
    }

}

