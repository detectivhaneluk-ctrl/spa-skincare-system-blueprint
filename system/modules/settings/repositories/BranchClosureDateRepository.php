<?php

declare(strict_types=1);

namespace Modules\Settings\Repositories;

use Core\App\Database;

final class BranchClosureDateRepository
{
    private ?bool $tableAvailable = null;

    public function __construct(private Database $db)
    {
    }

    public function isTableAvailable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['branch_closure_dates']
        );
        $this->tableAvailable = $row !== null;

        return $this->tableAvailable;
    }

    /**
     * @return list<array{id:int,branch_id:int,closure_date:string,title:string,notes:?string,created_by:?int,created_at:?string,updated_at:?string}>
     */
    public function listByBranch(int $branchId): array
    {
        if (!$this->isTableAvailable()) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id, branch_id, closure_date, title, notes, created_by, created_at, updated_at
             FROM branch_closure_dates
             WHERE branch_id = ? AND deleted_at IS NULL
             ORDER BY closure_date ASC, id ASC',
            [$branchId]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->normalizeRow($row);
        }

        return $out;
    }

    /**
     * Branch-scoped read: {@code branch_id} predicate ensures the row belongs to the caller's branch
     * before update/delete mutations are attempted. Callers must pass the session-resolved branch id.
     */
    public function findLiveByIdForBranch(int $id, int $branchId): ?array
    {
        if (!$this->isTableAvailable() || $id <= 0 || $branchId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id, branch_id, closure_date, title, notes, created_by, created_at, updated_at
             FROM branch_closure_dates
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL
             LIMIT 1',
            [$id, $branchId]
        );
        if (!is_array($row)) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    public function existsLiveDateForBranch(int $branchId, string $closureDate, ?int $excludeId = null): bool
    {
        if (!$this->isTableAvailable()) {
            return false;
        }

        $sql = 'SELECT id
                FROM branch_closure_dates
                WHERE branch_id = ?
                  AND closure_date = ?
                  AND deleted_at IS NULL';
        $params = [$branchId, $closureDate];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    /**
     * @param array{branch_id:int,closure_date:string,title:string,notes:?string,created_by:?int} $data
     */
    public function create(array $data): int
    {
        if (!$this->isTableAvailable()) {
            throw new \RuntimeException('Closure Dates storage is unavailable. Apply migration 093_create_branch_closure_dates_table.sql.');
        }

        $this->db->insert('branch_closure_dates', [
            'branch_id' => $data['branch_id'],
            'closure_date' => $data['closure_date'],
            'title' => $data['title'],
            'notes' => $data['notes'],
            'created_by' => $data['created_by'],
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Branch-scoped mutation: {@code branch_id} predicate prevents cross-branch writes.
     *
     * @param array{closure_date:string,title:string,notes:?string} $data
     */
    public function updateLive(int $id, int $branchId, array $data): void
    {
        if (!$this->isTableAvailable()) {
            throw new \RuntimeException('Closure Dates storage is unavailable. Apply migration 093_create_branch_closure_dates_table.sql.');
        }

        $this->db->query(
            'UPDATE branch_closure_dates
             SET closure_date = ?, title = ?, notes = ?, updated_at = NOW()
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL',
            [$data['closure_date'], $data['title'], $data['notes'], $id, $branchId]
        );
    }

    public function softDeleteLive(int $id, int $branchId): void
    {
        if (!$this->isTableAvailable()) {
            throw new \RuntimeException('Closure Dates storage is unavailable. Apply migration 093_create_branch_closure_dates_table.sql.');
        }

        $this->db->query(
            'UPDATE branch_closure_dates
             SET deleted_at = NOW(), updated_at = NOW()
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL',
            [$id, $branchId]
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,branch_id:int,closure_date:string,title:string,notes:?string,created_by:?int,created_at:?string,updated_at:?string}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'branch_id' => (int) ($row['branch_id'] ?? 0),
            'closure_date' => (string) ($row['closure_date'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_by' => isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) && $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }
}
