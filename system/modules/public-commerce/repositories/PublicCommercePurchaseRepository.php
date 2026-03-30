<?php

declare(strict_types=1);

namespace Modules\PublicCommerce\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * FOUNDATION-TENANT-REPOSITORY-CLOSURE-01: mutating paths require {@code public_commerce_purchases.branch_id}
 * to reference an active branch in the resolved organization (same contract as tenant sales plane).
 */
final class PublicCommercePurchaseRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * @return array{sql: string, params: list<mixed>}
     */
    private function purchaseBranchTenantClause(string $alias = 'p'): array
    {
        return $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause($alias, 'branch_id');
    }

    /**
     * Control-plane / reconcile bootstrap: branch pin for {@see InvoiceRepository::findForPublicCommerceCorrelatedBranch}
     * when HTTP tenant org context is not branch-derived (anonymous public finalize). Not a tenant HTTP data-plane read.
     */
    public function findBranchIdPinByInvoiceId(int $invoiceId): ?int
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT branch_id FROM public_commerce_purchases WHERE invoice_id = ? LIMIT 1',
            [$invoiceId]
        );
        if ($row === null) {
            return null;
        }
        $bid = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : 0;

        return $bid > 0 ? $bid : null;
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        $h = strtolower(trim($tokenHash));
        if (strlen($h) !== 64 || !ctype_xdigit($h)) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM public_commerce_purchases WHERE token_hash = ?',
            [$h]
        ) ?: null;
    }

    /**
     * Tenant fail-closed: purchase row must match {@code invoice_id} and {@code branch_id} (aligns with live invoice branch).
     */
    public function findByInvoiceIdForBranch(int $invoiceId, int $branchId): ?array
    {
        if ($invoiceId <= 0 || $branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM public_commerce_purchases WHERE invoice_id = ? AND branch_id = ?',
            [$invoiceId, $branchId]
        ) ?: null;
    }

    /**
     * When invoice.branch_id is unset/legacy-null: still require a live (non-deleted) invoice row joined to the purchase FK.
     */
    public function findByInvoiceIdAttachedToLiveInvoice(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT p.* FROM public_commerce_purchases p
             INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
             WHERE p.invoice_id = ?',
            [$invoiceId]
        ) ?: null;
    }

    /**
     * Resolve purchase for a tenant-scoped invoice row: branch predicate when possible, else live-invoice join.
     *
     * @param array<string, mixed> $invoiceRow
     */
    public function findCorrelatedToInvoiceRow(array $invoiceRow, int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $bid = (int) ($invoiceRow['branch_id'] ?? 0);
        if ($bid > 0) {
            return $this->findByInvoiceIdForBranch($invoiceId, $bid);
        }

        return $this->findByInvoiceIdAttachedToLiveInvoice($invoiceId);
    }

    /**
     * @param array<string, mixed> $invoiceRow
     */
    public function findForUpdateCorrelatedToInvoiceRow(array $invoiceRow, int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }
        $bid = (int) ($invoiceRow['branch_id'] ?? 0);
        if ($bid > 0) {
            return $this->findForUpdateByInvoiceIdForBranch($invoiceId, $bid);
        }

        return $this->findForUpdateByInvoiceIdAttachedToLiveInvoice($invoiceId);
    }

    /**
     * Non-terminal purchases that may still need fulfillment reconciliation (legacy gaps, missed hooks, or prerequisites pending).
     * Joins invoices to classify broken references for repair/report CLI.
     *
     * @return list<array<string, mixed>>
     */
    public function listPurchasesForFulfillmentRepair(?int $branchId, ?int $invoiceId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT p.*, i_live.status AS invoice_status,
                CASE
                    WHEN p.invoice_id IS NULL OR p.invoice_id <= 0 THEN \'invalid_invoice_id\'
                    WHEN i_any.id IS NULL THEN \'missing_invoice\'
                    WHEN i_live.id IS NULL AND i_any.id IS NOT NULL THEN \'invoice_soft_deleted\'
                    ELSE \'ok\'
                END AS repair_reference_state
                FROM public_commerce_purchases p
                LEFT JOIN invoices i_live ON i_live.id = p.invoice_id AND i_live.deleted_at IS NULL
                LEFT JOIN invoices i_any ON i_any.id = p.invoice_id
                WHERE p.status NOT IN (\'failed\', \'cancelled\')
                  AND (
                    p.fulfillment_reconcile_recovery_at IS NOT NULL
                    OR (
                        p.status <> \'paid\'
                        OR p.fulfillment_applied_at IS NULL
                        OR TRIM(COALESCE(p.fulfillment_applied_at, \'\')) = \'\'
                    )
                  )';
        $params = [];
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND p.branch_id = ?';
            $params[] = $branchId;
        }
        if ($invoiceId !== null && $invoiceId > 0) {
            $sql .= ' AND p.invoice_id = ?';
            $params[] = $invoiceId;
        }
        $sql .= ' ORDER BY p.id ASC LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Staff queue: purchases stuck after anonymous finalize (awaiting_verification) with live invoice rows.
     * Fail-closed: requires either a concrete {@code $branchId} or a positive {@code $organizationId} (branches in that org);
     * no global unscoped listing.
     *
     * **Query pattern (indexed, migration 117):**
     * - WHERE p.status = awaiting_verification AND i.status <> cancelled; INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
     * - Branch path: AND p.branch_id = ?
     * - Org path: AND EXISTS (branches b JOIN organizations o … WHERE b.id = p.branch_id AND o.id = ?)
     * - ORDER BY p.verification_queue_sort_at DESC, p.id DESC (column = STORED COALESCE(finalize_last_received_at, updated_at), same order as legacy ORDER BY COALESCE).
     *
     * @return list<array<string, mixed>>
     */
    public function listAwaitingVerificationWithInvoices(?int $branchId, ?int $organizationId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT p.*, i.status AS invoice_status, i.total_amount AS invoice_total_amount, i.paid_amount AS invoice_paid_amount
                FROM public_commerce_purchases p
                INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
                WHERE p.status = ?
                  AND i.status <> \'cancelled\'';
        $params = ['awaiting_verification'];
        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND p.branch_id = ?';
            $params[] = $branchId;
        } elseif ($organizationId !== null && $organizationId > 0) {
            $sql .= ' AND EXISTS (
                SELECT 1 FROM branches b
                INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                WHERE b.id = p.branch_id AND b.deleted_at IS NULL AND o.id = ?
            )';
            $params[] = $organizationId;
        } else {
            return [];
        }
        $sql .= ' ORDER BY p.verification_queue_sort_at DESC, p.id DESC LIMIT ' . $limit;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUpdateByInvoiceIdForBranch(int $invoiceId, int $branchId): ?array
    {
        if ($invoiceId <= 0 || $branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM public_commerce_purchases WHERE invoice_id = ? AND branch_id = ? FOR UPDATE',
            [$invoiceId, $branchId]
        ) ?: null;
    }

    public function findForUpdateByInvoiceIdAttachedToLiveInvoice(int $invoiceId): ?array
    {
        if ($invoiceId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT p.* FROM public_commerce_purchases p
             INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
             WHERE p.invoice_id = ? FOR UPDATE',
            [$invoiceId]
        ) ?: null;
    }

    /**
     * Row lock for anonymous finalize idempotency and state transitions.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdateByTokenHash(string $tokenHash): ?array
    {
        $h = strtolower(trim($tokenHash));
        if (strlen($h) !== 64 || !ctype_xdigit($h)) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM public_commerce_purchases WHERE token_hash = ? FOR UPDATE',
            [$h]
        ) ?: null;
    }

    public function insert(array $data): int
    {
        $this->db->insert('public_commerce_purchases', $this->normalizeInsert($data));

        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalizeUpdate($data);
        if ($norm === []) {
            return;
        }
        $scope = $this->purchaseBranchTenantClause('p');
        $cols = array_map(static fn (string $k): string => "p.{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $scope['params']);
        $this->db->query(
            'UPDATE public_commerce_purchases p SET ' . implode(', ', $cols) . ' WHERE p.id = ?' . $scope['sql'],
            $vals
        );
    }

    public function setFulfillmentReconcileRecovery(int $purchaseId, string $triggerSource, ?string $errorDetail): void
    {
        $trigger = trim($triggerSource);
        if (strlen($trigger) > 64) {
            $trigger = substr($trigger, 0, 64);
        }
        $detail = $errorDetail;
        if ($detail !== null && strlen($detail) > 65000) {
            $detail = substr($detail, 0, 65000);
        }
        $this->update($purchaseId, [
            'fulfillment_reconcile_recovery_at' => date('Y-m-d H:i:s'),
            'fulfillment_reconcile_recovery_trigger' => $trigger !== '' ? $trigger : null,
            'fulfillment_reconcile_recovery_error' => $detail,
        ]);
    }

    public function clearFulfillmentReconcileRecovery(int $purchaseId): void
    {
        $this->update($purchaseId, [
            'fulfillment_reconcile_recovery_at' => null,
            'fulfillment_reconcile_recovery_trigger' => null,
            'fulfillment_reconcile_recovery_error' => null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function normalizeInsert(array $data): array
    {
        $allowed = [
            'token_hash', 'branch_id', 'client_id', 'client_resolution_reason', 'product_kind',
            'package_id', 'membership_definition_id', 'package_snapshot_json', 'gift_card_amount',
            'membership_sale_id', 'invoice_id', 'client_package_id', 'gift_card_id',
            'fulfillment_applied_at', 'fulfillment_reversed_at', 'status',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }

    /** @param array<string, mixed> $data */
    private function normalizeUpdate(array $data): array
    {
        $allowed = [
            'client_package_id', 'gift_card_id', 'fulfillment_applied_at', 'fulfillment_reversed_at',
            'fulfillment_reconcile_recovery_at', 'fulfillment_reconcile_recovery_trigger', 'fulfillment_reconcile_recovery_error',
            'status',
            'finalize_attempt_count', 'finalize_last_request_hash', 'finalize_last_received_at',
        ];

        return array_intersect_key($data, array_flip($allowed));
    }
}
