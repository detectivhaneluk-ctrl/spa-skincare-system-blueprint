<?php

declare(strict_types=1);

namespace Modules\Reports\Repositories;

use Core\App\Database;
use Modules\Sales\Services\SalesTenantScope;

/**
 * Read-only report queries. All methods accept optional branch_id and date range.
 *
 * Branch: **Appointment** summaries use strict {@code branch_id = ?} when filtered (H-004; NULL-branch rows excluded).
 * Invoice-backed payment/VAT slices still use {@code (branch_id = ? OR branch_id IS NULL)} for global invoice rows where
 * applicable, after {@see SalesTenantScope::invoiceClause()} (non-null invoice branch in resolved org). Other domains may differ.
 *
 * Payment date range: uses {@code COALESCE(p.paid_at, p.created_at)} like dashboard “payments today” (completed rows).
 * Dates: inclusive day bounds via {@see normalizeDateStart} / {@see normalizeDateEnd}.
 */
final class ReportRepository
{
    public function __construct(
        private Database $db,
        private SalesTenantScope $salesTenantScope,
    ) {
    }

    /**
     * Revenue = completed payments (excl. refunds) in date range, grouped by persisted p.currency (no FX).
     * Scalar total_revenue is null when more than one currency bucket exists (empty currency is its own bucket).
     *
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{total_revenue: float|null, count_payments: int, mixed_currency: bool, by_currency: list<array{currency: string, total_revenue: float, count_payments: int}>}
     */
    public function getRevenueSummary(array $filters): array
    {
        $sqlBy = "SELECT p.currency,
                         COALESCE(SUM(p.amount), 0) AS total_revenue,
                         COUNT(p.id) AS count_payments
                  FROM payments p
                  INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
                  WHERE p.entry_type = 'payment' AND p.status = 'completed'";
        $paramsBy = [];
        $this->appendResolvedTenantInvoiceScope($sqlBy, $paramsBy, 'i');
        $this->appendBranchFilterOrIncludeGlobalNull($sqlBy, $paramsBy, $filters, 'i.branch_id');
        $this->appendPaidAtFilter($sqlBy, $paramsBy, $filters);
        $sqlBy .= ' GROUP BY p.currency ORDER BY p.currency ASC';
        $byRows = $this->db->fetchAll($sqlBy, $paramsBy);
        $byCurrency = [];
        $countPayments = 0;
        foreach ($byRows as $r) {
            $cnt = (int) ($r['count_payments'] ?? 0);
            $countPayments += $cnt;
            $byCurrency[] = [
                'currency' => strtoupper(trim((string) ($r['currency'] ?? ''))),
                'total_revenue' => round((float) ($r['total_revenue'] ?? 0), 2),
                'count_payments' => $cnt,
            ];
        }

        $mixedCurrency = $this->paymentsSummaryMixedCurrencyBuckets($byCurrency);
        $totalRevenue = null;
        if (!$mixedCurrency) {
            $totalRevenue = 0.0;
            foreach ($byCurrency as $b) {
                $totalRevenue += (float) ($b['total_revenue'] ?? 0);
            }
            $totalRevenue = round($totalRevenue, 2);
        }

        return [
            'total_revenue' => $totalRevenue,
            'count_payments' => $countPayments,
            'mixed_currency' => $mixedCurrency,
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * Completed payments grouped by payment_method and persisted p.currency in date range.
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return list<array{payment_method: string, currency: string, total_amount: float, count_payments: int}>
     */
    public function getPaymentsByMethod(array $filters): array
    {
        $sql = "SELECT p.payment_method,
                       p.currency,
                       COALESCE(SUM(p.amount), 0) AS total_amount,
                       COUNT(p.id) AS count_payments
                FROM payments p
                INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
                WHERE p.entry_type = 'payment' AND p.status = 'completed'";
        $params = [];
        $this->appendResolvedTenantInvoiceScope($sql, $params, 'i');
        $this->appendBranchFilterOrIncludeGlobalNull($sql, $params, $filters, 'i.branch_id');
        $this->appendPaidAtFilter($sql, $params, $filters);
        $sql .= " GROUP BY p.payment_method, p.currency ORDER BY total_amount DESC";
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'payment_method' => (string) $r['payment_method'],
                'currency' => strtoupper(trim((string) ($r['currency'] ?? ''))),
                'total_amount' => round((float) $r['total_amount'], 2),
                'count_payments' => (int) $r['count_payments'],
            ];
        }
        return $out;
    }

