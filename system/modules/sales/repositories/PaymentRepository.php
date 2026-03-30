<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use Modules\Sales\Services\SalesTenantScope;

final class PaymentRepository
{
    public function __construct(
        private Database $db,
        private SalesTenantScope $tenantScope
    )
    {
    }

    /**
     * Tenant payment read by id: explicit {@see SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane()} before SQL,
     * then {@see SalesTenantScope::paymentByInvoiceExistsClause} (invoice-plane EXISTS on {@code si}, same basis as {@see InvoiceRepository::find}).
     *
     * @throws \Core\Errors\AccessDeniedException when tenant invoice-plane context is not branch-derived
     */
    public function find(int $id): ?array
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $sql = 'SELECT p.* FROM payments p WHERE p.id = ?';
        $params = [$id];
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Tenant payment lock read by id: explicit {@see SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane()} before
     * {@code FOR UPDATE}, then {@see SalesTenantScope::paymentByInvoiceExistsClause} (same entry contract as {@see find()}).
     *
     * @throws \Core\Errors\AccessDeniedException when tenant invoice-plane context is not branch-derived
     */
    public function findForUpdate(int $id): ?array
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $sql = 'SELECT p.* FROM payments p WHERE p.id = ?';
        $params = [$id];
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $sql .= $scope['sql'] . ' FOR UPDATE';
        $params = array_merge($params, $scope['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Tenant payments list for one invoice: explicit {@see SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane()} before SQL,
     * then {@see SalesTenantScope::paymentByInvoiceExistsClause} (same entry contract as {@see find()} / {@see findForUpdate()}).
     *
     * @return list<array<string, mixed>>
     *
     * @throws \Core\Errors\AccessDeniedException when tenant invoice-plane context is not branch-derived
     */
    public function getByInvoiceId(int $invoiceId): array
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $sql = 'SELECT p.* FROM payments p WHERE p.invoice_id = ?';
        $params = [$invoiceId];
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $sql .= $scope['sql'] . ' ORDER BY p.created_at';
        $params = array_merge($params, $scope['params']);

        return $this->db->fetchAll($sql, $params);
    }

    public function getCompletedTotalByInvoiceId(int $invoiceId): float
    {
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $row = $this->db->fetchOne(
            "SELECT COALESCE(
                    SUM(
                        CASE
                            WHEN p.entry_type = 'refund' THEN -p.amount
                            ELSE p.amount
                        END
                    ),
                    0
                ) AS total
             FROM payments p
             WHERE p.invoice_id = ?
               AND p.status = 'completed'" . $scope['sql'],
            array_merge([$invoiceId], $scope['params'])
        );
        return (float) ($row['total'] ?? 0);
    }

    /**
     * Completed cash payments for a register session, grouped by persisted payments.currency (no FX).
     * Signed net per currency matches {@see getCompletedTotalByInvoiceId}: refunds reduce the total (cash leaving drawer).
     *
     * Tenant-owned: requires branch-derived org ({@see SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane}),
     * joins {@code register_sessions} with {@see SalesTenantScope::registerSessionClause} and ties payments to the invoice plane
     * via {@see SalesTenantScope::paymentByInvoiceExistsClause} — no empty-scope aggregate when tenant context is missing.
     *
     * @return list<array{currency: string, total: float}>
     */
    public function getCompletedCashTotalsByCurrencyForRegisterSession(int $registerSessionId): array
    {
        if ($registerSessionId <= 0) {
            return [];
        }

        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $invoiceScope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $sessionScope = $this->tenantScope->registerSessionClause('rs');
        $rows = $this->db->fetchAll(
            "SELECT p.currency, COALESCE(
                    SUM(
                        CASE
                            WHEN p.entry_type = 'refund' THEN -p.amount
                            ELSE p.amount
                        END
                    ),
                    0
                ) AS total
             FROM payments p
             INNER JOIN register_sessions rs ON rs.id = p.register_session_id
             WHERE p.register_session_id = ?
               AND p.payment_method = 'cash'
               AND p.status = 'completed'
             " . $sessionScope['sql'] . $invoiceScope['sql'] . "
             GROUP BY p.currency
             ORDER BY p.currency ASC",
            array_merge([$registerSessionId], $sessionScope['params'], $invoiceScope['params'])
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'currency' => strtoupper(trim((string) ($r['currency'] ?? ''))),
                'total' => round((float) ($r['total'] ?? 0), 2),
            ];
        }

        return $out;
    }

    public function create(array $data): int
    {
        $this->db->insert('payments', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function existsCompletedByInvoiceAndReference(int $invoiceId, string $reference): bool
    {
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $reference = trim($reference);
        if ($reference === '') {
            return false;
        }
        $row = $this->db->fetchOne(
            "SELECT p.id
             FROM payments p
             WHERE p.invoice_id = ?
               AND p.status = 'completed'
               AND p.transaction_reference = ?" . $scope['sql'] . "
             LIMIT 1",
            array_merge([$invoiceId, $reference], $scope['params'])
        );
        return $row !== null;
    }

    public function getCompletedRefundedTotalForParentPayment(int $parentPaymentId): float
    {
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(p.amount), 0) AS total
             FROM payments p
             WHERE p.parent_payment_id = ?
               AND p.entry_type = 'refund'
               AND p.status = 'completed'" . $scope['sql'],
            array_merge([$parentPaymentId], $scope['params'])
        );
        return round((float) ($row['total'] ?? 0), 2);
    }

    public function hasCompletedRefundForInvoice(int $invoiceId): bool
    {
        $scope = $this->tenantScope->paymentByInvoiceExistsClause('p', 'si');
        $row = $this->db->fetchOne(
            "SELECT p.id
             FROM payments p
             WHERE p.invoice_id = ?
               AND p.entry_type = 'refund'
               AND p.status = 'completed'" . $scope['sql'] . "
             LIMIT 1",
            array_merge([$invoiceId], $scope['params'])
        );
        return $row !== null;
    }

    private function normalize(array $data): array
    {
        $allowed = ['invoice_id', 'register_session_id', 'entry_type', 'parent_payment_id', 'payment_method', 'amount', 'currency', 'status', 'transaction_reference', 'paid_at', 'notes', 'created_by'];
        return array_intersect_key($data, array_flip($allowed));
    }
}
