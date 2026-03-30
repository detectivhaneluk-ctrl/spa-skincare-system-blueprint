<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\App\SqlIdentifier;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `marketing_campaign_runs` access. Reads/updates join `marketing_campaigns` and append tenant org EXISTS fragments.
 * **insert** is unscoped at repository level.
 */
final class MarketingCampaignRunRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function findInTenantScopeForStaff(int $id): ?array
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.* FROM marketing_campaign_runs r
            INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
            WHERE r.id = ?' . $frag['sql'];
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    public function findForUpdateInTenantScopeForStaff(int $id): ?array
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.* FROM marketing_campaign_runs r
            INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
            WHERE r.id = ?' . $frag['sql'] . ' FOR UPDATE';
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByCampaignIdInTenantScopeForStaff(int $campaignId, int $limit = 50): array
    {
        $lim = max(1, min(100, $limit));
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.* FROM marketing_campaign_runs r
            INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
            WHERE r.campaign_id = ?' . $frag['sql'] . ' ORDER BY r.id DESC LIMIT ' . $lim;
        $params = array_merge([$campaignId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->insert('marketing_campaign_runs', $data);

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
            $cols[] = 'r.' . SqlIdentifier::quoteColumn($k) . ' = ?';
            $vals[] = $v;
        }
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $sql = 'UPDATE marketing_campaign_runs r
            INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
            SET ' . implode(', ', $cols) . ' WHERE r.id = ?' . $frag['sql'];
        $this->db->query($sql, $vals);
    }
}