    /**
     * Refunds = completed refund rows in date range, grouped by persisted p.currency (no FX).
     * Scalar total_refunded is null when more than one currency bucket exists (empty currency is its own bucket).
     *
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{total_refunded: float|null, count_refunds: int, mixed_currency: bool, by_currency: list<array{currency: string, total_refunded: float, count_refunds: int}>}
     */
    public function getRefundsSummary(array $filters): array
    {
        $sqlBy = "SELECT p.currency,
                         COALESCE(SUM(p.amount), 0) AS total_refunded,
                         COUNT(p.id) AS count_refunds
                  FROM payments p
                  INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
                  WHERE p.entry_type = 'refund' AND p.status = 'completed'";
        $paramsBy = [];
        $this->appendResolvedTenantInvoiceScope($sqlBy, $paramsBy, 'i');
        $this->appendBranchFilterOrIncludeGlobalNull($sqlBy, $paramsBy, $filters, 'i.branch_id');
        $this->appendPaidAtFilter($sqlBy, $paramsBy, $filters);
        $sqlBy .= ' GROUP BY p.currency ORDER BY p.currency ASC';
        $byRows = $this->db->fetchAll($sqlBy, $paramsBy);
        $byCurrency = [];
        $countRefunds = 0;
        foreach ($byRows as $r) {
            $cnt = (int) ($r['count_refunds'] ?? 0);
            $countRefunds += $cnt;
            $byCurrency[] = [
                'currency' => strtoupper(trim((string) ($r['currency'] ?? ''))),
                'total_refunded' => round((float) ($r['total_refunded'] ?? 0), 2),
                'count_refunds' => $cnt,
            ];
        }

        $mixedCurrency = $this->paymentsSummaryMixedCurrencyBuckets($byCurrency);
        $totalRefunded = null;
        if (!$mixedCurrency) {
            $totalRefunded = 0.0;
            foreach ($byCurrency as $b) {
                $totalRefunded += (float) ($b['total_refunded'] ?? 0);
            }
            $totalRefunded = round($totalRefunded, 2);
        }

        return [
            'total_refunded' => $totalRefunded,
            'count_refunds' => $countRefunds,
            'mixed_currency' => $mixedCurrency,
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * Appointments in date range (by start_at), counts by status.
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{total: int, by_status: array<string, int>}
     */
    public function getAppointmentsVolumeSummary(array $filters): array
    {
        $sql = "SELECT status, COUNT(*) AS cnt FROM appointments WHERE deleted_at IS NULL";
        $params = [];
        $this->appendBranchFilter($sql, $params, $filters, 'branch_id');
        $this->appendDateFilter($sql, $params, $filters, 'start_at');
        $sql .= " GROUP BY status";
        $rows = $this->db->fetchAll($sql, $params);
        $byStatus = [];
        $total = 0;
        foreach ($rows as $r) {
            $cnt = (int) $r['cnt'];
            $byStatus[(string) $r['status']] = $cnt;
            $total += $cnt;
        }
        return ['total' => $total, 'by_status' => $byStatus];
    }

    /**
     * New clients created in date range (by created_at).
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{count_new_clients: int}
     */
    public function getNewClientsSummary(array $filters): array
    {
        $sql = "SELECT COUNT(*) AS c FROM clients WHERE deleted_at IS NULL";
        $params = [];
        $this->appendBranchFilter($sql, $params, $filters, 'branch_id');
        $this->appendDateFilter($sql, $params, $filters, 'created_at');
        $row = $this->db->fetchOne($sql, $params);
        return ['count_new_clients' => (int) ($row['c'] ?? 0)];
    }

    /**
     * Appointments in date range (by start_at) grouped by staff. Branch-scoped.
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{total: int, by_staff: list<array{staff_id: int|null, staff_name: string, count: int}>}
     */
    public function getStaffAppointmentCountSummary(array $filters): array
    {
        $sql = "SELECT a.staff_id,
                       s.first_name,
                       s.last_name,
                       COUNT(*) AS cnt
                FROM appointments a
                LEFT JOIN staff s ON s.id = a.staff_id AND s.deleted_at IS NULL
                WHERE a.deleted_at IS NULL";
        $params = [];
        $this->appendBranchFilter($sql, $params, $filters, 'a.branch_id');
        $this->appendDateFilter($sql, $params, $filters, 'a.start_at');
        $sql .= " GROUP BY a.staff_id, s.first_name, s.last_name ORDER BY cnt DESC";
        $rows = $this->db->fetchAll($sql, $params);
        $byStaff = [];
        $total = 0;
        foreach ($rows as $r) {
            $cnt = (int) $r['cnt'];
            $staffId = isset($r['staff_id']) && $r['staff_id'] !== null && $r['staff_id'] !== '' ? (int) $r['staff_id'] : null;
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $byStaff[] = [
                'staff_id' => $staffId,
                'staff_name' => $name !== '' ? $name : 'Unassigned',
                'count' => $cnt,
            ];
            $total += $cnt;
        }
        return ['total' => $total, 'by_staff' => $byStaff];
    }

    /**
     * Gift cards: outstanding liability (sum of latest balance_after for active cards), counts, and activity in period.
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{total_outstanding_balance: float, count_active: int, issued_in_period: float, redeemed_in_period: float, count_issued: int, count_redeemed: int}
     */
    public function getGiftCardLiabilitySummary(array $filters): array
    {
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== ''
            ? (int) $filters['branch_id']
            : null;

        $sql = "SELECT COALESCE(SUM(gct.balance_after), 0) AS total_outstanding, COUNT(DISTINCT gct.gift_card_id) AS count_active
                FROM gift_card_transactions gct
                INNER JOIN (SELECT gift_card_id, MAX(id) AS mid FROM gift_card_transactions GROUP BY gift_card_id) t
                    ON t.gift_card_id = gct.gift_card_id AND t.mid = gct.id
                INNER JOIN gift_cards gc ON gc.id = gct.gift_card_id AND gc.deleted_at IS NULL AND gc.status = 'active'";
        $params = [];
        if ($branchId !== null) {
            $sql .= " AND gc.branch_id = ?";
            $params[] = $branchId;
        }
        $row = $this->db->fetchOne($sql, $params);
        $totalOutstanding = round((float) ($row['total_outstanding'] ?? 0), 2);
        $countActive = (int) ($row['count_active'] ?? 0);

        $sql2 = "SELECT type, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
                 FROM gift_card_transactions gct
                 INNER JOIN gift_cards gc ON gc.id = gct.gift_card_id AND gc.deleted_at IS NULL";
        $params2 = [];
        if ($branchId !== null) {
            $sql2 .= " AND gc.branch_id = ?";
            $params2[] = $branchId;
        }
        $this->appendDateFilter($sql2, $params2, $filters, 'gct.created_at');
        $sql2 .= " AND gct.type IN ('issue', 'redeem') GROUP BY gct.type";
        $rows2 = $this->db->fetchAll($sql2, $params2);
        $issuedInPeriod = 0.0;
        $redeemedInPeriod = 0.0;
        $countIssued = 0;
        $countRedeemed = 0;
        foreach ($rows2 as $r) {
            if ((string) $r['type'] === 'issue') {
                $issuedInPeriod = round((float) $r['total'], 2);
                $countIssued = (int) $r['cnt'];
            } elseif ((string) $r['type'] === 'redeem') {
                $redeemedInPeriod = round((float) $r['total'], 2);
                $countRedeemed = (int) $r['cnt'];
            }
        }

        return [
            'total_outstanding_balance' => $totalOutstanding,
            'count_active' => $countActive,
            'issued_in_period' => $issuedInPeriod,
            'redeemed_in_period' => $redeemedInPeriod,
            'count_issued' => $countIssued,
            'count_redeemed' => $countRedeemed,
        ];
    }

    /**
     * Stock movements in date range: count by type and net quantity (in/out by sign).
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{total_movements: int, by_type: array<string, int>, quantity_in: float, quantity_out: float}
     */
    public function getInventoryMovementSummary(array $filters): array
    {
        $sql = "SELECT movement_type, COUNT(*) AS cnt, COALESCE(SUM(quantity), 0) AS qty
                FROM stock_movements WHERE 1=1";
        $params = [];
        $this->appendBranchFilter($sql, $params, $filters, 'branch_id');
        $this->appendDateFilter($sql, $params, $filters, 'created_at');
        $sql .= " GROUP BY movement_type";
        $rows = $this->db->fetchAll($sql, $params);
        $byType = [];
        $totalMovements = 0;
        $quantityIn = 0.0;
        $quantityOut = 0.0;
        foreach ($rows as $r) {
            $cnt = (int) $r['cnt'];
            $qty = (float) $r['qty'];
            $byType[(string) $r['movement_type']] = $cnt;
            $totalMovements += $cnt;
            if ($qty >= 0) {
                $quantityIn += $qty;
            } else {
                $quantityOut += abs($qty);
            }
        }
        return [
            'total_movements' => $totalMovements,
            'by_type' => $byType,
            'quantity_in' => round($quantityIn, 3),
            'quantity_out' => round($quantityOut, 3),
        ];
    }

    /**
     * True when grouped payment/refund rows span more than one distinct payments.currency bucket (after trim/upper).
     * Empty string is its own bucket so '' + 'USD' counts as mixed.
     *
     * @param list<array{currency: string}> $byCurrencyBuckets
     */
    private function paymentsSummaryMixedCurrencyBuckets(array $byCurrencyBuckets): bool
    {
        $keys = [];
        foreach ($byCurrencyBuckets as $row) {
            $keys[(string) ($row['currency'] ?? '')] = true;
        }

        return count($keys) > 1;
    }

    /**
     * Same org/branch proof as {@see SalesTenantScope::invoiceClause()} for invoice-backed aggregates (tenant data-plane).
     */
    private function appendResolvedTenantInvoiceScope(string &$sql, array &$params, string $invoiceAlias = 'i'): void
    {
        $clause = $this->salesTenantScope->invoiceClause($invoiceAlias);
        $sql .= $clause['sql'];
        $params = array_merge($params, $clause['params']);
    }

    private function appendBranchFilter(string &$sql, array &$params, array $filters, string $column): void
    {
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= " AND {$column} = ?";
            $params[] = (int) $filters['branch_id'];
        }
    }

    /**
     * Same branch semantics as dashboard aggregates: scoped branch plus NULL-branch (global) rows.
     */
    private function appendBranchFilterOrIncludeGlobalNull(string &$sql, array &$params, array $filters, string $column): void
    {
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= " AND ({$column} = ? OR {$column} IS NULL)";
            $params[] = (int) $filters['branch_id'];
        }
    }

