<?php

declare(strict_types=1);

namespace Modules\Marketing\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Read-side recipient resolution for automated-email execution.
 * {@code clients} predicates use {@see OrganizationRepositoryScope::clientMarketingBranchScopedOrBranchlessTenantMemberClause()} (org-anchored
 * branchless rows) instead of hand-rolled {@code (branch_id = ? OR branch_id IS NULL)}.
 */
final class MarketingAutomationExecutionRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligibleReengagement(int $branchId, int $dormantDays): array
    {
        $days = max(1, min(365, $dormantDays));
        $m = $this->orgScope->clientMarketingBranchScopedOrBranchlessTenantMemberClause('c', $branchId);

        return $this->db->fetchAll(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.birth_date, c.branch_id, c.marketing_opt_in, c.deleted_at, c.merged_into_client_id,
                    MAX(a.end_at) AS last_completed_at
             FROM clients c
             INNER JOIN appointments a ON a.client_id = c.id
                 AND a.deleted_at IS NULL
                 AND a.status = 'completed'
                 AND a.branch_id = ?
             WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
               {$m['sql']}
             GROUP BY c.id
             HAVING MAX(a.end_at) < DATE_SUB(NOW(), INTERVAL ? DAY)",
            array_merge([$branchId], $m['params'], [$days])
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligibleBirthday(int $branchId): array
    {
        $m = $this->orgScope->clientMarketingBranchScopedOrBranchlessTenantMemberClause('c', $branchId);

        return $this->db->fetchAll(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.birth_date, c.branch_id, c.marketing_opt_in, c.deleted_at, c.merged_into_client_id
             FROM clients c
             WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
               AND c.birth_date IS NOT NULL
               {$m['sql']}",
            $m['params']
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligibleFirstTimeVisitorWelcome(int $branchId, int $delayHours): array
    {
        $hours = max(0, min(720, $delayHours));
        $m = $this->orgScope->clientMarketingBranchScopedOrBranchlessTenantMemberClause('c', $branchId);

        return $this->db->fetchAll(
            "SELECT c.id, c.first_name, c.last_name, c.email, c.birth_date, c.branch_id, c.marketing_opt_in, c.deleted_at, c.merged_into_client_id,
                    MIN(a.end_at) AS first_completed_at,
                    COUNT(*) AS completed_count
             FROM clients c
             INNER JOIN appointments a ON a.client_id = c.id
                 AND a.deleted_at IS NULL
                 AND a.status = 'completed'
                 AND a.branch_id = ?
             WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL
               {$m['sql']}
             GROUP BY c.id
             HAVING COUNT(*) = 1
                AND MIN(a.end_at) <= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            array_merge([$branchId], $m['params'], [$hours])
        );
    }
}
