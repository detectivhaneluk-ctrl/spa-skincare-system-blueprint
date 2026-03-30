<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Database;

/**
 * Read-only audit: **invoice header money** vs **completed payment/refund rows** on the same invoice,
 * restricted to **same invoice currency** for settlement comparison (no FX).
 *
 * Does **not** mutate data, recompute invoices, or change payment posting.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-06}.
 */
final class InvoicePaymentSettlementTruthAuditService
{
    public const AUDIT_SCHEMA_VERSION = 1;

    public const SAMPLE_CONTRADICTION_CAP = 20;

    /**
     * Stored-money sanity: {@code balance_due = total_amount - paid_amount} vs zero (negative ⇒ contradiction).
     */
    public const MONEY_EPSILON = 0.01;

    /**
     * Material difference between header {@code paid_amount} and same-currency net from completed rows.
     */
    public const MATERIAL_PAID_VS_EVIDENCE_DELTA = 0.02;

    /**
     * Completed payment net vs {@code total_amount} when flagging structural over/under vs total.
     */
    public const MATERIAL_NET_VS_TOTAL_DELTA = 0.02;

    public const SETTLEMENT_TRUTH_CLASSES = [
        'coherent_settlement',
        'contradicted_settlement',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('c');
        $invoiceWhere = '';
        $params = [];
        if ($invoiceIdFilter !== null) {
            $invoiceWhere = ' AND i.id = ?';
            $params[] = $invoiceIdFilter;
        }

        $invoiceSql = <<<SQL
SELECT i.id,
       i.status,
       i.branch_id,
       i.currency,
       i.total_amount,
       i.paid_amount
FROM invoices i
WHERE i.deleted_at IS NULL{$invoiceWhere}
ORDER BY i.id ASC
SQL;
        $invoiceRows = $this->db->fetchAll($invoiceSql, $params);

        $paymentSql = <<<SQL
SELECT p.invoice_id,
       p.status,
       p.currency,
       p.amount,
       p.entry_type
FROM payments p
INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
WHERE 1 = 1{$invoiceWhere}
ORDER BY p.invoice_id ASC, p.id ASC
SQL;
        $paymentRows = $this->db->fetchAll($paymentSql, $params);

        $paymentsByInvoice = [];
        foreach ($paymentRows as $pr) {
            $iid = (int) ($pr['invoice_id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            $paymentsByInvoice[$iid][] = $pr;
        }

        $invoices = [];
        $coherent = 0;
        $contradicted = 0;
        $sameCurrencyOverpaid = 0;
        $sameCurrencyUnderpaid = 0;
        $paidButStatusUnpaid = 0;
        $unpaidButStatusPaid = 0;
        $negativeBalanceDue = 0;
        $crossCurrencyPresence = 0;
        $noCompletedPaymentsButPositivePaid = 0;
        $sampleContradictions = [];

        foreach ($invoiceRows as $inv) {
            $row = $this->buildPerInvoiceRow($inv, $paymentsByInvoice[(int) $inv['id']] ?? []);
            $invoices[] = $row;

            $isContradicted = $row['settlement_truth_class'] === 'contradicted_settlement';
            if ($isContradicted) {
                $contradicted++;
                if (count($sampleContradictions) < self::SAMPLE_CONTRADICTION_CAP) {
                    $sampleContradictions[] = $this->contradictionSampleSlice($row);
                }
            } else {
                $coherent++;
            }

            $this->accumulateAggregateFlags(
                $row,
                $sameCurrencyOverpaid,
                $sameCurrencyUnderpaid,
                $paidButStatusUnpaid,
                $unpaidButStatusPaid,
                $negativeBalanceDue,
                $crossCurrencyPresence,
                $noCompletedPaymentsButPositivePaid
            );
        }

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'thresholds' => [
                'money_epsilon' => self::MONEY_EPSILON,
                'material_paid_vs_evidence_delta' => self::MATERIAL_PAID_VS_EVIDENCE_DELTA,
                'material_net_vs_total_delta' => self::MATERIAL_NET_VS_TOTAL_DELTA,
            ],
            'invoices_scanned' => count($invoices),
            'coherent_invoice_count' => $coherent,
            'contradicted_invoice_count' => $contradicted,
            'same_currency_overpaid_count' => $sameCurrencyOverpaid,
            'same_currency_underpaid_count' => $sameCurrencyUnderpaid,
            'paid_but_status_unpaid_count' => $paidButStatusUnpaid,
            'unpaid_but_status_paid_count' => $unpaidButStatusPaid,
            'negative_balance_due_count' => $negativeBalanceDue,
            'cross_currency_payment_presence_count' => $crossCurrencyPresence,
            'invoice_without_payments_but_paid_amount_positive_count' => $noCompletedPaymentsButPositivePaid,
            'sample_contradictions' => $sampleContradictions,
            'invoices' => $invoices,
            'notes' => [
                'Successful/usable payment evidence = payments rows with status = completed (includes entry_type payment and refund; refunds net negatively per PaymentRepository::getCompletedTotalByInvoiceId).',
                'Same-currency net sums only include completed rows whose normalized payments.currency equals normalized invoices.currency; other completed currencies contribute to successful_payment_total_cross_currency_excluded only.',
                'Cross-currency presence = more than one distinct currency among completed rows OR any completed row currency differs from invoice currency (after trim + uppercase).',
                'settlement_truth_class = contradicted_settlement if any contradiction reason is present; informational reasons alone keep coherent_settlement.',
                'Read-only: no repairs, no recompute, no allocation changes.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $inv
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>
     */
    private function buildPerInvoiceRow(array $inv, array $payments): array
    {
        $invoiceId = (int) ($inv['id'] ?? 0);
        $status = strtolower(trim((string) ($inv['status'] ?? '')));
        $branchId = $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null;
        $invoiceCurrencyRaw = strtoupper(trim((string) ($inv['currency'] ?? '')));
        $totalAmount = round((float) ($inv['total_amount'] ?? 0), 2);
        $paidAmount = round((float) ($inv['paid_amount'] ?? 0), 2);
        $balanceDue = round($totalAmount - $paidAmount, 2);

        $paymentRowCount = count($payments);
        $successfulCompleted = 0;
        $voidedOrRejected = 0;
        $currenciesCompleted = [];

        $sameCurrencyNet = 0.0;
        $crossCurrencyExcludedNet = 0.0;

        foreach ($payments as $p) {
            $pStatus = strtolower(trim((string) ($p['status'] ?? '')));
            if ($pStatus === 'voided' || $pStatus === 'rejected') {
                $voidedOrRejected++;
            }
            if ($pStatus !== 'completed') {
                continue;
            }
            $successfulCompleted++;
            $signed = $this->signedCompletedAmount($p);
            $pCur = strtoupper(trim((string) ($p['currency'] ?? '')));
            if ($pCur !== '') {
                $currenciesCompleted[$pCur] = true;
            }
            if ($invoiceCurrencyRaw !== '' && $pCur === $invoiceCurrencyRaw) {
                $sameCurrencyNet += $signed;
            } else {
                $crossCurrencyExcludedNet += $signed;
            }
        }

        $sameCurrencyNet = round($sameCurrencyNet, 2);
        $crossCurrencyExcludedNet = round($crossCurrencyExcludedNet, 2);
        $distinctCompletedCurrencies = count($currenciesCompleted);
        $paidDelta = round($paidAmount - $sameCurrencyNet, 2);
        $balanceRecomputedSame = round($totalAmount - $sameCurrencyNet, 2);

        $crossCurrencyPresence = $distinctCompletedCurrencies > 1
            || $this->hasCompletedForeignCurrency($payments, $invoiceCurrencyRaw);

        $reasons = [];
        if ($crossCurrencyPresence) {
            $reasons[] = 'cross_currency_completed_payment_evidence_present';
        }

        $contradictionReasons = [];

        if ($balanceDue < -self::MONEY_EPSILON) {
            $contradictionReasons[] = 'negative_stored_balance_due';
        }
        if ($paidAmount > self::MONEY_EPSILON && $successfulCompleted === 0) {
            $contradictionReasons[] = 'positive_paid_amount_without_completed_payment_rows';
        }
        if ($totalAmount > self::MONEY_EPSILON && $sameCurrencyNet > $totalAmount + self::MATERIAL_NET_VS_TOTAL_DELTA) {
            $contradictionReasons[] = 'same_currency_completed_net_exceeds_invoice_total';
        }
        if (abs($paidDelta) > self::MATERIAL_PAID_VS_EVIDENCE_DELTA) {
            $contradictionReasons[] = 'paid_amount_mismatch_vs_same_currency_completed_net';
        }

        $unpaidStory = in_array($status, ['draft', 'open', 'partial'], true);
        if ($status === 'paid' && $totalAmount > self::MONEY_EPSILON && $sameCurrencyNet < $totalAmount - self::MATERIAL_NET_VS_TOTAL_DELTA) {
            $contradictionReasons[] = 'status_paid_insufficient_same_currency_completed_net';
        }
        if ($unpaidStory && $totalAmount > self::MONEY_EPSILON && $sameCurrencyNet >= $totalAmount - self::MATERIAL_NET_VS_TOTAL_DELTA) {
            $contradictionReasons[] = 'status_unpaid_story_fully_covered_by_same_currency_completed_net';
        }

        foreach ($contradictionReasons as $c) {
            $reasons[] = $c;
        }

        $isContradicted = $contradictionReasons !== [];
        $settlementClass = $isContradicted ? 'contradicted_settlement' : 'coherent_settlement';

        return [
            'invoice_id' => $invoiceId,
            'invoice_status' => (string) ($inv['status'] ?? ''),
            'invoice_branch_id' => $branchId,
            'invoice_currency' => $invoiceCurrencyRaw,
            'invoice_total_amount' => $totalAmount,
            'invoice_paid_amount' => $paidAmount,
            'invoice_balance_due' => $balanceDue,
            'payment_row_count' => $paymentRowCount,
            'successful_payment_row_count' => $successfulCompleted,
            'voided_or_rejected_payment_row_count' => $voidedOrRejected,
            'distinct_successful_payment_currency_count' => $distinctCompletedCurrencies,
            'successful_payment_total_same_currency' => $sameCurrencyNet,
            'successful_payment_total_cross_currency_excluded' => $crossCurrencyExcludedNet,
            'paid_amount_delta_vs_successful_same_currency' => $paidDelta,
            'balance_due_recomputed_same_currency' => $balanceRecomputedSame,
            'settlement_truth_class' => $settlementClass,
            'settlement_truth_reasons' => $reasons,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function accumulateAggregateFlags(
        array $row,
        int &$sameCurrencyOverpaid,
        int &$sameCurrencyUnderpaid,
        int &$paidButStatusUnpaid,
        int &$unpaidButStatusPaid,
        int &$negativeBalanceDue,
        int &$crossCurrencyPresence,
        int &$noCompletedPaymentsButPositivePaid
    ): void {
        $total = (float) $row['invoice_total_amount'];
        $paid = (float) $row['invoice_paid_amount'];
        $balanceDue = (float) $row['invoice_balance_due'];
        $net = (float) $row['successful_payment_total_same_currency'];
        $status = strtolower(trim((string) $row['invoice_status']));
        $unpaidStory = in_array($status, ['draft', 'open', 'partial'], true);
        $successfulCount = (int) $row['successful_payment_row_count'];

        if ($total > self::MONEY_EPSILON && $net > $total + self::MATERIAL_NET_VS_TOTAL_DELTA) {
            $sameCurrencyOverpaid++;
        }
        if ($paid > $net + self::MATERIAL_PAID_VS_EVIDENCE_DELTA) {
            $sameCurrencyUnderpaid++;
        }
        if ($unpaidStory && $total > self::MONEY_EPSILON && $net >= $total - self::MATERIAL_NET_VS_TOTAL_DELTA) {
            $paidButStatusUnpaid++;
        }
        if ($status === 'paid' && $total > self::MONEY_EPSILON && $net < $total - self::MATERIAL_NET_VS_TOTAL_DELTA) {
            $unpaidButStatusPaid++;
        }
        if ($balanceDue < -self::MONEY_EPSILON) {
            $negativeBalanceDue++;
        }
        if (in_array('cross_currency_completed_payment_evidence_present', $row['settlement_truth_reasons'], true)) {
            $crossCurrencyPresence++;
        }
        if ($successfulCount === 0 && $paid > self::MONEY_EPSILON) {
            $noCompletedPaymentsButPositivePaid++;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function contradictionSampleSlice(array $row): array
    {
        $codes = array_values(array_filter(
            $row['settlement_truth_reasons'],
            static fn (string $c): bool => $c !== 'cross_currency_completed_payment_evidence_present'
        ));

        return [
            'invoice_id' => $row['invoice_id'],
            'invoice_status' => $row['invoice_status'],
            'invoice_branch_id' => $row['invoice_branch_id'],
            'invoice_currency' => $row['invoice_currency'],
            'invoice_total_amount' => $row['invoice_total_amount'],
            'invoice_paid_amount' => $row['invoice_paid_amount'],
            'invoice_balance_due' => $row['invoice_balance_due'],
            'successful_payment_total_same_currency' => $row['successful_payment_total_same_currency'],
            'paid_amount_delta_vs_successful_same_currency' => $row['paid_amount_delta_vs_successful_same_currency'],
            'settlement_truth_class' => $row['settlement_truth_class'],
            'contradiction_reason_codes' => $codes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function hasCompletedForeignCurrency(array $payments, string $invoiceCurrencyNorm): bool
    {
        foreach ($payments as $p) {
            if (strtolower(trim((string) ($p['status'] ?? ''))) !== 'completed') {
                continue;
            }
            $pCur = strtoupper(trim((string) ($p['currency'] ?? '')));
            if ($invoiceCurrencyNorm === '') {
                if ($pCur !== '') {
                    return true;
                }
            } elseif ($pCur !== $invoiceCurrencyNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function signedCompletedAmount(array $p): float
    {
        $amount = round((float) ($p['amount'] ?? 0), 2);
        $entry = strtolower(trim((string) ($p['entry_type'] ?? 'payment')));

        return $entry === 'refund' ? -$amount : $amount;
    }
}
