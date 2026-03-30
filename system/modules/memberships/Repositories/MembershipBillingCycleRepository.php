<?php

declare(strict_types=1);

namespace Modules\Memberships\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;
use Core\Repository\RepositoryContractGuard;
use Modules\Memberships\Services\MembershipBenefitEntitlementPolicy;

final class MembershipBillingCycleRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Cycle row joined to its invoice.
     *
     * - Tenant/runtime: strict branch-derived invoice-plane scope.
     * - Repair/global: resolved-org scope when available; otherwise explicit deployment-global scan inline in this method.
     */
    public function findInInvoicePlane(int $cycleId): ?array
    {
        $frag = $this->strictTenantInvoicePlaneBranchScope('i');

        return $this->db->fetchOne(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.id = ?' . $frag['sql'],
            array_merge([$cycleId], $frag['params'])
        ) ?: null;
    }

    public function findForRepair(int $cycleId): ?array
    {
        $sql = 'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.id = ?';
        $params = [$cycleId];
        $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * Same as {@see findInInvoicePlane} with {@code FOR UPDATE} on the cycle row (invoice-plane proof retained).
     */
    public function findForUpdateInInvoicePlane(int $cycleId): ?array
    {
        $frag = $this->strictTenantInvoicePlaneBranchScope('i');

        return $this->db->fetchOne(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.id = ?' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$cycleId], $frag['params'])
        ) ?: null;
    }

    public function findForUpdateForRepair(int $cycleId): ?array
    {
        $sql = 'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.id = ?';
        $params = [$cycleId];
        $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }
        $sql .= ' FOR UPDATE';

        return $this->db->fetchOne($sql, $params) ?: null;
    }

    /**
     * Correlates cycle id to a concrete invoice id (settlement / operator paths where both are known).
     */
    public function findForInvoice(int $cycleId, int $invoiceId): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::findForInvoice', ['findForInvoiceInInvoicePlane', 'findForInvoiceForRepair']);
    }

    /**
     * Correlates cycle id to a concrete invoice id under strict runtime invoice-plane scope.
     */
    public function findForInvoiceInInvoicePlane(int $cycleId, int $invoiceId): ?array
    {
        if ($cycleId <= 0 || $invoiceId <= 0) {
            return null;
        }
        $frag = $this->strictTenantInvoicePlaneBranchScope('i');

        return $this->db->fetchOne(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.id = ? AND i.deleted_at IS NULL
             WHERE mbc.id = ? AND mbc.invoice_id = ?' . $frag['sql'],
            array_merge([$invoiceId, $cycleId, $invoiceId], $frag['params'])
        ) ?: null;
    }

    public function findForInvoiceForRepair(int $cycleId, int $invoiceId): ?array
    {
        if ($cycleId <= 0 || $invoiceId <= 0) {
            return null;
        }

        $sql = 'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.id = ? AND i.deleted_at IS NULL
             WHERE mbc.id = ? AND mbc.invoice_id = ?';
        $params = [$invoiceId, $cycleId, $invoiceId];
        $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }

        return $this->db->fetchOne(
            $sql,
            $params
        ) ?: null;
    }

    /**
     * {@see findForInvoice} with row lock.
     */
    public function findForUpdateForInvoice(int $cycleId, int $invoiceId): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::findForUpdateForInvoice', ['findForUpdateForInvoiceInInvoicePlane', 'findForUpdateForInvoiceForRepair']);
    }

    /**
     * {@see findForInvoiceInInvoicePlane} with row lock.
     */
    public function findForUpdateForInvoiceInInvoicePlane(int $cycleId, int $invoiceId): ?array
    {
        if ($cycleId <= 0 || $invoiceId <= 0) {
            return null;
        }
        $frag = $this->strictTenantInvoicePlaneBranchScope('i');

        return $this->db->fetchOne(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.id = ? AND i.deleted_at IS NULL
             WHERE mbc.id = ? AND mbc.invoice_id = ?' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$invoiceId, $cycleId, $invoiceId], $frag['params'])
        ) ?: null;
    }

    public function findForUpdateForInvoiceForRepair(int $cycleId, int $invoiceId): ?array
    {
        if ($cycleId <= 0 || $invoiceId <= 0) {
            return null;
        }

        $sql = 'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.id = ? AND i.deleted_at IS NULL
             WHERE mbc.id = ? AND mbc.invoice_id = ?';
        $params = [$invoiceId, $cycleId, $invoiceId];
        $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }
        $sql .= ' FOR UPDATE';

        return $this->db->fetchOne(
            $sql,
            $params
        ) ?: null;
    }

    /**
     * Invoice-plane read by cycle id (no bare {@code SELECT * FROM membership_billing_cycles WHERE id = ?}).
     */
    public function find(int $id): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::find', ['findInInvoicePlane', 'findForRepair']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cm');

        return $this->db->fetchOne(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
             WHERE mbc.id = ? AND cm.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::findForUpdate', ['findForUpdateInInvoicePlane', 'findForUpdateForRepair']);
    }

    /**
     * Cycles linked to an invoice.
     *
     * - Tenant/runtime: strict branch-derived invoice-plane scope.
     * - Repair/global: resolved-org scope when available; otherwise explicit deployment-global scan inline in this method.
     *
     * @return list<array<string, mixed>>
     */
    public function listByInvoiceId(int $invoiceId): array
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::listByInvoiceId', ['listByInvoiceIdInInvoicePlane', 'listByInvoiceIdForRepair']);
    }

    /**
     * Runtime-safe invoice-plane read: strict branch-derived invoice scope only, no widening.
     *
     * @return list<array<string, mixed>>
     */
    public function listByInvoiceIdInInvoicePlane(int $invoiceId): array
    {
        $frag = $this->strictTenantInvoicePlaneBranchScope('i');

        return $this->db->fetchAll(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.invoice_id = ?' . $frag['sql'] . '
             ORDER BY mbc.id ASC',
            array_merge([$invoiceId], $frag['params'])
        );
    }

    /**
     * Explicit repair/global invoice-plane read: scope by resolved org when available, otherwise run the deployment-global scan inline.
     *
     * @return list<array<string, mixed>>
     */
    public function listByInvoiceIdForRepair(int $invoiceId): array
    {
        $sql = 'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.invoice_id = ?';
        $params = [$invoiceId];
        $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }
        $sql .= ' ORDER BY mbc.id ASC';

        return $this->db->fetchAll(
            $sql,
            $params
        );
    }

    /**
     * Latest cycle row for a membership that already has a renewal invoice attached.
     */
    public function maxInvoicedCycleIdForMembership(int $clientMembershipId): ?int
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::maxInvoicedCycleIdForMembership', ['maxInvoicedCycleIdForMembershipInTenantScope', 'maxInvoicedCycleIdForMembershipInResolvedTenantScope', 'maxInvoicedCycleIdForMembershipForRepair']);
    }

    public function maxInvoicedCycleIdForMembershipInTenantScope(int $clientMembershipId, int $branchId): ?int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cm');
        $row = $this->db->fetchOne(
            'SELECT MAX(mbc.id) AS m
             FROM membership_billing_cycles mbc
             INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
             WHERE mbc.client_membership_id = ?
               AND mbc.invoice_id IS NOT NULL
               AND cm.branch_id = ?' . $frag['sql'],
            array_merge([$clientMembershipId, $branchId], $frag['params'])
        );

        return $this->nullableMaxAggregateToInt($row);
    }

    public function maxInvoicedCycleIdForMembershipInResolvedTenantScope(int $clientMembershipId): ?int
    {
        $pin = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($pin === null || $pin <= 0) {
            return null;
        }
        $tenant = $this->orgScope->clientMembershipVisibleFromBranchContextClause('cm', $pin);
        $row = $this->db->fetchOne(
            'SELECT MAX(mbc.id) AS m
             FROM membership_billing_cycles mbc
             INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
             WHERE mbc.client_membership_id = ?
               AND mbc.invoice_id IS NOT NULL
               AND (' . $tenant['sql'] . ')',
            array_merge([$clientMembershipId], $tenant['params'])
        );

        return $this->nullableMaxAggregateToInt($row);
    }

    public function maxInvoicedCycleIdForMembershipForRepair(int $clientMembershipId): ?int
    {
        $row = $this->db->fetchOne(
            'SELECT MAX(mbc.id) AS m
             FROM membership_billing_cycles mbc
             INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
             WHERE mbc.client_membership_id = ?
               AND mbc.invoice_id IS NOT NULL
               AND ' . $this->orgScope->clientMembershipRowAnchoredToLiveOrganizationSql('cm'),
            [$clientMembershipId]
        );

        return $this->nullableMaxAggregateToInt($row);
    }

    /**
     * Distinct renewal invoices pending term application — **internal control-plane / batch** (cron {@see MembershipBillingService::applyPaidRenewalTerms}).
     * Uses strict tenant scope when branch-derived context is active; otherwise applies resolved-org repair scope when available
     * and leaves the deployment-global batch scan explicit in this method.
     *
     * @return list<int>
     */
    public function listDistinctInvoiceIdsPendingRenewalApplication(): array
    {
        $sql = 'SELECT DISTINCT mbc.invoice_id AS iid
             FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.renewal_applied_at IS NULL
               AND mbc.status IN (\'invoiced\', \'overdue\')
               AND mbc.invoice_id IS NOT NULL';
        $params = [];
        $frag = null;
        if ($this->isStrictTenantInvoicePlaneContext()) {
            $frag = $this->strictTenantInvoicePlaneBranchScope('i');
        } else {
            $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        }
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r['iid'];
        }

        return $out;
    }

    /**
     * Invoiced/overdue cycles past due_at — **internal control-plane / batch** (cron {@see MembershipBillingService::markOverdueCycles}).
     * Uses strict tenant scope when branch-derived context is active; otherwise applies resolved-org repair scope when available
     * and leaves the deployment-global batch scan explicit in this method.
     *
     * @return list<int>
     */
    public function listDistinctInvoiceIdsOverdueCandidates(): array
    {
        $sql = 'SELECT DISTINCT mbc.invoice_id AS iid
             FROM membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             WHERE mbc.invoice_id IS NOT NULL
               AND mbc.status IN (\'invoiced\', \'overdue\')
               AND mbc.due_at < CURDATE()
               AND i.status IN (\'open\', \'partial\')';
        $params = [];
        $frag = null;
        if ($this->isStrictTenantInvoicePlaneContext()) {
            $frag = $this->strictTenantInvoicePlaneBranchScope('i');
        } else {
            $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        }
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r['iid'];
        }

        return $out;
    }

    /**
     * Repair/backfill: distinct invoice ids for cycle↔invoice reconcile — **repair/ops** entry ({@see MembershipBillingService::reconcileBillingCyclesFromCanonicalInvoices}
     * and CLI `memberships_reconcile_billing_cycles.php`). Branch-derived tenant context uses strict invoice-plane scope;
     * non-branch-derived repair/global flows scope by resolved org when available and otherwise run the explicit deployment-global scan inline.
     *
     * @return list<int> distinct invoice ids
     */
    public function listDistinctInvoiceIdsForReconcile(?int $invoiceId = null, ?int $clientMembershipId = null, ?int $branchId = null): array
    {
        $sql = 'SELECT DISTINCT mbc.invoice_id AS iid
                FROM membership_billing_cycles mbc
                INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
                INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL';
        $params = [];
        $where = ['mbc.invoice_id IS NOT NULL'];
        if ($invoiceId !== null && $invoiceId > 0) {
            $where[] = 'mbc.invoice_id = ?';
            $params[] = $invoiceId;
        }
        if ($clientMembershipId !== null && $clientMembershipId > 0) {
            $where[] = 'mbc.client_membership_id = ?';
            $params[] = $clientMembershipId;
        }
        if ($branchId !== null && $branchId > 0) {
            $where[] = 'cm.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' WHERE ' . implode(' AND ', $where);
        $frag = null;
        if ($this->isStrictTenantInvoicePlaneContext()) {
            $frag = $this->strictTenantInvoicePlaneBranchScope('i');
        } else {
            $frag = $this->resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable('i');
        }
        if ($frag !== null) {
            $sql .= $frag['sql'];
            $params = array_merge($params, $frag['params']);
        }
        $sql .= ' ORDER BY mbc.invoice_id ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r['iid'];
        }

        return $out;
    }

    /**
     * Renewal duplicate guard: strict tenant lookup only. If there is no concrete or derived tenant branch context, the method
     * fails closed and returns {@code null}; repair/global callers must use an explicitly named path instead of a hidden raw fallback.
     *
     * @return array<string, mixed>|null
     */
    public function findByMembershipAndPeriod(int $clientMembershipId, string $periodStart, string $periodEnd, ?int $branchContextId = null): ?array
    {
        $pin = $branchContextId;
        if ($pin === null || $pin <= 0) {
            $pin = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        }
        if ($pin === null || $pin <= 0) {
            return null;
        }
        $tenant = $this->orgScope->clientMembershipVisibleFromBranchContextClause('cm', $pin);

        return $this->db->fetchOne(
            'SELECT mbc.* FROM membership_billing_cycles mbc
             INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
             WHERE mbc.client_membership_id = ?
               AND mbc.billing_period_start = ?
               AND mbc.billing_period_end = ?
               AND (' . $tenant['sql'] . ')',
            array_merge([$clientMembershipId, $periodStart, $periodEnd], $tenant['params'])
        );
    }

    public function insert(array $data): int
    {
        $this->db->insert('membership_billing_cycles', $this->normalizeInsert($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        RepositoryContractGuard::denyMixedSemanticsApi('MembershipBillingCycleRepository::update', ['updateInInvoicePlane', 'updateForRepair']);
    }

    public function updateInInvoicePlane(int $id, array $data): void
    {
        $norm = $this->normalizeUpdate($data);
        if ($norm === []) {
            return;
        }
        $frag = $this->strictTenantInvoicePlaneBranchScope('i');
        $cols = array_map(static fn (string $k): string => "mbc.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query(
            'UPDATE membership_billing_cycles mbc
             INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
             SET ' . implode(', ', $cols) . '
             WHERE mbc.id = ?' . $frag['sql'],
            array_merge($vals, $frag['params'])
        );
    }

    public function updateForRepair(int $id, array $data): void
    {
        $norm = $this->normalizeUpdate($data);
        if ($norm === []) {
            return;
        }
        $cols = array_map(static fn (string $k): string => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $this->db->query('UPDATE membership_billing_cycles SET ' . implode(', ', $cols) . ' WHERE id = ?', $vals);
    }

    /**
     * Renewal term was applied ({@code renewal_applied_at} set) but canonical invoice no longer matches a settled paid renewal.
     * **Intrinsic resolved-organization binding** (fail-closed: empty when org id unset). {@code cm.branch_id} set → branch-in-org;
     * null membership branch → invoice {@code i.branch_id} must lie in the same org.
     * **Tenant HTTP data-plane (single branch pin):** {@see listRefundReviewQueueInTenantScope}.
     *
     * @return list<array<string, mixed>> rows include {@code invoice_status}, {@code invoice_paid_amount}, {@code invoice_total_amount}, {@code membership_branch_id}
     */
    public function listRefundReviewQueue(?int $branchId = null, ?string $branchScope = null): array
    {
        $oid = $this->orgScope->resolvedOrganizationId();
        if ($oid === null) {
            return [];
        }
        $orgBind = $this->billingCycleRefundReviewOrganizationBinding();
        $sql = 'SELECT mbc.*,
                       i.status AS invoice_status,
                       i.paid_amount AS invoice_paid_amount,
                       i.total_amount AS invoice_total_amount,
                       cm.branch_id AS membership_branch_id,
                       cm.client_id AS membership_client_id
                FROM membership_billing_cycles mbc
                INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
                INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
                WHERE mbc.renewal_applied_at IS NOT NULL
                  AND mbc.status = \'invoiced\'
                  AND mbc.invoice_id IS NOT NULL
                  AND (
                      i.status = \'refunded\'
                      OR (ROUND(i.paid_amount, 2) < ROUND(i.total_amount, 2) AND ROUND(i.total_amount, 2) > 0)
                  )';
        $params = [];
        if ($branchScope === 'global') {
            $sql .= ' AND cm.branch_id IS NULL';
        } elseif ($branchId !== null) {
            $sql .= ' AND cm.branch_id <=> ?';
            $params[] = $branchId;
        }
        $sql .= $orgBind['sql'];
        $params = array_merge($params, $orgBind['params']);
        $sql .= ' ORDER BY mbc.id DESC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Refund-review billing cycles for tenant branch (via client_memberships.branch_id).
     *
     * @return list<array<string, mixed>>
     */
    public function listRefundReviewQueueInTenantScope(int $branchId): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('cm');
        $sql = 'SELECT mbc.*,
                       i.status AS invoice_status,
                       i.paid_amount AS invoice_paid_amount,
                       i.total_amount AS invoice_total_amount,
                       cm.branch_id AS membership_branch_id,
                       cm.client_id AS membership_client_id
                FROM membership_billing_cycles mbc
                INNER JOIN invoices i ON i.id = mbc.invoice_id AND i.deleted_at IS NULL
                INNER JOIN client_memberships cm ON cm.id = mbc.client_membership_id
                WHERE mbc.renewal_applied_at IS NOT NULL
                  AND mbc.status = \'invoiced\'
                  AND mbc.invoice_id IS NOT NULL
                  AND cm.branch_id = ?' . $frag['sql'] . '
                  AND (
                      i.status = \'refunded\'
                      OR (ROUND(i.paid_amount, 2) < ROUND(i.total_amount, 2) AND ROUND(i.total_amount, 2) > 0)
                  )
                ORDER BY mbc.id DESC';

        return $this->db->fetchAll($sql, array_merge([$branchId], $frag['params']));
    }

    /**
     * @return list<array{id: int, branch_id: int|null}>
     */
    public function listDueClientMembershipIds(?int $branchId = null): array
    {
        $defOp = MembershipBenefitEntitlementPolicy::sqlMembershipDefinitionJoinOperational('md');
        $sql = "SELECT cm.id, cm.branch_id
                FROM client_memberships cm
                INNER JOIN membership_definitions md ON md.id = cm.membership_definition_id AND {$defOp}
                WHERE md.billing_enabled = 1
                  AND cm.status = 'active'
                  AND COALESCE(cm.cancel_at_period_end, 0) = 0
                  AND cm.billing_auto_renew_enabled = 1
                  AND cm.next_billing_at IS NOT NULL
                  AND cm.next_billing_at <= CURDATE()";
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND cm.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY cm.next_billing_at ASC, cm.id ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $bid = $r['branch_id'] ?? null;
            $out[] = [
                'id' => (int) $r['id'],
                'branch_id' => $bid !== null && $bid !== '' ? (int) $bid : null,
            ];
        }

        return $out;
    }

    /**
     * Org binding for {@see listRefundReviewQueue}: membership branch or invoice branch in resolved org.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function billingCycleRefundReviewOrganizationBinding(): array
    {
        $oid = $this->orgScope->resolvedOrganizationId();
        if ($oid === null) {
            return ['sql' => '', 'params' => []];
        }
        $sql = " AND (
            (cm.branch_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM branches b
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE b.id = cm.branch_id AND b.deleted_at IS NULL AND o.id = ?
            ))
            OR
            (cm.branch_id IS NULL AND i.branch_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM branches b
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE b.id = i.branch_id AND b.deleted_at IS NULL AND o.id = ?
            ))
        )";

        return ['sql' => $sql, 'params' => [$oid, $oid]];
    }

    /**
     * Strict tenant helper for invoice-plane billing-cycle reads. No fallback, no silent widening.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function strictTenantInvoicePlaneBranchScope(string $invoiceAlias = 'i'): array
    {
        return $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause($invoiceAlias, 'branch_id');
    }

    /**
     * Explicit repair/global helper: when an organization is resolved outside branch-derived tenant mode, scope invoice-joined
     * billing-cycle SQL to that org. Returns `null` when no org resolves so callers must choose deployment-global behavior inline.
     *
     * @return array{sql: string, params: list<mixed>}|null
     */
    private function resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable(string $invoiceAlias = 'i'): ?array
    {
        if ($this->orgScope->resolvedOrganizationId() === null) {
            return null;
        }

        return $this->orgScope->globalAdminBranchColumnOwnedByResolvedOrganizationExistsClause($invoiceAlias, 'branch_id');
    }

    private function isStrictTenantInvoicePlaneContext(): bool
    {
        return $this->orgScope->isBranchDerivedResolvedOrganizationContext();
    }

    private function nullableMaxAggregateToInt(?array $row): ?int
    {
        if (!$row || $row['m'] === null || $row['m'] === '') {
            return null;
        }

        return (int) $row['m'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeInsert(array $data): array
    {
        $allowed = [
            'client_membership_id', 'billing_period_start', 'billing_period_end', 'due_at',
            'invoice_id', 'status', 'attempt_count', 'renewal_applied_at',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeUpdate(array $data): array
    {
        $allowed = [
            'invoice_id', 'status', 'attempt_count', 'renewal_applied_at',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
