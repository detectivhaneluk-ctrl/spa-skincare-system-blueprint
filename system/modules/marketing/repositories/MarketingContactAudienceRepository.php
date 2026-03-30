<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Marketing\Support\MarketingContactEligibilityPolicy;

final class MarketingContactAudienceRepository
{
    private ?bool $manualListTablesReady = null;

    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listContacts(
        int $branchId,
        string $audienceKey,
        ?int $manualListId,
        string $search,
        int $limit,
        int $offset
    ): array {
        [$whereSql, $params] = $this->whereClause($branchId, $audienceKey, $manualListId, $search);
        $sql = "SELECT
                    c.id AS client_id,
                    c.first_name,
                    c.last_name,
                    c.email,
                    c.phone AS mobile_phone,
                    c.marketing_opt_in,
                    c.birth_date AS birthday,
                    c.created_at,
                    IF(TRIM(COALESCE(c.email, '')) = '', 0, 1) AS has_email,
                    IF(TRIM(COALESCE(c.phone, '')) = '', 0, 1) AS has_mobile,
                    ap.last_visit_at,
                    ap.first_visit_at,
                    ap.completed_visits
                FROM clients c
                LEFT JOIN (
                    SELECT
                        a.client_id,
                        MAX(a.end_at) AS last_visit_at,
                        MIN(a.end_at) AS first_visit_at,
                        COUNT(*) AS completed_visits
                    FROM appointments a
                    WHERE a.deleted_at IS NULL
                      AND a.status = 'completed'
                      AND a.branch_id = ?
                    GROUP BY a.client_id
                ) ap ON ap.client_id = c.id
                {$whereSql}
                ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
                LIMIT ? OFFSET ?";
        $params = array_merge([$branchId], $params, [max(1, $limit), max(0, $offset)]);

        return $this->db->fetchAll($sql, $params);
    }

    public function countContacts(int $branchId, string $audienceKey, ?int $manualListId, string $search): int
    {
        [$whereSql, $params] = $this->whereClause($branchId, $audienceKey, $manualListId, $search);
        $sql = "SELECT COUNT(*) AS c
                FROM clients c
                {$whereSql}";
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function countForAudience(int $branchId, string $audienceKey): int
    {
        return $this->countContacts($branchId, $audienceKey, null, '');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereClause(int $branchId, string $audienceKey, ?int $manualListId, string $search): array
    {
        $mk = $this->orgScope->clientMarketingBranchScopedOrBranchlessTenantMemberClause('c', $branchId);
        $where = [
            'c.deleted_at IS NULL',
            'c.merged_into_client_id IS NULL',
        ];
        $params = [];

        if ($search !== '') {
            $q = '%' . $search . '%';
            $where[] = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)';
            array_push($params, $q, $q, $q, $q);
        }

        if ($audienceKey === 'marketing_email_eligible') {
            $where[] = MarketingContactEligibilityPolicy::sqlEmailEligible('c');
        } elseif ($audienceKey === 'marketing_sms_eligible') {
            $where[] = MarketingContactEligibilityPolicy::sqlSmsEligible('c');
        } elseif ($audienceKey === 'birthday_this_month') {
            $where[] = 'c.birth_date IS NOT NULL';
            $where[] = 'MONTH(c.birth_date) = MONTH(CURDATE())';
        } elseif ($audienceKey === 'first_time_visitors') {
            $where[] = 'EXISTS (
                SELECT 1 FROM appointments a1
                WHERE a1.client_id = c.id
                  AND a1.deleted_at IS NULL
                  AND a1.status = \'completed\'
                  AND a1.branch_id = ?
                GROUP BY a1.client_id
                HAVING COUNT(*) = 1
            )';
            $params[] = $branchId;
        } elseif ($audienceKey === 'no_recent_visit_45_days') {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM appointments a2
                WHERE a2.client_id = c.id
                  AND a2.deleted_at IS NULL
                  AND a2.status = \'completed\'
                  AND a2.branch_id = ?
                  AND a2.end_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)
            )';
            $where[] = 'EXISTS (
                SELECT 1 FROM appointments a3
                WHERE a3.client_id = c.id
                  AND a3.deleted_at IS NULL
                  AND a3.status = \'completed\'
                  AND a3.branch_id = ?
            )';
            $params[] = $branchId;
            $params[] = $branchId;
        } elseif ($audienceKey === 'manual_list' && $manualListId !== null && $manualListId > 0) {
            if (!$this->manualListTablesReady()) {
                $where[] = '1 = 0';
                $sql = 'WHERE ' . implode(' AND ', $where) . $mk['sql'];
                $params = array_merge($params, $mk['params']);

                return [$sql, $params];
            }
            $lfrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('l');
            $where[] = 'EXISTS (
                SELECT 1
                FROM marketing_contact_list_members m
                INNER JOIN marketing_contact_lists l ON l.id = m.list_id
                WHERE m.client_id = c.id
                  AND m.list_id = ?
                  AND l.archived_at IS NULL
                  AND l.branch_id = ?' . $lfrag['sql'] . '
            )';
            $params[] = $manualListId;
            $params[] = $branchId;
            $params = array_merge($params, $lfrag['params']);
        }

        $sql = 'WHERE ' . implode(' AND ', $where) . $mk['sql'];
        $params = array_merge($params, $mk['params']);

        return [$sql, $params];
    }

    private function manualListTablesReady(): bool
    {
        if ($this->manualListTablesReady !== null) {
            return $this->manualListTablesReady;
        }
        $listTable = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['marketing_contact_lists']
        );
        $memberTable = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['marketing_contact_list_members']
        );
        $this->manualListTablesReady = $listTable !== null && $memberTable !== null;

        return $this->manualListTablesReady;
    }
}

