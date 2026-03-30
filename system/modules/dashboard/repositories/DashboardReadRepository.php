<?php

declare(strict_types=1);

namespace Modules\Dashboard\Repositories;

use Core\App\Database;
use Modules\Memberships\Services\MembershipBenefitEntitlementPolicy;
use Modules\Sales\Services\SalesTenantScope;

/**
 * Read-only aggregate queries for the operational dashboard.
 * Branch: when {@code $branchId} is set, **appointments** use exact {@code branch_id = ?} (H-004; NULL-branch rows
 * excluded from branch dashboards). Invoices and waitlist still use {@code branch_id = ? OR branch_id IS NULL};
 * memberships/products use exact {@code branch_id}.
 */
final class DashboardReadRepository
{
    public function __construct(
        private Database $db,
        private SalesTenantScope $salesTenantScope,
    ) {
    }

    public function countAppointmentsTodayTotal(?int $branchId, string $todayStart, string $tomorrowStart): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.start_at >= ? AND a.start_at < ?';
        $params = [$todayStart, $tomorrowStart];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function countAppointmentsTodayByStatus(?int $branchId, string $todayStart, string $tomorrowStart, string $status): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.start_at >= ? AND a.start_at < ?
                  AND a.status = ?';
        $params = [$todayStart, $tomorrowStart, $status];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Scheduled/confirmed still in the future today, or in_progress anytime today (started, not terminal).
     */
    public function countAppointmentsTodayActiveUpcoming(?int $branchId, string $todayStart, string $tomorrowStart, string $now): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.start_at >= ? AND a.start_at < ?
                  AND (
                        (a.status IN (\'scheduled\', \'confirmed\') AND a.start_at >= ?)
                        OR (a.status = \'in_progress\')
                  )';
        $params = [$todayStart, $tomorrowStart, $now];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Past start time, still scheduled or confirmed (not completed / cancelled / no_show / in_progress).
     */
    public function countAppointmentsPastStartOpen(?int $branchId, string $now): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.start_at < ?
                  AND a.status IN (\'scheduled\', \'confirmed\')';
        $params = [$now];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function countAppointmentsInRange(?int $branchId, string $rangeStart, string $rangeEndExclusive): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.start_at >= ? AND a.start_at < ?';
        $params = [$rangeStart, $rangeEndExclusive];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array{day:string,count:int}>
     */
    public function countAppointmentsByDayInRange(?int $branchId, string $rangeStart, string $rangeEndExclusive): array
    {
        $sql = 'SELECT DATE(a.start_at) AS day, COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.start_at >= ? AND a.start_at < ?';
        $params = [$rangeStart, $rangeEndExclusive];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $sql .= ' GROUP BY DATE(a.start_at) ORDER BY day ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'day' => (string) ($r['day'] ?? ''),
                'count' => (int) ($r['c'] ?? 0),
            ];
        }

        return $out;
    }

    public function countWaitlistByStatus(?int $branchId, string $status): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointment_waitlist w WHERE w.status = ?';
        $params = [$status];
        $this->appendBranchOrNull($sql, $params, $branchId, 'w.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function countWaitlistStatuses(?int $branchId, array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT COUNT(*) AS c FROM appointment_waitlist w WHERE w.status IN ({$placeholders})";
        $params = array_values($statuses);
        $this->appendBranchOrNull($sql, $params, $branchId, 'w.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Rows still {@code status = offered} with {@code offer_expires_at} in the past (cleanup queue; authoritative).
     */
    public function countWaitlistOfferedPastExpiry(?int $branchId): int
    {
        $sql = "SELECT COUNT(*) AS c FROM appointment_waitlist w
                WHERE w.status = 'offered'
                  AND w.offer_expires_at IS NOT NULL
                  AND w.offer_expires_at < ?";
        $params = [date('Y-m-d H:i:s')];
        $this->appendBranchOrNull($sql, $params, $branchId, 'w.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function countClientMembershipsByStatus(?int $branchId, string $status): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM client_memberships cm WHERE cm.status = ?';
        $params = [$status];
        $this->appendExactBranch($sql, $params, $branchId, 'cm.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Active memberships whose {@code ends_at} falls in [renewalStartYmd, renewalEndYmd] inclusive (DATE columns).
     */
    public function countActiveMembershipsRenewalWindow(?int $branchId, string $renewalStartYmd, string $renewalEndYmd): int
    {
        $defOp = MembershipBenefitEntitlementPolicy::sqlMembershipDefinitionJoinOperational('md');
        $sql = "SELECT COUNT(*) AS c FROM client_memberships cm
                INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id AND {$defOp}
                WHERE cm.status = 'active'
                  AND cm.ends_at >= ?
                  AND cm.ends_at <= ?";
        $params = [$renewalStartYmd, $renewalEndYmd];
        $this->appendExactBranch($sql, $params, $branchId, 'cm.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    public function countMembershipBillingCyclesByStatus(?int $branchId, string $status): int
    {
        $sql = 'SELECT COUNT(*) AS c
                FROM membership_billing_cycles mbc
                INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
                WHERE mbc.status = ?';
        $params = [$status];
        $this->appendExactBranch($sql, $params, $branchId, 'cm.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Cycles past {@code due_at} with an open/partial invoice (same predicate family as
     * {@see \Modules\Memberships\Repositories\MembershipBillingCycleRepository::listDistinctInvoiceIdsOverdueCandidates}).
     */
    public function countMembershipBillingCyclesPastDueOpenInvoice(?int $branchId, string $todayYmd): int
    {
        $sql = "SELECT COUNT(*) AS c
                FROM membership_billing_cycles mbc
                INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
                INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
                WHERE mbc.invoice_id IS NOT NULL
                  AND mbc.status IN ('invoiced', 'overdue')
                  AND mbc.due_at < ?
                  AND i.status IN ('open', 'partial')";
        $params = [$todayYmd];
        $this->appendResolvedTenantInvoiceScope($sql, $params, 'i');
        $this->appendExactBranch($sql, $params, $branchId, 'cm.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array{currency:string,net_collected:float}>
     */
    public function sumCompletedPaymentsNetByCurrencyToday(?int $branchId, string $todayStart, string $tomorrowStart): array
    {
        $sql = "SELECT p.currency,
                       COALESCE(SUM(CASE WHEN p.entry_type = 'refund' THEN -p.amount ELSE p.amount END), 0) AS net_total
                FROM payments p
                INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
                WHERE p.status = 'completed'
                  AND COALESCE(p.paid_at, p.created_at) >= ?
                  AND COALESCE(p.paid_at, p.created_at) < ?";
        $params = [$todayStart, $tomorrowStart];
        $this->appendResolvedTenantInvoiceScope($sql, $params, 'i');
        $this->appendBranchOrNull($sql, $params, $branchId, 'i.branch_id');
        $sql .= ' GROUP BY p.currency ORDER BY p.currency ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $cur = strtoupper(trim((string) ($r['currency'] ?? '')));
            if ($cur === '') {
                continue;
            }
            $out[] = [
                'currency' => $cur,
                'net_collected' => round((float) ($r['net_total'] ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{currency:string,count:int}>
     */
    public function countInvoicesCreatedTodayByCurrency(?int $branchId, string $todayStart, string $tomorrowStart): array
    {
        $sql = 'SELECT i.currency, COUNT(*) AS c
                FROM invoices i
                WHERE i.deleted_at IS NULL
                  AND i.created_at >= ? AND i.created_at < ?';
        $params = [$todayStart, $tomorrowStart];
        $this->appendResolvedTenantInvoiceScope($sql, $params, 'i');
        $this->appendBranchOrNull($sql, $params, $branchId, 'i.branch_id');
        $sql .= ' GROUP BY i.currency ORDER BY i.currency ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $cur = strtoupper(trim((string) ($r['currency'] ?? '')));
            if ($cur === '') {
                continue;
            }
            $out[] = ['currency' => $cur, 'count' => (int) ($r['c'] ?? 0)];
        }

        return $out;
    }

    /**
     * @return array{count:int, balances_by_currency: array<string, float>}
     */
    public function openInvoiceTotalsByCurrency(?int $branchId): array
    {
        $sql = "SELECT i.currency,
                       COUNT(*) AS c,
                       COALESCE(SUM(ROUND(i.total_amount - i.paid_amount, 2)), 0) AS balance
                FROM invoices i
                WHERE i.deleted_at IS NULL
                  AND i.status IN ('open', 'partial')
                  AND ROUND(i.total_amount, 2) > ROUND(i.paid_amount, 2)";
        $params = [];
        $this->appendResolvedTenantInvoiceScope($sql, $params, 'i');
        $this->appendBranchOrNull($sql, $params, $branchId, 'i.branch_id');
        $sql .= ' GROUP BY i.currency ORDER BY i.currency ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $count = 0;
        $balances = [];
        foreach ($rows as $r) {
            $cur = strtoupper(trim((string) ($r['currency'] ?? '')));
            if ($cur === '') {
                continue;
            }
            $count += (int) ($r['c'] ?? 0);
            $balances[$cur] = round((float) ($r['balance'] ?? 0), 2);
        }

        return ['count' => $count, 'balances_by_currency' => $balances];
    }

    /**
     * Products at/below reorder_level (reorder_level must be &gt; 0).
     */
    public function countProductsLowStock(?int $branchId): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM products p
                WHERE p.deleted_at IS NULL
                  AND p.is_active = 1
                  AND p.reorder_level IS NOT NULL
                  AND p.reorder_level > 0
                  AND p.stock_quantity <= p.reorder_level';
        $params = [];
        $this->appendExactBranch($sql, $params, $branchId, 'p.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * All non-deleted appointments; when branch is set, {@code a.branch_id = ?} only (H-004).
     */
    public function countAppointmentsTotal(?int $branchId): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM appointments a WHERE a.deleted_at IS NULL';
        $params = [];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Scheduled or confirmed appointments with start in [now, windowEnd) — operational “starting soon”.
     */
    public function countAppointmentsStartingSoon(
        ?int $branchId,
        string $nowInclusive,
        string $windowEndExclusive
    ): int {
        $sql = "SELECT COUNT(*) AS c FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.status IN ('scheduled', 'confirmed')
                  AND a.start_at >= ? AND a.start_at < ?";
        $params = [$nowInclusive, $windowEndExclusive];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Next operational appointments (scheduled / confirmed / in_progress), from now forward.
     *
     * @return list<array<string, mixed>>
     */
    public function listUpcomingAppointmentsForDashboard(?int $branchId, string $nowInclusive, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT a.id, a.start_at, a.status, a.branch_id,
                       c.first_name AS client_first_name, c.last_name AS client_last_name,
                       s.name AS service_name,
                       st.first_name AS staff_first_name, st.last_name AS staff_last_name,
                       b.name AS branch_name
                FROM appointments a
                LEFT JOIN clients c ON a.client_id = c.id
                LEFT JOIN services s ON a.service_id = s.id
                LEFT JOIN staff st ON a.staff_id = st.id
                LEFT JOIN branches b ON b.id = a.branch_id AND b.deleted_at IS NULL
                WHERE a.deleted_at IS NULL
                  AND a.start_at >= ?
                  AND a.status IN (\'scheduled\', \'confirmed\', \'in_progress\')';
        $params = [$nowInclusive];
        $this->appendExactBranch($sql, $params, $branchId, 'a.branch_id');
        $sql .= ' ORDER BY a.start_at ASC LIMIT ' . $limit;
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $r;
        }

        return $out;
    }

    /** @see \Modules\Sales\Services\SalesTenantScope::invoiceClause() */
    private function appendResolvedTenantInvoiceScope(string &$sql, array &$params, string $invoiceAlias = 'i'): void
    {
        $clause = $this->salesTenantScope->invoiceClause($invoiceAlias);
        $sql .= $clause['sql'];
        $params = array_merge($params, $clause['params']);
    }

    private function appendBranchOrNull(string &$sql, array &$params, ?int $branchId, string $columnExpr): void
    {
        if ($branchId === null) {
            return;
        }
        $sql .= ' AND (' . $columnExpr . ' = ? OR ' . $columnExpr . ' IS NULL)';
        $params[] = $branchId;
    }

    private function appendExactBranch(string &$sql, array &$params, ?int $branchId, string $columnExpr): void
    {
        if ($branchId === null) {
            return;
        }
        $sql .= ' AND ' . $columnExpr . ' = ?';
        $params[] = $branchId;
    }
}