    private function appendPaidAtFilter(string &$sql, array &$params, array $filters): void
    {
        if (!empty($filters['date_from'])) {
            $sql .= ' AND COALESCE(p.paid_at, p.created_at) >= ?';
            $params[] = $this->normalizeDateStart($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $sql .= ' AND COALESCE(p.paid_at, p.created_at) <= ?';
            $params[] = $this->normalizeDateEnd($filters['date_to']);
        }
    }

    private function appendDateFilter(string &$sql, array &$params, array $filters, string $column): void
    {
        if (!empty($filters['date_from'])) {
            $sql .= " AND {$column} >= ?";
            $params[] = $this->normalizeDateStart($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND {$column} <= ?";
            $params[] = $this->normalizeDateEnd($filters['date_to']);
        }
    }

    private function normalizeDateStart(string $date): string
    {
        if (strlen($date) <= 10) {
            return $date . ' 00:00:00';
        }
        return $date;
    }

    private function normalizeDateEnd(string $date): string
    {
        if (strlen($date) <= 10) {
            return $date . ' 23:59:59';
        }
        return $date;
    }

    /**
     * VAT distribution: invoice line totals grouped by tax_rate, with optional vat_rates code/name.
     * Uses invoice_items.tax_rate (invoice lines do not store vat_rate_id; matching by rate_percent for display).
     * Date filter on COALESCE(invoices.issued_at, invoices.created_at).
     *
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return list<array{tax_rate: float, vat_rate_id: int|null, vat_code: string|null, vat_name: string|null, taxable_base_total: float, tax_total: float, gross_total: float}>
     */
    public function getVatDistribution(array $filters): array
    {
        $sql = "SELECT ii.tax_rate,
                MIN(vr.id) AS vat_rate_id,
                MIN(vr.code) AS vat_code,
                MIN(vr.name) AS vat_name,
                SUM(ii.quantity * ii.unit_price - ii.discount_amount) AS taxable_base_total,
                SUM(ii.line_total - (ii.quantity * ii.unit_price - ii.discount_amount)) AS tax_total,
                SUM(ii.line_total) AS gross_total
                FROM invoice_items ii
                INNER JOIN invoices i ON i.id = ii.invoice_id AND i.deleted_at IS NULL
                LEFT JOIN vat_rates vr ON vr.rate_percent = ii.tax_rate AND (vr.branch_id = i.branch_id OR vr.branch_id IS NULL) AND vr.is_active = 1
                WHERE 1=1";
        $params = [];
        $this->appendResolvedTenantInvoiceScope($sql, $params, 'i');
        $this->appendBranchFilterOrIncludeGlobalNull($sql, $params, $filters, 'i.branch_id');
        if (!empty($filters['date_from'])) {
            $sql .= " AND COALESCE(i.issued_at, i.created_at) >= ?";
            $params[] = $this->normalizeDateStart($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND COALESCE(i.issued_at, i.created_at) <= ?";
            $params[] = $this->normalizeDateEnd($filters['date_to']);
        }
        $sql .= " GROUP BY ii.tax_rate ORDER BY ii.tax_rate ASC";
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'tax_rate' => round((float) $r['tax_rate'], 2),
                'vat_rate_id' => isset($r['vat_rate_id']) && $r['vat_rate_id'] !== '' && $r['vat_rate_id'] !== null ? (int) $r['vat_rate_id'] : null,
                'vat_code' => isset($r['vat_code']) && $r['vat_code'] !== '' ? (string) $r['vat_code'] : null,
                'vat_name' => isset($r['vat_name']) && $r['vat_name'] !== '' ? (string) $r['vat_name'] : null,
                'taxable_base_total' => round((float) $r['taxable_base_total'], 2),
                'tax_total' => round((float) $r['tax_total'], 2),
                'gross_total' => round((float) $r['gross_total'], 2),
            ];
        }
        return $out;
    }
}
