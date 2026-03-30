<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\App\Database;

/**
 * Read-only audit: {@code invoice_items} on non-deleted {@code invoices}, classified by stored
 * {@code item_type} + {@code source_id} vs {@code products} / {@code services} row existence (same numeric id can
 * theoretically match both tables).
 *
 * Does **not** implement mixed-sales behavior; no writes.
 *
 * Wave: {@code MIXED-SALES-SERVICE-AND-RETAIL-LINE-ARCHITECTURE-01} (line-type domain boundary truth).
 */
final class SalesLineDomainBoundaryTruthAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AUDIT_SCHEMA_VERSION = 1;

    /** Accepted writer-facing line kinds in repo ({@see InvoiceService}, {@see InvoiceStockSettlementService}). */
    private const KNOWN_ITEM_TYPES = [
        'service',
        'product',
        'manual',
        'gift_voucher',
        'gift_card',
        'series',
        'client_account',
        'membership',
        'tip',
    ];

    public const REFERENCE_SHAPES = [
        'service_only_reference',
        'product_only_reference',
        'both_product_and_service_reference',
        'no_domain_reference',
        'unsupported_reference_shape',
    ];

    public const LINE_DOMAIN_CLASSES = [
        'clear_service_line',
        'clear_retail_product_line',
        'mixed_domain_line',
        'orphaned_domain_reference',
        'unsupported_line_contract',
        'ambiguous_domain_story',
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
        $params = [];
        $invoiceWhere = '';
        if ($invoiceIdFilter !== null) {
            $invoiceWhere = ' AND i.id = ?';
            $params[] = $invoiceIdFilter;
        }

        $sql = <<<SQL
SELECT ii.id AS invoice_item_id,
       ii.invoice_id,
       ii.item_type,
       ii.source_id,
       ii.quantity,
       ii.unit_price,
       i.status AS invoice_status,
       i.branch_id AS invoice_branch_id,
       p.id AS join_product_id,
       p.deleted_at AS product_deleted_at,
       p.is_active AS product_is_active,
       s.id AS join_service_id,
       s.deleted_at AS service_deleted_at,
       s.is_active AS service_is_active
FROM invoice_items ii
INNER JOIN invoices i ON i.id = ii.invoice_id
LEFT JOIN products p ON p.id = ii.source_id
LEFT JOIN services s ON s.id = ii.source_id
WHERE i.deleted_at IS NULL{$invoiceWhere}
ORDER BY ii.id ASC
SQL;

        $rows = $this->db->fetchAll($sql, $params);

        $refCounts = array_fill_keys(self::REFERENCE_SHAPES, 0);
        $classCounts = array_fill_keys(self::LINE_DOMAIN_CLASSES, 0);
        $examples = [];
        foreach (self::LINE_DOMAIN_CLASSES as $c) {
            $examples[$c] = [];
        }

        $lines = [];
        $invoiceIds = [];
        $affectedInvoiceIds = [];

        foreach ($rows as $r) {
            $classified = $this->classifyLine($r);
            $line = $classified['line'];
            $lines[] = $line;

            $shape = $line['reference_shape'];
            $class = $line['line_domain_class'];
            $refCounts[$shape]++;
            $classCounts[$class]++;

            $invId = (int) $line['invoice_id'];
            $invoiceIds[$invId] = true;
            if (!in_array($class, ['clear_service_line', 'clear_retail_product_line'], true)) {
                $affectedInvoiceIds[$invId] = true;
            }

            if (count($examples[$class]) < self::EXAMPLE_CAP) {
                $examples[$class][] = $line;
            }
        }

        $affectedCount = 0;
        foreach ($lines as $ln) {
            if (!in_array($ln['line_domain_class'], ['clear_service_line', 'clear_retail_product_line'], true)) {
                $affectedCount++;
            }
        }

        $sampleIds = array_keys($affectedInvoiceIds);
        sort($sampleIds, SORT_NUMERIC);
        $sampleIds = array_slice($sampleIds, 0, 20);

        $notes = [
            'invoice_items has a single source_id column; product catalog lines use item_type=product and source_id=products.id; service lines use item_type=service and source_id=services.id; manual lines expect source_id null.',
            'There is no staff_id column on invoice_items; staff attribution for commissions uses payroll tables, not the line row (see migration 076 comment).',
            'reference_shape is keyed only on whether source_id matches a products row, a services row, both, or neither — independent of item_type.',
            'The same numeric id can exist in both products and services (separate sequences); that yields both_product_and_service_reference and is treated as mixed domain.',
            'Product "usable" for clear_retail_product_line matches settlement expectations: row exists, deleted_at IS NULL, is_active = 1.',
            'Service "usable" for clear_service_line: row exists, deleted_at IS NULL, is_active = 1.',
            'Manual lines with no source_id are neither clear service nor clear retail; they are ambiguous_domain_story for service-vs-retail boundary purposes.',
            'This audit does not read stock_movements, appointments, packages, gift cards, or memberships.',
        ];

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'invoice_id_filter' => $invoiceIdFilter,
            'lines_scanned' => count($lines),
            'invoices_scanned' => count($invoiceIds),
            'reference_shape_counts' => $refCounts,
            'line_domain_class_counts' => $classCounts,
            'affected_lines_count' => $affectedCount,
            'affected_invoice_ids_sample' => $sampleIds,
            'examples_by_line_domain_class' => $examples,
            'notes' => $notes,
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{line: array<string, mixed>}
     */
    private function classifyLine(array $r): array
    {
        $invoiceItemId = (int) $r['invoice_item_id'];
        $invoiceId = (int) $r['invoice_id'];
        $itemTypeRaw = isset($r['item_type']) ? strtolower(trim((string) $r['item_type'])) : '';
        $sourceRaw = $r['source_id'] ?? null;
        $sourceId = $sourceRaw === null || $sourceRaw === '' ? null : (int) $sourceRaw;
        if ($sourceId !== null && $sourceId <= 0) {
            $sourceId = null;
        }

        $invStatus = (string) ($r['invoice_status'] ?? '');
        $branchRaw = $r['invoice_branch_id'] ?? null;
        $invoiceBranchId = $branchRaw === null || $branchRaw === '' ? null : (int) $branchRaw;

        $productRowId = isset($r['join_product_id']) && $r['join_product_id'] !== '' && $r['join_product_id'] !== null
            ? (int) $r['join_product_id']
            : null;
        $serviceRowId = isset($r['join_service_id']) && $r['join_service_id'] !== '' && $r['join_service_id'] !== null
            ? (int) $r['join_service_id']
            : null;

        $hasProductRow = $productRowId !== null;
        $hasServiceRow = $serviceRowId !== null;

        $productUsable = $hasProductRow
            && empty($r['product_deleted_at'])
            && $this->truthyActive($r['product_is_active'] ?? 1);
        $serviceUsable = $hasServiceRow
            && empty($r['service_deleted_at'])
            && $this->truthyActive($r['service_is_active'] ?? 1);

        if ($sourceId === null) {
            $referenceShape = 'no_domain_reference';
        } elseif ($hasProductRow && $hasServiceRow) {
            $referenceShape = 'both_product_and_service_reference';
        } elseif ($hasProductRow) {
            $referenceShape = 'product_only_reference';
        } elseif ($hasServiceRow) {
            $referenceShape = 'service_only_reference';
        } else {
            $referenceShape = 'unsupported_reference_shape';
        }

        $productIdPayload = ($itemTypeRaw === 'product' && $sourceId !== null) ? $sourceId : null;
        $serviceIdPayload = ($itemTypeRaw === 'service' && $sourceId !== null) ? $sourceId : null;

        $qtyStr = isset($r['quantity']) ? $this->decimalToString($r['quantity']) : '0';
        $priceStr = isset($r['unit_price']) ? $this->decimalToString($r['unit_price']) : '0';

        $serviceDeleted = $hasServiceRow && !empty($r['service_deleted_at']);
        $serviceInactive = $hasServiceRow && !$this->truthyActive($r['service_is_active'] ?? 1);
        $productDeleted = $hasProductRow && !empty($r['product_deleted_at']);
        $productInactive = $hasProductRow && !$this->truthyActive($r['product_is_active'] ?? 1);

        $reasonCodes = [];
        $lineClass = $this->resolveLineDomainClass(
            $itemTypeRaw,
            $sourceId,
            $referenceShape,
            $productUsable,
            $serviceUsable,
            $hasProductRow,
            $hasServiceRow,
            $serviceDeleted,
            $serviceInactive,
            $productDeleted,
            $productInactive,
            $reasonCodes
        );

        $line = [
            'invoice_id' => $invoiceId,
            'invoice_item_id' => $invoiceItemId,
            'invoice_status' => $invStatus,
            'invoice_branch_id' => $invoiceBranchId,
            'item_type' => $itemTypeRaw,
            'product_id' => $productIdPayload,
            'service_id' => $serviceIdPayload,
            'staff_id' => null,
            'quantity' => $qtyStr,
            'unit_price' => $priceStr,
            'reference_shape' => $referenceShape,
            'line_domain_class' => $lineClass,
            'reason_codes' => $reasonCodes,
        ];

        return ['line' => $line];
    }

    /**
     * @param list<string> $reasonCodes
     */
    private function resolveLineDomainClass(
        string $itemType,
        ?int $sourceId,
        string $referenceShape,
        bool $productUsable,
        bool $serviceUsable,
        bool $hasProductRow,
        bool $hasServiceRow,
        bool $serviceDeleted,
        bool $serviceInactive,
        bool $productDeleted,
        bool $productInactive,
        array &$reasonCodes
    ): string {
        if (!in_array($itemType, self::KNOWN_ITEM_TYPES, true)) {
            $reasonCodes[] = 'item_type_not_in_known_contract_set';
            $reasonCodes[] = 'known_item_types_are_service_product_manual';

            return 'unsupported_line_contract';
        }

        $cashierExtended = ['gift_voucher', 'gift_card', 'series', 'client_account', 'membership', 'tip'];
        if (in_array($itemType, $cashierExtended, true)) {
            $reasonCodes[] = 'cashier_extended_line_contract';

            return 'ambiguous_domain_story';
        }

        if ($referenceShape === 'both_product_and_service_reference') {
            $reasonCodes[] = 'source_id_matches_both_products_and_services_rows';

            return 'mixed_domain_line';
        }

        if ($itemType === 'manual') {
            if ($sourceId !== null) {
                $reasonCodes[] = 'manual_line_contract_expects_null_source_id';
                $reasonCodes[] = 'non_null_source_id_on_manual_line';

                return 'mixed_domain_line';
            }
            $reasonCodes[] = 'manual_line_has_no_product_or_service_attribution';

            return 'ambiguous_domain_story';
        }

        if ($itemType === 'service') {
            if ($sourceId === null) {
                $reasonCodes[] = 'service_line_contract_requires_non_null_source_id';

                return 'unsupported_line_contract';
            }
            if ($referenceShape === 'unsupported_reference_shape') {
                $reasonCodes[] = 'source_id_not_found_in_products_or_services';

                return 'orphaned_domain_reference';
            }
            if ($referenceShape === 'product_only_reference') {
                $reasonCodes[] = 'item_type_service_but_source_id_resolves_product_row_only';

                return 'mixed_domain_line';
            }
            // service_only_reference
            if (!$serviceUsable) {
                if ($serviceDeleted) {
                    $reasonCodes[] = 'service_soft_deleted';
                }
                if ($serviceInactive) {
                    $reasonCodes[] = 'service_inactive';
                }
                if ($reasonCodes === []) {
                    $reasonCodes[] = 'service_reference_unusable';
                }

                return 'orphaned_domain_reference';
            }

            return 'clear_service_line';
        }

        if ($itemType === 'product') {
            if ($sourceId === null) {
                $reasonCodes[] = 'product_line_contract_requires_non_null_source_id';

                return 'unsupported_line_contract';
            }
            if ($referenceShape === 'unsupported_reference_shape') {
                $reasonCodes[] = 'source_id_not_found_in_products_or_services';

                return 'orphaned_domain_reference';
            }
            if ($referenceShape === 'service_only_reference') {
                $reasonCodes[] = 'item_type_product_but_source_id_resolves_service_row_only';

                return 'mixed_domain_line';
            }
            if (!$productUsable) {
                if (!$hasProductRow) {
                    $reasonCodes[] = 'product_row_missing';
                } else {
                    if ($productDeleted) {
                        $reasonCodes[] = 'product_soft_deleted';
                    }
                    if ($productInactive) {
                        $reasonCodes[] = 'product_inactive';
                    }
                }
                if ($reasonCodes === []) {
                    $reasonCodes[] = 'product_reference_unusable';
                }

                return 'orphaned_domain_reference';
            }

            return 'clear_retail_product_line';
        }

        $reasonCodes[] = 'unreachable_item_type_branch';

        return 'unsupported_line_contract';
    }

    private function truthyActive(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return true;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }

        return true;
    }

    private function decimalToString(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (is_float($v) || is_int($v)) {
            return (string) $v;
        }

        return trim((string) $v);
    }

}
