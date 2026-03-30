<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only audit: **active** (`is_active = 1`) non-deleted products composed from
 * {@see ProductCatalogReferenceCoverageAuditService} + {@see ProductLegacyNormalizedTaxonomyCoherenceAuditService}
 * + SKU/name identity trim checks. No writes.
 *
 * Task: {@code UNIFIED-CATALOG-DOMAIN-TRUTH-TAIL-WAVE-01}.
 */
final class ActiveProductDomainReadinessAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AFFECTED_ID_SAMPLE_CAP = 100;

    public const AUDIT_SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const READINESS_CLASSES = [
        'domain_ready',
        'identity_incomplete',
        'reference_risk',
        'taxonomy_cleanup_needed',
        'normalization_needed',
        'unusable_catalog_state',
        'mixed_domain_anomaly',
    ];

    /** @var list<string> */
    private const COVERAGE_UNUSABLE = [
        'missing_category_reference',
        'inactive_category_reference',
        'missing_brand_reference',
        'inactive_brand_reference',
    ];

    /** @var list<string> */
    private const COVERAGE_RISK = [
        'category_branch_contract_risk',
        'brand_branch_contract_risk',
        'mixed_reference_anomaly',
    ];

    public function __construct(
        private ProductCatalogReferenceCoverageAuditService $coverageAudit,
        private ProductLegacyNormalizedTaxonomyCoherenceAuditService $coherenceAudit,
        private Database $db,
    ) {
    }

    /**
     * @return array{
     *     generated_at_utc: string,
     *     audit_schema_version: int,
     *     product_id_filter: int|null,
     *     products_scanned: int,
     *     readiness_class_counts: array<string, int>,
     *     affected_products_count: int,
     *     affected_product_ids_sample: list<int>,
     *     examples_by_readiness_class: array<string, list<array<string, mixed>>>,
     *     notes: list<string>,
     *     products: list<array<string, mixed>>
     * }
     */
    public function run(?int $productId = null): array
    {
        $generatedAt = gmdate('c');

        $activeIds = $this->resolveActiveProductIds($productId);
        $coverage = $this->coverageAudit->run($productId);
        $coherence = $this->coherenceAudit->run($productId);

        $covById = [];
        foreach ($coverage['products'] as $row) {
            $covById[(int) $row['product_id']] = $row;
        }
        $cohById = [];
        foreach ($coherence['products'] as $row) {
            $cohById[(int) $row['product_id']] = $row;
        }

        $counts = array_fill_keys(self::READINESS_CLASSES, 0);
        $examples = [];
        foreach (self::READINESS_CLASSES as $c) {
            $examples[$c] = [];
        }

        $affectedIds = [];
        $products = [];

        foreach ($activeIds as $id) {
            $covRow = $covById[$id] ?? null;
            $cohRow = $cohById[$id] ?? null;
            if ($covRow === null || $cohRow === null) {
                continue;
            }

            $cc = (string) $covRow['coverage_class'];
            $tc = (string) $cohRow['coherence_class'];
            $sku = (string) ($covRow['sku'] ?? '');
            $name = (string) ($covRow['name'] ?? '');
            $identity = $this->identityPresent($sku, $name);

            [$readiness, $reasonCodes] = $this->classifyReadiness($identity, $cc, $tc);

            $payload = [
                'product_id' => $id,
                'sku' => $sku,
                'name' => $name,
                'branch_id' => $covRow['branch_id'] ?? null,
                'identity_present' => $identity,
                'category_reference_coverage_class' => $cc,
                'taxonomy_coherence_class' => $tc,
                'readiness_class' => $readiness,
                'reason_codes' => $reasonCodes,
            ];

            $counts[$readiness]++;
            $products[] = $payload;

            if ($readiness !== 'domain_ready') {
                $affectedIds[] = $id;
            }

            if (count($examples[$readiness]) < self::EXAMPLE_CAP) {
                $examples[$readiness][] = $payload;
            }
        }

        $scanned = count($products);

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'product_id_filter' => $productId,
            'products_scanned' => $scanned,
            'readiness_class_counts' => $counts,
            'affected_products_count' => count($affectedIds),
            'affected_product_ids_sample' => array_slice($affectedIds, 0, self::AFFECTED_ID_SAMPLE_CAP),
            'examples_by_readiness_class' => $examples,
            'notes' => [
                'Scans only products with deleted_at IS NULL AND is_active = 1.',
                'category_reference_coverage_class is the coverage_class from ProductCatalogReferenceCoverageAuditService (full ZIP WAVE-01).',
                'taxonomy_coherence_class is the coherence_class from ProductLegacyNormalizedTaxonomyCoherenceAuditService (WAVE-02).',
                'This audit does not expose products publicly, implement storefronts, or mixed-sales flows; it is a stored-fact readiness composition only.',
            ],
            'products' => $products,
        ];
    }

    /**
     * @return list<int>
     */
    private function resolveActiveProductIds(?int $productId): array
    {
        if ($productId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM products WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
                [$productId]
            );

            return $row !== null ? [(int) $row['id']] : [];
        }

        $rows = $this->db->fetchAll(
            'SELECT id FROM products WHERE deleted_at IS NULL AND is_active = 1 ORDER BY id ASC'
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    private function identityPresent(string $sku, string $name): bool
    {
        return trim($sku) !== '' && trim($name) !== '';
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function classifyReadiness(bool $identity, string $cc, string $tc): array
    {
        $taxCoherent = $tc === 'taxonomy_coherent';
        $taxUnusable = $tc === 'unusable_normalized_reference';
        $taxMixed = $tc === 'mixed_taxonomy_anomaly';
        $taxCleanup = $tc === 'legacy_cleanup_only' || $tc === 'legacy_normalized_mismatch';
        $taxNorm = $tc === 'normalization_missing_only';

        $covOk = $cc === 'catalog_reference_ok';
        $covUnusable = in_array($cc, self::COVERAGE_UNUSABLE, true);
        $covRisk = in_array($cc, self::COVERAGE_RISK, true);

        $mixedDomain = ($covOk && $taxMixed)
            || ($covRisk && !$taxCoherent)
            || ($covUnusable && ($taxCleanup || $taxNorm || $taxMixed));

        if ($mixedDomain) {
            return [
                'mixed_domain_anomaly',
                $this->composeReasons('mixed_domain_anomaly', $identity, $cc, $tc),
            ];
        }

        if ($covUnusable || $taxUnusable) {
            return [
                'unusable_catalog_state',
                $this->composeReasons('unusable_catalog_state', $identity, $cc, $tc),
            ];
        }

        if ($identity && $covOk && $taxCoherent) {
            return [
                'domain_ready',
                $this->composeReasons('domain_ready', $identity, $cc, $tc),
            ];
        }

        if ($covOk && $taxNorm) {
            return [
                'normalization_needed',
                $this->composeReasons('normalization_needed', $identity, $cc, $tc),
            ];
        }

        if ($covOk && $taxCleanup) {
            return [
                'taxonomy_cleanup_needed',
                $this->composeReasons('taxonomy_cleanup_needed', $identity, $cc, $tc),
            ];
        }

        if ($covRisk && $taxCoherent) {
            return [
                'reference_risk',
                $this->composeReasons('reference_risk', $identity, $cc, $tc),
            ];
        }

        if (!$identity) {
            return [
                'identity_incomplete',
                $this->composeReasons('identity_incomplete', $identity, $cc, $tc),
            ];
        }

        return [
            'mixed_domain_anomaly',
            $this->composeReasons('mixed_domain_anomaly_residual', $identity, $cc, $tc),
        ];
    }

    /**
     * @return list<string>
     */
    private function composeReasons(string $rule, bool $identity, string $cc, string $tc): array
    {
        $out = [
            'readiness_rule:' . $rule,
            'coverage_class:' . $cc,
            'taxonomy_coherence_class:' . $tc,
            'identity_present:' . ($identity ? 'true' : 'false'),
        ];

        return $out;
    }
}
