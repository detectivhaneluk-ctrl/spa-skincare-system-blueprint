<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `marketing_automations` access. Reads are org-scoped through branch ownership.
 */
final class MarketingAutomationRepository
{
    private const TABLE_NAME = 'marketing_automations';

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function isStorageReady(): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [self::TABLE_NAME]
        );

        return isset($row['ok']) && (int) $row['ok'] === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByBranch(int $branchId): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ma', 'branch_id');
        $sql = 'SELECT ma.* FROM marketing_automations ma
            WHERE ma.branch_id = ?' . $frag['sql'] . '
            ORDER BY ma.automation_key ASC';
        $params = array_merge([$branchId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    public function findByBranchAndKey(int $branchId, string $automationKey): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ma', 'branch_id');
        $sql = 'SELECT ma.* FROM marketing_automations ma
            WHERE ma.branch_id = ? AND ma.automation_key = ?' . $frag['sql'] . '
            LIMIT 1';
        $params = array_merge([$branchId, $automationKey], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    public function upsert(int $branchId, string $automationKey, bool $enabled, ?string $configJson): void
    {
        $this->db->query(
            'INSERT INTO marketing_automations (branch_id, automation_key, enabled, config_json)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config_json = VALUES(config_json), updated_at = CURRENT_TIMESTAMP',
            [$branchId, $automationKey, $enabled ? 1 : 0, $configJson]
        );
    }
}
