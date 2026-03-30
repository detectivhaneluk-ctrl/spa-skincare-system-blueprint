<?php

declare(strict_types=1);

namespace Modules\Memberships\Repositories;

use Core\App\Database;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationRepositoryScope;

final class MembershipSaleRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Fail-closed tenant read: row must be owned by resolved org via {@code membership_sales.branch_id}
     * (same plane as {@see update()}). Prefer {@see findInTenantScope()} when branch is known for sargability.
     */
    public function find(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');

        return $this->db->fetchOne(
            'SELECT ms.* FROM membership_sales ms WHERE ms.id = ?' . $frag['sql'],
            array_merge([$id], $frag['params'])
        );
    }

    public function findInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');

        return $this->db->fetchOne(
            'SELECT ms.* FROM membership_sales ms
             WHERE ms.id = ? AND ms.branch_id = ?' . $frag['sql'],
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * Fail-closed tenant row lock (org-owned {@code membership_sales.branch_id}), same predicate as {@see update()}.
     * Prefer {@see findForUpdateInTenantScope()} when invoice/caller branch is known.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');

        return $this->db->fetchOne(
            'SELECT ms.* FROM membership_sales ms WHERE ms.id = ?' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$id], $frag['params'])
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdateInTenantScope(int $id, int $branchId): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');

        return $this->db->fetchOne(
            'SELECT ms.* FROM membership_sales ms
             WHERE ms.id = ? AND ms.branch_id = ?' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    /**
     * In-flight initial-sale pipeline for the same client + definition + branch.
     * Blocks while the sale is not in a terminal post-sale state, including the gap after the
     * invoice is fully paid (`paid`) but before activation completes (`activated`).
     * Terminal states that allow a new sale: `activated`, `void`, `cancelled`.
     *
     * @return array<string, mixed>|null
     */
    public function findBlockingOpenInitialSale(int $clientId, int $membershipDefinitionId, ?int $branchId): ?array
    {
        $statusList = '\'draft\', \'invoiced\', \'paid\', \'refund_review\'';
        if ($branchId !== null && $branchId > 0) {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');

            return $this->db->fetchOne(
                'SELECT ms.* FROM membership_sales ms
                 WHERE ms.client_id = ?
                   AND ms.membership_definition_id = ?
                   AND ms.branch_id = ?' . $frag['sql'] . '
                   AND ms.status IN (' . $statusList . ')
                 ORDER BY ms.id DESC
                 LIMIT 1',
                array_merge([$clientId, $membershipDefinitionId, $branchId], $frag['params'])
            );
        }

        $invFrag = $this->invoicePlaneExistsClauseForMembershipReconcileQueries('i');

        return $this->db->fetchOne(
            'SELECT ms.* FROM membership_sales ms
             INNER JOIN invoices i ON i.id = ms.invoice_id AND i.deleted_at IS NULL
             WHERE ms.client_id = ?
               AND ms.membership_definition_id = ?
               AND ms.branch_id IS NULL
               AND ms.status IN (' . $statusList . ')' . $invFrag['sql'] . '
             ORDER BY ms.id DESC
             LIMIT 1',
            array_merge([$clientId, $membershipDefinitionId], $invFrag['params'])
        );
    }

    /**
     * Rows linked to an invoice — **tenant data-plane** when org resolves (HTTP: {@see MembershipSaleService::syncMembershipSaleForInvoice} with
     * {@see \Modules\Sales\Repositories\InvoiceRepository::find}); **repair/ops** when org unset. Same {@see invoicePlaneExistsClauseForMembershipReconcileQueries}
     * as {@see listDistinctInvoiceIdsForReconcile}.
     *
     * @return list<array<string, mixed>>
     */
    public function listByInvoiceId(int $invoiceId): array
    {
        $frag = $this->invoicePlaneExistsClauseForMembershipReconcileQueries('i');

        return $this->db->fetchAll(
            'SELECT ms.* FROM membership_sales ms
             INNER JOIN invoices i ON i.id = ms.invoice_id AND i.deleted_at IS NULL
             WHERE ms.invoice_id = ?' . $frag['sql'] . ' ORDER BY ms.id ASC',
            array_merge([$invoiceId], $frag['params'])
        );
    }

    public function insert(array $data): int
    {
        $this->db->insert('membership_sales', $this->normalizeInsert($data));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalizeUpdate($data);
        if ($norm === []) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');
        $cols = array_map(static fn (string $k): string => "ms.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query(
            'UPDATE membership_sales ms SET ' . implode(', ', $cols) . ' WHERE ms.id = ?' . $frag['sql'],
            $vals
        );
    }

    /**
     * Refund-review queue with **intrinsic resolved-organization binding** (fail-closed: empty when org id unset).
     * Non-null {@code membership_sales.branch_id} rows: branch-in-org EXISTS; null-branch rows: require {@code invoice_id}
     * and invoice {@code branch_id} in the same org (legacy/repair subset).
     *
     * @param 'global'|null $branchScope when {@code global}, only {@code ms.branch_id IS NULL}
     *
     * @return list<array<string, mixed>>
     */
    public function listRefundReview(?int $branchId = null, ?string $branchScope = null): array
    {
        $oid = $this->orgScope->resolvedOrganizationId();
        if ($oid === null) {
            return [];
        }
        $bind = $this->membershipSalesRefundReviewOrganizationBinding('i');
        $sql = 'SELECT ms.* FROM membership_sales ms
            LEFT JOIN invoices i ON i.id = ms.invoice_id AND i.deleted_at IS NULL
            WHERE ms.status = \'refund_review\'';
        $params = [];
        if ($branchScope === 'global') {
            $sql .= ' AND ms.branch_id IS NULL';
        } elseif ($branchId !== null) {
            $sql .= ' AND ms.branch_id <=> ?';
            $params[] = $branchId;
        }
        $sql .= $bind['sql'];
        $params = array_merge($params, $bind['params']);
        $sql .= ' ORDER BY ms.id DESC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Refund-review queue pinned to a tenant branch (branch_id NOT NULL on sale).
     *
     * @return list<array<string, mixed>>
     */
    public function listRefundReviewInTenantScope(int $branchId): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('ms');

        return $this->db->fetchAll(
            'SELECT ms.* FROM membership_sales ms
             WHERE ms.status = \'refund_review\'
               AND ms.branch_id = ?' . $frag['sql'] . '
             ORDER BY ms.id DESC',
            array_merge([$branchId], $frag['params'])
        );
    }

    /**
     * Distinct invoice ids for membership_sale ↔ canonical invoice repair/sync — **repair/ops** primary entry
     * ({@see MembershipSaleService::reconcileMembershipSalesFromCanonicalInvoices}, CLI `memberships_reconcile_membership_sales.php`).
     * **Tenant HTTP / hooks:** branch-derived invoice-plane EXISTS when org resolves; **repair/cron without org:** OrUnscoped fallback
     * ({@see OrganizationRepositoryScope::globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped}).
     *
     * @return list<int>
     */
    public function listDistinctInvoiceIdsForReconcile(?int $invoiceId = null, ?int $clientId = null, ?int $branchId = null): array
    {
        $frag = $this->invoicePlaneExistsClauseForMembershipReconcileQueries('i');
        $sql = 'SELECT DISTINCT ms.invoice_id AS iid
                FROM membership_sales ms
                INNER JOIN invoices i ON i.id = ms.invoice_id AND i.deleted_at IS NULL
                WHERE ms.invoice_id IS NOT NULL';
        $params = [];
        if ($invoiceId !== null && $invoiceId > 0) {
            $sql .= ' AND ms.invoice_id = ?';
            $params[] = $invoiceId;
        }
        if ($clientId !== null && $clientId > 0) {
            $sql .= ' AND ms.client_id = ?';
            $params[] = $clientId;
        }
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND ms.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= $frag['sql'] . ' ORDER BY ms.invoice_id ASC';
        $params = array_merge($params, $frag['params']);
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r['iid'];
        }

        return $out;
    }

    /**
     * Org binding for {@see listRefundReview}: {@code ms}-anchored branch row or invoice branch (when sale branch null).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function membershipSalesRefundReviewOrganizationBinding(string $invoiceAlias = 'i'): array
    {
        $oid = $this->orgScope->resolvedOrganizationId();
        if ($oid === null) {
            return ['sql' => '', 'params' => []];
        }
        $i = $invoiceAlias;
        $sql = " AND (
            (ms.branch_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM branches b
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE b.id = ms.branch_id AND b.deleted_at IS NULL AND o.id = ?
            ))
            OR
            (ms.branch_id IS NULL AND ms.invoice_id IS NOT NULL AND {$i}.branch_id IS NOT NULL AND EXISTS (
                SELECT 1 FROM branches b
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE b.id = {$i}.branch_id AND b.deleted_at IS NULL AND o.id = ?
            ))
        )";

        return ['sql' => $sql, 'params' => [$oid, $oid]];
    }

    /**
     * Same contract as {@see MembershipBillingCycleRepository} (duplicate private by module boundary): strict tenant invoice-plane EXISTS, else OrUnscoped for repair.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function invoicePlaneExistsClauseForMembershipReconcileQueries(string $invoiceAlias = 'i'): array
    {
        try {
            return $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause($invoiceAlias, 'branch_id');
        } catch (AccessDeniedException) {
            return $this->orgScope->globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped($invoiceAlias, 'branch_id');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeInsert(array $data): array
    {
        $allowed = [
            'membership_definition_id', 'client_id', 'branch_id', 'invoice_id', 'client_membership_id',
            'status', 'activation_applied_at', 'starts_at', 'ends_at', 'sold_by_user_id',
            'definition_snapshot_json',
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
            'membership_definition_id', 'client_id', 'branch_id', 'invoice_id', 'client_membership_id',
            'status', 'activation_applied_at', 'starts_at', 'ends_at', 'sold_by_user_id',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
