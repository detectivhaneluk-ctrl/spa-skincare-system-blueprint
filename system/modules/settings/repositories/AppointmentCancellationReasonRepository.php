<?php

declare(strict_types=1);

namespace Modules\Settings\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationContext;

final class AppointmentCancellationReasonRepository
{
    private ?bool $tableAvailable = null;

    public function __construct(private Database $db, private OrganizationContext $organizationContext)
    {
    }

    public function isTableAvailable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['appointment_cancellation_reasons']
        );
        $this->tableAvailable = $row !== null;

        return $this->tableAvailable;
    }

    public function organizationId(): int
    {
        $organizationId = (int) ($this->organizationContext->getCurrentOrganizationId() ?? 0);
        if ($organizationId <= 0) {
            throw new \RuntimeException('Organization context is required.');
        }

        return $organizationId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByOrganization(int $organizationId, bool $activeOnly = false): array
    {
        if (!$this->isTableAvailable()) {
            return [];
        }
        $sql = 'SELECT id, organization_id, branch_id, code, name, applies_to, sort_order, is_active, created_at, updated_at
                FROM appointment_cancellation_reasons
                WHERE organization_id = ? AND branch_id = 0 AND deleted_at IS NULL';
        $params = [$organizationId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    public function findById(int $organizationId, int $id): ?array
    {
        if (!$this->isTableAvailable()) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT id, organization_id, branch_id, code, name, applies_to, sort_order, is_active, created_at, updated_at
             FROM appointment_cancellation_reasons
             WHERE id = ? AND organization_id = ? AND branch_id = 0 AND deleted_at IS NULL
             LIMIT 1',
            [$id, $organizationId]
        );
    }

    public function findActiveByIdAndAppliesTo(int $organizationId, int $id, string $appliesTo): ?array
    {
        if (!$this->isTableAvailable()) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT id, organization_id, branch_id, code, name, applies_to, sort_order, is_active
             FROM appointment_cancellation_reasons
             WHERE id = ?
               AND organization_id = ?
               AND branch_id = 0
               AND deleted_at IS NULL
               AND is_active = 1
               AND (applies_to = ? OR applies_to = ?)
             LIMIT 1',
            [$id, $organizationId, $appliesTo, 'both']
        );
    }

    public function codeExists(int $organizationId, string $code, ?int $excludeId = null): bool
    {
        if (!$this->isTableAvailable()) {
            return false;
        }
        $sql = 'SELECT id
                FROM appointment_cancellation_reasons
                WHERE organization_id = ?
                  AND branch_id = 0
                  AND code = ?
                  AND deleted_at IS NULL';
        $params = [$organizationId, $code];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        return $this->db->fetchOne($sql, $params) !== null;
    }

    public function insert(array $row): int
    {
        $this->db->insert('appointment_cancellation_reasons', $row);

        return $this->db->lastInsertId();
    }

    public function update(int $organizationId, int $id, array $row): void
    {
        $set = [];
        $params = [];
        foreach ($row as $k => $v) {
            $set[] = $k . ' = ?';
            $params[] = $v;
        }
        if ($set === []) {
            return;
        }
        $set[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $organizationId;
        $this->db->query(
            'UPDATE appointment_cancellation_reasons
             SET ' . implode(', ', $set) . '
             WHERE id = ? AND organization_id = ? AND branch_id = 0 AND deleted_at IS NULL',
            $params
        );
    }

    public function softDelete(int $organizationId, int $id): void
    {
        $this->db->query(
            'UPDATE appointment_cancellation_reasons
             SET deleted_at = NOW(), updated_at = NOW()
             WHERE id = ? AND organization_id = ? AND branch_id = 0 AND deleted_at IS NULL',
            [$id, $organizationId]
        );
    }
}

