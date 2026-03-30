<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only matrix: **active** (`is_active = 1`) non-deleted products combining
 * {@see ActiveProductDomainReadinessAuditService} and {@see ProductNegativeOnHandExposureReportService}
 * with on-hand quantity. No writes; no storefront or mixed-sales semantics.
 *
 * Task: {@code UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-02}.
 */
final class ActiveProductInventoryReadinessMatrixAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AFFECTED_ID_SAMPLE_CAP = 100;

    public const AUDIT_SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const INVENTORY_READINESS_CLASSES = [
        'operationally_ready',
        'domain_ready_but_negative_on_hand',
        'domain_not_ready',
        'unusable_inventory_state',
        'mixed_operational_anomaly',
    ];

    /** @var list<string> */
    private const DOMAIN_NOT_READY_CLASSES = [
        'identity_incomplete',
        'reference_risk',
        'taxonomy_cleanup_needed',
        'normalization_needed',
    ];

    public function __construct(
        private ActiveProductDomainReadinessAuditService $domainAudit,
        private ProductNegativeOnHandExposureReportService $negativeExposureReport,
        private Database $db,
    ) {
    }

    /**
     * @return array{
     *     generated_at_utc: string,
     *     audit_schema_version: int,
     *     product_id_filter: int|null,
     *     products_scanned: int,
     *     inventory_readiness_class_counts: array<string, int>,
     *     affected_products_count: int,
     *     affected_product_ids_sample: list<int>,
     *     examples_by_inventory_readiness_class: array<string, list<array<string, mixed>>>,
     *     notes: list<string>,
     *     products: list<array<string, mixed>>
     * }
     */
    public function run(?int $productId = null): array
    {
        $generatedAt = gmdate('c');

        $domainPayload = $this->domainAudit->run($productId);
        $exposurePayload = $this->negativeExposureReport->run();

        $negByProductId = [];
        foreach ($exposurePayload['products'] as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            if ($pid > 0) {
                $negByProductId[$pid] = $row;
            }
        }

        $domainProducts = $domainPayload['products'];
        $ids = array_map(static fn (array $p): int => (int) $p['product_id'], $domainProducts);
        $stockById = $this->fetchStockQuantitiesByProductId($ids);

        $counts = array_fill_keys(self::INVENTORY_READINESS_CLASSES, 0);
        $examples = [];
        foreach (self::INVENTORY_READINESS_CLASSES as $c) {
            $examples[$c] = [];
        }

        $affectedIds = [];
        $products = [];

        foreach ($domainProducts as $dRow) {
            $id = (int) $dRow['product_id'];
            $domainClass = (string) ($dRow['readiness_class'] ?? '');
            $domainReasonCodes = [];
            if (isset($dRow['reason_codes']) && is_array($dRow['reason_codes'])) {
                foreach ($dRow['reason_codes'] as $rc) {
                    $domainReasonCodes[] = (string) $rc;
                }
            }
            $stockQty = (float) ($stockById[$id] ?? 0.0);
            $negRow = $negByProductId[$id] ?? null;
            $negativeOnHand = $stockQty < 0.0;
            $exposureClass = null;
            if ($negRow !== null) {
                $exposureClass = (string) ($negRow['exposure_class'] ?? '');
            }

            [$invClass, $reasonCodes] = $this->classifyInventoryReadiness(
                $domainClass,
                $stockQty,
                $negRow,
                $negativeOnHand,
                $exposureClass,
                $domainReasonCodes
            );

            $row = [
                'product_id' => $id,
                'sku' => (string) ($dRow['sku'] ?? ''),
                'name' => (string) ($dRow['name'] ?? ''),
                'branch_id' => $dRow['branch_id'] ?? null,
                'stock_quantity' => $stockQty,
                'domain_readiness_class' => $domainClass,
                'negative_on_hand' => $negativeOnHand,
                'negative_on_hand_exposure_class' => $exposureClass,
                'inventory_readiness_class' => $invClass,
                'reason_codes' => $reasonCodes,
            ];

            $counts[$invClass]++;
            $products[] = $row;

            if ($invClass !== 'operationally_ready') {
                $affectedIds[] = $id;
            }

            if (count($examples[$invClass]) < self::EXAMPLE_CAP) {
                $examples[$invClass][] = $row;
            }
        }

        usort($products, static fn (array $a, array $b): int => ((int) $a['product_id']) <=> ((int) $b['product_id']));

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'product_id_filter' => $productId,
            'products_scanned' => count($products),
            'inventory_readiness_class_counts' => $counts,
            'affected_products_count' => count($affectedIds),
            'affected_product_ids_sample' => array_slice($affectedIds, 0, self::AFFECTED_ID_SAMPLE_CAP),
            'examples_by_inventory_readiness_class' => $examples,
            'notes' => [
                'Scans only products with deleted_at IS NULL AND is_active = 1.',
                'domain_readiness_class is readiness_class from ActiveProductDomainReadinessAuditService (UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-01).',
                'negative_on_hand_exposure_class is exposure_class from ProductNegativeOnHandExposureReportService when stock_quantity < 0; otherwise null.',
                'stock_quantity is read from products.stock_quantity (read-only SELECT) for matrix rows.',
                'ProductNegativeOnHandExposureReportService is invoked in full; negative_on_hand_exposure_class applies only to products present in its products list.',
                'This audit does not repair data, implement storefronts, mixed-sales baskets, or public catalog exposure.',
            ],
            'products' => $products,
        ];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, float>
     */
    private function fetchStockQuantitiesByProductId(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id) => $id > 0)));
        if ($productIds === []) {
            return [];
        }

        $out = [];
        $chunkSize = 400;
        for ($i = 0, $n = count($productIds); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($productIds, $i, $chunkSize);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->fetchAll(
                "SELECT id, stock_quantity FROM products WHERE deleted_at IS NULL AND id IN ({$ph})",
                $chunk
            );
            foreach ($rows as $r) {
                $pid = (int) ($r['id'] ?? 0);
                if ($pid > 0) {
                    $out[$pid] = (float) ($r['stock_quantity'] ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $negRow
     * @param list<string> $domainReasonCodes
     * @return array{0: string, 1: list<string>}
     */
    private function classifyInventoryReadiness(
        string $domainClass,
        float $stockQty,
        ?array $negRow,
        bool $negativeOnHand,
        ?string $exposureClass,
        array $domainReasonCodes
    ): array {
        $suspicious = $exposureClass === ProductNegativeOnHandExposureReportService::CLASS_SUSPICIOUS_POLICY_BREACH_HISTORY;

        if ($domainClass === 'unusable_catalog_state' || $suspicious) {
            return [
                'unusable_inventory_state',
                $this->composeReasonCodes('unusable_inventory_state', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
            ];
        }

        if ($domainClass === 'domain_ready' && !$negativeOnHand) {
            if ($negRow !== null) {
                return [
                    'mixed_operational_anomaly',
                    $this->composeReasonCodes('mixed_operational_anomaly_non_negative_stock_with_exposure_report_row', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
                ];
            }

            return [
                'operationally_ready',
                $this->composeReasonCodes('operationally_ready', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
            ];
        }

        if ($domainClass === 'domain_ready' && $negativeOnHand) {
            if ($negRow !== null) {
                return [
                    'domain_ready_but_negative_on_hand',
                    $this->composeReasonCodes('domain_ready_but_negative_on_hand', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
                ];
            }

            return [
                'mixed_operational_anomaly',
                $this->composeReasonCodes('mixed_operational_anomaly_negative_stock_missing_exposure_row', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
            ];
        }

        if ($domainClass === 'mixed_domain_anomaly') {
            return [
                'mixed_operational_anomaly',
                $this->composeReasonCodes('mixed_operational_anomaly_domain_mixed', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
            ];
        }

        if (in_array($domainClass, self::DOMAIN_NOT_READY_CLASSES, true) && !$negativeOnHand) {
            return [
                'domain_not_ready',
                $this->composeReasonCodes('domain_not_ready', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
            ];
        }

        if (in_array($domainClass, self::DOMAIN_NOT_READY_CLASSES, true) && $negativeOnHand) {
            return [
                'mixed_operational_anomaly',
                $this->composeReasonCodes('mixed_operational_anomaly_domain_issue_and_negative_on_hand', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
            ];
        }

        return [
            'mixed_operational_anomaly',
            $this->composeReasonCodes('mixed_operational_anomaly_residual', $domainClass, $stockQty, $negRow, $negativeOnHand, $exposureClass, $domainReasonCodes),
        ];
    }

    /**
     * @param array<string, mixed>|null $negRow
     * @param list<string> $domainReasonCodes
     * @return list<string>
     */
    private function composeReasonCodes(
        string $rule,
        string $domainClass,
        float $stockQty,
        ?array $negRow,
        bool $negativeOnHand,
        ?string $exposureClass,
        array $domainReasonCodes
    ): array {
        $merged = $domainReasonCodes;
        $merged[] = 'inventory_readiness_rule:' . $rule;
        $merged[] = 'domain_readiness_class:' . $domainClass;
        $merged[] = 'negative_on_hand:' . ($negativeOnHand ? 'true' : 'false');
        $merged[] = 'stock_quantity:' . $this->formatStock($stockQty);
        if ($exposureClass !== null && $exposureClass !== '') {
            $merged[] = 'negative_on_hand_exposure_class:' . $exposureClass;
        } else {
            $merged[] = 'negative_on_hand_exposure_class:(none)';
        }

        if ($negRow !== null && isset($negRow['reason_codes']) && is_array($negRow['reason_codes'])) {
            foreach ($negRow['reason_codes'] as $rc) {
                $merged[] = (string) $rc;
            }
        }

        $merged[] = 'composed_from:active_product_domain_readiness_audit';
        $merged[] = 'composed_from:product_negative_on_hand_exposure_report';

        $merged = array_values(array_unique($merged));
        sort($merged, SORT_STRING);

        return $merged;
    }

    private function formatStock(float $q): string
    {
        if (floor($q) == $q) {
            return (string) (int) $q;
        }

        return (string) $q;
    }
}
