<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Database;

/**
 * Read-only audit: **invoice header money** vs **persisted invoice line money fields** (rollup evidence).
 *
 * Ground truth for header {@code subtotal_amount} / {@code total_amount} is {@see InvoiceService::recomputeInvoiceFinancials}:
 * subtotal = Σ {@code invoice_items.line_total}; total = subtotal − invoice {@code discount_amount} + invoice {@code tax_amount}.
 *
 * Does **not** mutate data, recompute invoices, or implement tax/discount allocation engines.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-07}.
 */
final class InvoiceFinancialRollupTruthAuditService
{
    public const AUDIT_SCHEMA_VERSION = 1;

    public const SAMPLE_CONTRADICTION_CAP = 20;

    /**
     * Material mismatch for header subtotal vs Σ {@code line_total} and total rollup vs line evidence + header disc/tax.
     */
    public const MATERIAL_ROLLUP_DELTA = 0.02;

    /**
     * Material mismatch between header {@code discount_amount} and Σ line {@code discount_amount} (see ops doc: channels differ by design).
     */
    public const MATERIAL_DISCOUNT_CHANNEL_DELTA = 0.02;

    /**
     * Per-line: stored {@code line_total} vs {@see InvoiceService::computeLineTotal} components (same formula).
     */
    public const LINE_TOTAL_COMPONENT_EPSILON = 0.01;

    public const FINANCIAL_ROLLUP_TRUTH_CLASSES = [
        'coherent_financial_rollup',
        'contradicted_financial_rollup',
        'insufficient_line_financial_evidence',
    ];

