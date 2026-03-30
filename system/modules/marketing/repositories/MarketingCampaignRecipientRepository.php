<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\App\SqlIdentifier;
use Core\Organization\OrganizationRepositoryScope;

/**
 * `marketing_campaign_recipients` access. **insertBatch** never applies {@see OrganizationRepositoryScope}.
 * Other methods scope via join to campaigns (tenant fail-closed).
 */
final class MarketingCampaignRecipientRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function insertBatch(array $rows): void
    {
        foreach ($rows as $row) {
            $this->db->insert('marketing_campaign_recipients', $row);
        }
    }

    /**
     * Row-level lock for dispatch. Callers must only use after the parent run/campaign is already validated.
     */
    public function findForUpdateInTenantScopeForStaff(int $id): ?array
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.* FROM marketing_campaign_recipients r
            INNER JOIN marketing_campaign_runs run ON run.id = r.campaign_run_id
            INNER JOIN marketing_campaigns c ON c.id = run.campaign_id
            WHERE r.id = ?' . $frag['sql'] . ' FOR UPDATE';
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByRunIdInTenantScopeForStaff(int $runId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.*, o.status AS outbound_status, o.sent_at AS outbound_sent_at, o.failed_at AS outbound_failed_at,
                o.error_summary AS outbound_error_summary, o.skip_reason AS outbound_skip_reason
             FROM marketing_campaign_recipients r
             LEFT JOIN outbound_notification_messages o ON o.id = r.outbound_message_id
             INNER JOIN marketing_campaign_runs run ON run.id = r.campaign_run_id
             INNER JOIN marketing_campaigns c ON c.id = run.campaign_id
             WHERE r.campaign_run_id = ?' . $frag['sql'] . '
             ORDER BY r.id ASC
             LIMIT ' . $limit;
        $params = array_merge([$runId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingForRunInTenantScopeForStaff(int $runId): array
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.* FROM marketing_campaign_recipients r
            INNER JOIN marketing_campaign_runs run ON run.id = r.campaign_run_id
            INNER JOIN marketing_campaigns c ON c.id = run.campaign_id
            WHERE r.campaign_run_id = ? AND r.delivery_status = \'pending\'' . $frag['sql'] . '
            ORDER BY r.id ASC';
        $params = array_merge([$runId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array{recipient_count: int, pending_count: int, enqueued_count: int, skipped_count: int, cancelled_count: int, sent_count: int, last_sent_at: string|null}
     */
    public function summarizeByCampaign(int $campaignId): array
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT
                COUNT(*) AS recipient_count,
                SUM(CASE WHEN r.delivery_status = \'pending\' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN r.delivery_status = \'enqueued\' THEN 1 ELSE 0 END) AS enqueued_count,
                SUM(CASE WHEN r.delivery_status = \'skipped\' THEN 1 ELSE 0 END) AS skipped_count,
                SUM(CASE WHEN r.delivery_status = \'cancelled\' THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN o.status IN (\'sent\', \'handoff_accepted\') THEN 1 ELSE 0 END) AS sent_count,
                MAX(o.sent_at) AS last_sent_at
            FROM marketing_campaign_recipients r
            INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
            LEFT JOIN outbound_notification_messages o ON o.id = r.outbound_message_id
            WHERE r.campaign_id = ?' . $frag['sql'];
        $params = array_merge([$campaignId], $frag['params']);
        $row = $this->db->fetchOne($sql, $params) ?: [];

        return [
            'recipient_count' => (int) ($row['recipient_count'] ?? 0),
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'enqueued_count' => (int) ($row['enqueued_count'] ?? 0),
            'skipped_count' => (int) ($row['skipped_count'] ?? 0),
            'cancelled_count' => (int) ($row['cancelled_count'] ?? 0),
            'sent_count' => (int) ($row['sent_count'] ?? 0),
            'last_sent_at' => isset($row['last_sent_at']) && $row['last_sent_at'] !== ''
                ? (string) $row['last_sent_at']
                : null,
        ];
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
        $sql = 'UPDATE marketing_campaign_recipients r
            INNER JOIN marketing_campaign_runs run ON run.id = r.campaign_run_id
            INNER JOIN marketing_campaigns c ON c.id = run.campaign_id
            SET ' . implode(', ', $cols) . ' WHERE r.id = ?' . $frag['sql'];
        $this->db->query($sql, $vals);
    }

    public function cancelAllPendingForRun(int $runId): void
    {
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'UPDATE marketing_campaign_recipients r
            INNER JOIN marketing_campaign_runs run ON run.id = r.campaign_run_id
            INNER JOIN marketing_campaigns c ON c.id = run.campaign_id
            SET r.delivery_status = \'cancelled\', r.skip_reason = \'run_cancelled\'
            WHERE r.campaign_run_id = ? AND r.delivery_status = \'pending\'' . $frag['sql'];
        $params = array_merge([$runId], $frag['params']);
        $this->db->query($sql, $params);
    }

    /**
     * Recent frozen-run recipient rows for this client (read-only marketing history).
     *
     * @return list<array<string, mixed>>
     */
    public function listRecentForClient(int $clientId, int $limit = 25): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $frag = $this->orgScope->marketingCampaignBranchOrgExistsClause('c');
        $sql = 'SELECT r.id, r.campaign_id, r.campaign_run_id, r.delivery_status, r.email_snapshot,
                r.created_at, c.name AS campaign_name, run.status AS run_status
            FROM marketing_campaign_recipients r
            INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
            INNER JOIN marketing_campaign_runs run ON run.id = r.campaign_run_id
            WHERE r.client_id = ?' . $frag['sql'] . '
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT ' . $limit;
        $params = array_merge([$clientId], $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }
}
