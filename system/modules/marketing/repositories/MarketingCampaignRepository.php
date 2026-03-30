<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\App\SqlIdentifier;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `marketing_campaigns` access. Staff reads/mutations use explicit {@see OrganizationRepositoryScope} fragments
 * (fail-closed when context is not branch-derived). **insert** has no org clause in this repository; callers/services
 * enforce branch when creating rows.
 */
final class MarketingCampaignRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function findInTenantScopeForStaff(int $id): ?array
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('mc');
        $sql = 'SELECT mc.* FROM marketing_campaigns mc WHERE mc.id = ?' . $frag['sql'];
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * Index list with aggregate send metrics from outbound rows linked to campaign recipients (opens/clicks not stored).
     *
     * @param array{branch_id?: int|null, status?: string|null, channel?: string|null, q?: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function listForIndexRead(array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $agg = $this->indexAggregateJoinSql();
        $fragMc = $this->orgScope->marketingCampaignBranchOrgExistsClause('mc');
        $sql = 'SELECT mc.*, COALESCE(agg.sent_count, 0) AS index_sent_count, agg.last_sent_at AS index_last_sent_at
            FROM marketing_campaigns mc
            LEFT JOIN ' . $agg['sql'] . ' ON agg.campaign_id = mc.id
            WHERE 1=1';
        $params = [...$agg['params']];
        $this->appendIndexListFilters($sql, $params, $filters);
        $sql .= $fragMc['sql'];
        $params = array_merge($params, $fragMc['params']);
        $sql .= ' ORDER BY mc.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array{branch_id?: int|null, status?: string|null, channel?: string|null, q?: string|null} $filters
     */
    public function countForIndexRead(array $filters): int
    {
        $fragMc = $this->orgScope->marketingCampaignBranchOrgExistsClause('mc');
        $sql = 'SELECT COUNT(*) AS c FROM marketing_campaigns mc WHERE 1=1';
        $params = [];
        $this->appendIndexListFilters($sql, $params, $filters);
        $sql .= $fragMc['sql'];
        $params = array_merge($params, $fragMc['params']);
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function indexAggregateJoinSql(): array
    {
        $fragAgg = $this->orgScope->marketingCampaignBranchOrgExistsClause('c_agg');
        $sql = '(
            SELECT rec.campaign_id,
                SUM(CASE WHEN o.status IN (\'sent\', \'handoff_accepted\') THEN 1 ELSE 0 END) AS sent_count,
                MAX(o.sent_at) AS last_sent_at
            FROM marketing_campaign_recipients rec
            INNER JOIN marketing_campaign_runs run ON run.id = rec.campaign_run_id
            INNER JOIN marketing_campaigns c_agg ON c_agg.id = rec.campaign_id'
            . $fragAgg['sql']
            . ' LEFT JOIN outbound_notification_messages o ON o.id = rec.outbound_message_id
            GROUP BY rec.campaign_id
        ) agg';

        return ['sql' => $sql, 'params' => $fragAgg['params']];
    }

    /**
     * @param array{branch_id?: int|null, status?: string|null, channel?: string|null, q?: string|null} $filters
     */
    private function appendIndexListFilters(string &$sql, array &$params, array $filters): void
    {
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND mc.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND mc.status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['channel'])) {
            $sql .= ' AND mc.channel = ?';
            $params[] = (string) $filters['channel'];
        }
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $sql .= ' AND mc.name LIKE ?';
            $params[] = '%' . $q . '%';
        }
    }

    /**
     * @param array{branch_id?: int|null, status?: string|null} $filters
     * @return list<array<string, mixed>>
     */
    public function listInTenantScopeForStaff(array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = 'SELECT mc.* FROM marketing_campaigns mc WHERE 1=1';
        $params = [];
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND mc.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND mc.status = ?';
            $params[] = (string) $filters['status'];
        }
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('mc');
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY mc.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array{branch_id?: int|null, status?: string|null} $filters
     */
    public function countInTenantScopeForStaff(array $filters): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM marketing_campaigns mc WHERE 1=1';
        $params = [];
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND mc.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND mc.status = ?';
            $params[] = (string) $filters['status'];
        }
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('mc');
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->insert('marketing_campaigns', $data);

        return $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function updateInTenantScopeForStaff(int $id, array $patch): void
    {
        if ($patch === []) {
            return;
        }
        $cols = [];
        $vals = [];
        foreach ($patch as $k => $v) {
            $cols[] = 'mc.' . SqlIdentifier::quoteColumn($k) . ' = ?';
            $vals[] = $v;
        }
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('mc');
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $sql = 'UPDATE marketing_campaigns mc SET ' . implode(', ', $cols) . ' WHERE mc.id = ?' . $frag['sql'];
        $this->db->query($sql, $vals);
    }
}