    public function __construct(
        private Database $db,
        private SalesLineLifecycleConsistencyTruthAuditService $lifecycleConsistencyAudit
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?int $invoiceIdFilter = null): array
    {
        $generatedAt = gmdate('c');
        $lifecycleReport = $this->lifecycleConsistencyAudit->run($invoiceIdFilter);
        $lifecycleLines = $lifecycleReport['lines'] ?? [];
        if (!is_array($lifecycleLines)) {
            $lifecycleLines = [];
        }

        $mixedDomainCountByInvoice = [];
        foreach ($lifecycleLines as $ln) {
            if (!is_array($ln)) {
                continue;
            }
            $iid = (int) ($ln['invoice_id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            $domain = (string) ($ln['line_domain_class'] ?? '');
            if (!isset($mixedDomainCountByInvoice[$iid])) {
                $mixedDomainCountByInvoice[$iid] = 0;
            }
            if (!in_array($domain, ['clear_service_line', 'clear_retail_product_line'], true)) {
                $mixedDomainCountByInvoice[$iid]++;
            }
        }

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
       i.subtotal_amount,
       i.discount_amount,
       i.tax_amount,
       i.total_amount
FROM invoices i
WHERE i.deleted_at IS NULL{$invoiceWhere}
ORDER BY i.id ASC
SQL;
        $invoiceRows = $this->db->fetchAll($invoiceSql, $params);

        $itemsSql = <<<SQL
SELECT ii.id,
       ii.invoice_id,
       ii.quantity,
       ii.unit_price,
       ii.discount_amount,
       ii.tax_rate,
       ii.line_total
FROM invoice_items ii
INNER JOIN invoices i ON i.id = ii.invoice_id AND i.deleted_at IS NULL
WHERE 1 = 1{$invoiceWhere}
ORDER BY ii.invoice_id ASC, ii.id ASC
SQL;
        $itemRows = $this->db->fetchAll($itemsSql, $params);

        $itemsByInvoice = [];
        foreach ($itemRows as $row) {
            $iid = (int) ($row['invoice_id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            if (!isset($itemsByInvoice[$iid])) {
                $itemsByInvoice[$iid] = [];
            }
            $itemsByInvoice[$iid][] = $row;
        }

        $invoices = [];
        $coherent = 0;
        $contradicted = 0;
        $missingLineFinancialEvidence = 0;
        $subtotalMismatch = 0;
        $discountMismatch = 0;
        $taxMismatch = 0;
        $totalMismatch = 0;
        $mixedDomainInvoice = 0;
        $sampleContradictions = [];

        foreach ($invoiceRows as $inv) {
            $row = $this->buildPerInvoiceRow(
                $inv,
                $itemsByInvoice[(int) $inv['id']] ?? [],
                (int) ($mixedDomainCountByInvoice[(int) $inv['id']] ?? 0)
            );
            $invoices[] = $row;

            $class = (string) ($row['financial_rollup_truth_class'] ?? '');
            if ($class === 'coherent_financial_rollup') {
                $coherent++;
            } else {
                $contradicted++;
            }
            if ($class === 'insufficient_line_financial_evidence') {
                $missingLineFinancialEvidence++;
            }
            if (!empty($row['subtotal_mismatch'])) {
                $subtotalMismatch++;
            }
            if (!empty($row['discount_mismatch'])) {
                $discountMismatch++;
            }
            if (!empty($row['tax_mismatch'])) {
                $taxMismatch++;
            }
            if (!empty($row['total_mismatch'])) {
                $totalMismatch++;
            }
            if ((int) ($row['mixed_domain_line_count'] ?? 0) > 0) {
                $mixedDomainInvoice++;
            }

            if ($class !== 'coherent_financial_rollup' && count($sampleContradictions) < self::SAMPLE_CONTRADICTION_CAP) {
                $sampleContradictions[] = $this->contradictionSampleSlice($row);
            }
        }

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'thresholds' => [
                'material_rollup_delta' => self::MATERIAL_ROLLUP_DELTA,
                'material_discount_channel_delta' => self::MATERIAL_DISCOUNT_CHANNEL_DELTA,
                'line_total_component_epsilon' => self::LINE_TOTAL_COMPONENT_EPSILON,
            ],
            'invoices_scanned' => count($invoices),
            'coherent_invoice_count' => $coherent,
            'contradicted_invoice_count' => $contradicted,
            'missing_line_financial_evidence_count' => $missingLineFinancialEvidence,
            'subtotal_mismatch_count' => $subtotalMismatch,
            'discount_mismatch_count' => $discountMismatch,
            'tax_mismatch_count' => $taxMismatch,
            'total_mismatch_count' => $totalMismatch,
            'mixed_domain_invoice_count' => $mixedDomainInvoice,
            'sample_contradictions' => $sampleContradictions,
            'invoices' => $invoices,
            'composed_lifecycle_audit_schema_version' => $lifecycleReport['audit_schema_version'] ?? null,
            'notes' => [
                'Header subtotal_amount is defined in InvoiceService as Σ invoice_items.line_total (tax-inclusive per line); not Σ quantity×unit_price.',
                'Header discount_amount and tax_amount are invoice-level channels; line discount_amount sums are per-line only — discount_delta is evidence, not necessarily a defect (see ops).',
                'No per-line tax money column exists; line_tax_evidence is null and tax_mismatch_count stays 0 — header tax is not line-rolled-up in this schema.',
                'line_persisted_total_drift uses the same line_total formula as InvoiceService::computeLineTotal (read-only consistency of persisted line_total vs qty/unit_price/discount_amount/tax_rate).',
                'mixed_domain_line_count is from SalesLineLifecycleConsistencyTruthAuditService line_domain_class (non–clear service/retail lines).',
                'Read-only: truth/audit only; no repairs or production-path recompute.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $inv
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildPerInvoiceRow(array $inv, array $items, int $mixedDomainLineCount): array
    {
        $invoiceId = (int) ($inv['id'] ?? 0);
        $status = strtolower(trim((string) ($inv['status'] ?? '')));
        $branchId = $inv['branch_id'] !== null && $inv['branch_id'] !== '' ? (int) $inv['branch_id'] : null;
        $currency = strtoupper(trim((string) ($inv['currency'] ?? '')));

        $storedSubtotal = round((float) ($inv['subtotal_amount'] ?? 0), 2);
        $storedDiscount = round((float) ($inv['discount_amount'] ?? 0), 2);
        $storedTax = round((float) ($inv['tax_amount'] ?? 0), 2);
        $storedTotal = round((float) ($inv['total_amount'] ?? 0), 2);

        $lineCount = count($items);
        $sumPreTaxNet = 0.0;
        $sumLineDiscount = 0.0;
        $sumLineTotal = 0.0;
        $insufficientEvidence = false;
        $reasons = [];
        $lineDrift = false;

        foreach ($items as $it) {
            if (!is_array($it)) {
                $insufficientEvidence = true;
                $reasons[] = 'non_array_invoice_item_row';

                continue;
            }
            $qty = $this->finiteMoneyComponent($it['quantity'] ?? null, 'quantity', $reasons);
            $unit = $this->finiteMoneyComponent($it['unit_price'] ?? null, 'unit_price', $reasons);
            $disc = $this->finiteMoneyComponent($it['discount_amount'] ?? null, 'discount_amount', $reasons);
            $taxRate = $this->finiteMoneyComponent($it['tax_rate'] ?? null, 'tax_rate', $reasons);
            $lineTotalStored = $this->finiteMoneyComponent($it['line_total'] ?? null, 'line_total', $reasons);

            if ($qty === null || $unit === null || $disc === null || $taxRate === null || $lineTotalStored === null) {
                $insufficientEvidence = true;

                continue;
            }

            $preTaxNet = round($qty * $unit - $disc, 2);
            $sumPreTaxNet = round($sumPreTaxNet + $preTaxNet, 2);
            $sumLineDiscount = round($sumLineDiscount + $disc, 2);
            $sumLineTotal = round($sumLineTotal + $lineTotalStored, 2);

            $expectedLineTotal = round($preTaxNet * (1 + $taxRate / 100), 2);
            if (abs($expectedLineTotal - $lineTotalStored) > self::LINE_TOTAL_COMPONENT_EPSILON) {
                $lineDrift = true;
                $reasons[] = 'line_persisted_total_drift_from_line_money_fields';
            }
        }

        $lineSubtotalEvidence = $sumPreTaxNet;
        $lineDiscountEvidence = $sumLineDiscount;
        $lineTotalEvidence = $sumLineTotal;
        $lineTaxEvidence = null;

        $subtotalDelta = round($storedSubtotal - $lineTotalEvidence, 2);
        $discountDelta = round($storedDiscount - $lineDiscountEvidence, 2);
        $taxDelta = null;

        $expectedTotalFromRollup = round($lineTotalEvidence - $storedDiscount + $storedTax, 2);
        $totalDelta = round($storedTotal - $expectedTotalFromRollup, 2);

        $subtotalMismatch = $lineCount > 0 && !$insufficientEvidence && abs($subtotalDelta) > self::MATERIAL_ROLLUP_DELTA;
        $totalMismatch = $lineCount > 0 && !$insufficientEvidence && abs($totalDelta) > self::MATERIAL_ROLLUP_DELTA;
        $discountMismatch = $lineCount > 0 && !$insufficientEvidence && abs($discountDelta) > self::MATERIAL_DISCOUNT_CHANNEL_DELTA;
        $taxMismatch = false;

        if ($subtotalMismatch) {
            $reasons[] = 'stored_subtotal_differs_from_sum_line_total_evidence';
        }
        if ($totalMismatch) {
            $reasons[] = 'stored_total_differs_from_line_total_evidence_minus_header_discount_plus_header_tax';
        }
        if ($discountMismatch) {
            $reasons[] = 'stored_invoice_discount_differs_from_sum_line_discount_amount';
        }
        if ($lineDrift) {
            $reasons[] = 'line_persisted_total_drift_from_line_money_fields';
        }

        if ($lineCount > 0 && $insufficientEvidence) {
            $reasons[] = 'invoice_has_lines_but_non_finite_or_missing_money_fields';
        }

        if ($lineCount === 0 && ($storedSubtotal !== 0.0 || $storedTotal !== 0.0 || $storedDiscount !== 0.0 || $storedTax !== 0.0)) {
            $reasons[] = 'invoice_has_no_lines_but_header_money_non_zero';
        }

        $reasons = array_values(array_unique($reasons));

        [$truthClass, $rollupReasons] = $this->resolveTruthClass(
            $lineCount,
            $insufficientEvidence,
            $subtotalMismatch,
            $totalMismatch,
            $discountMismatch,
            $lineDrift,
            $reasons
        );

        return [
            'invoice_id' => $invoiceId,
            'invoice_status' => $status,
            'invoice_branch_id' => $branchId,
            'invoice_currency' => $currency,
            'invoice_line_count' => $lineCount,
            'stored_subtotal_amount' => $storedSubtotal,
            'stored_discount_amount' => $storedDiscount,
            'stored_tax_amount' => $storedTax,
            'stored_total_amount' => $storedTotal,
            'line_subtotal_evidence' => $lineCount > 0 && !$insufficientEvidence ? $lineSubtotalEvidence : null,
            'line_discount_evidence' => $lineCount > 0 && !$insufficientEvidence ? $lineDiscountEvidence : null,
            'line_tax_evidence' => $lineTaxEvidence,
            'line_total_evidence' => $lineCount > 0 && !$insufficientEvidence ? $lineTotalEvidence : null,
            'subtotal_delta' => $lineCount > 0 && !$insufficientEvidence ? $subtotalDelta : null,
            'discount_delta' => $lineCount > 0 && !$insufficientEvidence ? $discountDelta : null,
            'tax_delta' => $taxDelta,
            'total_delta' => $lineCount > 0 && !$insufficientEvidence ? $totalDelta : null,
            'mixed_domain_line_count' => $mixedDomainLineCount,
            'financial_rollup_truth_class' => $truthClass,
            'financial_rollup_truth_reasons' => $rollupReasons,
            'subtotal_mismatch' => $subtotalMismatch,
            'discount_mismatch' => $discountMismatch,
            'tax_mismatch' => $taxMismatch,
            'total_mismatch' => $totalMismatch,
            'line_total_component_drift' => $lineDrift,
        ];
    }

    /**
     * @param list<string> $reasonsSink
     */
    private function finiteMoneyComponent(mixed $raw, string $field, array &$reasonsSink): ?float
    {
        if ($raw === null || $raw === '') {
            $reasonsSink[] = 'missing_numeric_invoice_item_field:' . $field;

            return null;
        }
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        $v = (float) $raw;
        if (!is_finite($v)) {
            $reasonsSink[] = 'non_finite_invoice_item_field:' . $field;

            return null;
        }

        return $v;
    }

    /**
     * @param list<string> $reasons
     * @return array{0: string, 1: list<string>}
     */
    private function resolveTruthClass(
        int $lineCount,
        bool $insufficientEvidence,
        bool $subtotalMismatch,
        bool $totalMismatch,
        bool $discountMismatch,
        bool $lineDrift,
        array $reasons
    ): array {
        if ($lineCount > 0 && $insufficientEvidence) {
            return ['insufficient_line_financial_evidence', $reasons];
        }

        $contradicted = $subtotalMismatch || $totalMismatch || $discountMismatch || $lineDrift
            || ($lineCount === 0 && $this->headerMoneyNonZeroWithoutLines($reasons));

        if ($contradicted) {
            return ['contradicted_financial_rollup', $reasons];
        }

        return ['coherent_financial_rollup', $reasons];
    }

    /**
     * @param list<string> $reasons
     */
    private function headerMoneyNonZeroWithoutLines(array $reasons): bool
    {
        return in_array('invoice_has_no_lines_but_header_money_non_zero', $reasons, true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function contradictionSampleSlice(array $row): array
    {
        $pick = [
            'invoice_id',
            'invoice_status',
            'invoice_branch_id',
            'invoice_currency',
            'invoice_line_count',
            'stored_subtotal_amount',
            'stored_discount_amount',
            'stored_tax_amount',
            'stored_total_amount',
            'line_subtotal_evidence',
            'line_discount_evidence',
            'line_tax_evidence',
            'line_total_evidence',
            'subtotal_delta',
            'discount_delta',
            'tax_delta',
            'total_delta',
            'mixed_domain_line_count',
            'financial_rollup_truth_class',
            'financial_rollup_truth_reasons',
            'subtotal_mismatch',
            'discount_mismatch',
            'tax_mismatch',
            'total_mismatch',
            'line_total_component_drift',
        ];
        $out = [];
        foreach ($pick as $k) {
            $out[$k] = $row[$k] ?? null;
        }

        return $out;
    }
}
