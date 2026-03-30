<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only audit: legacy {@code products.category} / {@code products.brand} vs normalized FK + live taxonomy names,
 * trim + case-insensitive name compare, plus unusable FK detection (missing / soft-deleted / branch assignability).
 *
 * Task: {@code PRODUCT-BRAND-CATALOG-TAIL-WAVE-02}.
 */
final class ProductLegacyNormalizedTaxonomyCoherenceAuditService
{
    public const EXAMPLE_CAP = 5;

    public const AFFECTED_ID_SAMPLE_CAP = 100;

    public const AUDIT_SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const AXIS_STATUSES = [
        'aligned',
        'legacy_only',
        'normalized_only',
        'blank_on_both',
        'text_mismatch',
        'normalized_reference_unusable',
    ];

    /** @var list<string> */
    public const COHERENCE_CLASSES = [
        'taxonomy_coherent',
        'legacy_cleanup_only',
        'normalization_missing_only',
        'dual_blank_taxonomy',
        'legacy_normalized_mismatch',
        'unusable_normalized_reference',
        'mixed_taxonomy_anomaly',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *     generated_at_utc: string,
     *     audit_schema_version: int,
     *     product_id_filter: int|null,
     *     products_scanned: int,
     *     coherence_class_counts: array<string, int>,
     *     category_axis_status_counts: array<string, int>,
     *     brand_axis_status_counts: array<string, int>,
     *     affected_products_count: int,
     *     affected_product_ids_sample: list<int>,
     *     examples_by_coherence_class: array<string, list<array<string, mixed>>>,
     *     notes: list<string>,
     *     products: list<array<string, mixed>>
     * }
     */
    public function run(?int $productId = null): array
    {
        $generatedAt = gmdate('c');
        $params = [];
        $filterSql = '';
        if ($productId !== null) {
            $filterSql = ' AND p.id = ?';
            $params[] = $productId;
        }

        $sql = <<<SQL
SELECT p.id AS product_id,
       p.sku,
       p.name,
       p.branch_id AS product_branch_id,
       p.category AS legacy_category,
       p.brand AS legacy_brand,
       p.product_category_id,
       p.product_brand_id,
       pc.id AS pc_row_id,
       pc.name AS pc_name,
       pc.branch_id AS pc_branch_id,
       pc.deleted_at AS pc_deleted_at,
       pb.id AS pb_row_id,
       pb.name AS pb_name,
       pb.branch_id AS pb_branch_id,
       pb.deleted_at AS pb_deleted_at
FROM products p
LEFT JOIN product_categories pc ON pc.id = p.product_category_id
LEFT JOIN product_brands pb ON pb.id = p.product_brand_id
WHERE p.deleted_at IS NULL{$filterSql}
ORDER BY p.id ASC
SQL;

        $rows = $this->db->fetchAll($sql, $params);

        $cohCounts = array_fill_keys(self::COHERENCE_CLASSES, 0);
        $catAxisCounts = array_fill_keys(self::AXIS_STATUSES, 0);
        $brandAxisCounts = array_fill_keys(self::AXIS_STATUSES, 0);
        $examples = [];
        foreach (self::COHERENCE_CLASSES as $c) {
            $examples[$c] = [];
        }

        $affectedIds = [];
        $products = [];

        foreach ($rows as $r) {
            $eval = $this->evaluateProductRow($r);
            $class = $eval['coherence_class'];
            $payload = $eval['payload'];
            $cohCounts[$class]++;
            $catAxisCounts[(string) $payload['category_axis_status']]++;
            $brandAxisCounts[(string) $payload['brand_axis_status']]++;
            $products[] = $payload;

            if ($class !== 'taxonomy_coherent') {
                $affectedIds[] = (int) $payload['product_id'];
            }

            if (count($examples[$class]) < self::EXAMPLE_CAP) {
                $examples[$class][] = $payload;
            }
        }

        $scanned = count($rows);

        return [
            'generated_at_utc' => $generatedAt,
            'audit_schema_version' => self::AUDIT_SCHEMA_VERSION,
            'product_id_filter' => $productId,
            'products_scanned' => $scanned,
            'coherence_class_counts' => $cohCounts,
            'category_axis_status_counts' => $catAxisCounts,
            'brand_axis_status_counts' => $brandAxisCounts,
            'affected_products_count' => count($affectedIds),
            'affected_product_ids_sample' => array_slice($affectedIds, 0, self::AFFECTED_ID_SAMPLE_CAP),
            'examples_by_coherence_class' => $examples,
            'notes' => [
                'Legacy vs normalized comparison uses TRIM + case-insensitive string equality only (no slugging, folding, or fuzzy match).',
                'Normalized reference is unusable when FK is set but the row is missing, soft-deleted, or fails ProductTaxonomyAssignabilityService branch pairing (mirrored as boolean checks).',
                'taxonomy_coherent requires both axes aligned (legacy text non-empty and matches normalized name under the comparison rules).',
                'This audit does not run backfill or clear FKs; remediation is out of band.',
            ],
            'products' => $products,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array{coherence_class: string, payload: array<string, mixed>}
     */
    private function evaluateProductRow(array $r): array
    {
        $productId = (int) $r['product_id'];
        $sku = (string) ($r['sku'] ?? '');
        $name = (string) ($r['name'] ?? '');
        $branchRaw = $r['product_branch_id'] ?? null;
        $productBranchId = ($branchRaw !== null && $branchRaw !== '') ? (int) $branchRaw : null;

        $legacyCat = $this->legacyText($r['legacy_category'] ?? null);
        $legacyBrand = $this->legacyText($r['legacy_brand'] ?? null);

        $catFk = $this->nullableInt($r['product_category_id'] ?? null);
        $brandFk = $this->nullableInt($r['product_brand_id'] ?? null);

        $pcRowId = $this->nullableInt($r['pc_row_id'] ?? null);
        $pcNameRaw = isset($r['pc_name']) ? (string) $r['pc_name'] : '';
        $pcName = $pcNameRaw === '' ? null : trim($pcNameRaw);
        $pcBranchRaw = $r['pc_branch_id'] ?? null;
        $pcBranchId = ($pcRowId === null) ? null : (($pcBranchRaw !== null && $pcBranchRaw !== '') ? (int) $pcBranchRaw : null);
        $pcDeleted = $this->isDeletedAtSet($r['pc_deleted_at'] ?? null);
        $pcLive = $pcRowId !== null && !$pcDeleted;
        $pcAssignable = $pcLive && $this->taxonomyAssignableToProductBranch($productBranchId, $pcBranchId);

        $pbRowId = $this->nullableInt($r['pb_row_id'] ?? null);
        $pbNameRaw = isset($r['pb_name']) ? (string) $r['pb_name'] : '';
        $pbName = $pbNameRaw === '' ? null : trim($pbNameRaw);
        $pbBranchRaw = $r['pb_branch_id'] ?? null;
        $pbBranchId = ($pbRowId === null) ? null : (($pbBranchRaw !== null && $pbBranchRaw !== '') ? (int) $pbBranchRaw : null);
        $pbDeleted = $this->isDeletedAtSet($r['pb_deleted_at'] ?? null);
        $pbLive = $pbRowId !== null && !$pbDeleted;
        $pbAssignable = $pbLive && $this->taxonomyAssignableToProductBranch($productBranchId, $pbBranchId);

        $catAxis = $this->axisStatus(
            $legacyCat,
            $catFk,
            $pcRowId,
            $pcDeleted,
            $pcAssignable,
            $pcName
        );
        $brandAxis = $this->axisStatus(
            $legacyBrand,
            $brandFk,
            $pbRowId,
            $pbDeleted,
            $pbAssignable,
            $pbName
        );

        [$coherenceClass, $reasonCodes] = $this->coherenceClass($catAxis, $brandAxis);

        $payload = [
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'branch_id' => $productBranchId,
            'legacy_category' => $legacyCat,
            'normalized_category_id' => $catFk,
            'normalized_category_name' => $pcRowId !== null ? $pcName : null,
            'category_axis_status' => $catAxis,
            'legacy_brand' => $legacyBrand,
            'normalized_brand_id' => $brandFk,
            'normalized_brand_name' => $pbRowId !== null ? $pbName : null,
            'brand_axis_status' => $brandAxis,
            'coherence_class' => $coherenceClass,
            'reason_codes' => $reasonCodes,
        ];

        return ['coherence_class' => $coherenceClass, 'payload' => $payload];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function coherenceClass(string $c, string $b): array
    {
        $u = 'normalized_reference_unusable';
        $m = 'text_mismatch';
        $l = 'legacy_only';
        $n = 'normalized_only';
        $a = 'aligned';
        $blank = 'blank_on_both';

        if ($c === $a && $b === $a) {
            return ['taxonomy_coherent', ['both_axes_legacy_matches_normalized_name']];
        }
        if ($c === $blank && $b === $blank) {
            return ['dual_blank_taxonomy', ['both_axes_no_legacy_text_and_no_normalized_fk']];
        }

        $hasU = ($c === $u || $b === $u);
        $hasM = ($c === $m || $b === $m);
        $hasL = ($c === $l || $b === $l);
        $crossLN = ($c === $n && $b === $l) || ($c === $l && $b === $n);

        if ($hasU) {
            if ($hasM || $hasL || $crossLN || ($c === $n && $b === $u) || ($c === $u && $b === $n)) {
                return ['mixed_taxonomy_anomaly', $this->mixedReasons($c, $b)];
            }
            if ($c === $u && $b === $u) {
                return ['unusable_normalized_reference', ['both_axes_normalized_reference_unusable']];
            }

            return ['unusable_normalized_reference', $this->unusableReasons($c, $b)];
        }

        if ($hasM) {
            if ($hasL || $crossLN || ($c === $m && $b === $blank) || ($c === $blank && $b === $m)) {
                return ['mixed_taxonomy_anomaly', $this->mixedReasons($c, $b)];
            }

            return ['legacy_normalized_mismatch', $this->mismatchReasons($c, $b)];
        }

        $axisOkForCleanup = [$a, $n, $blank];
        if (!$hasL
            && in_array($c, $axisOkForCleanup, true)
            && in_array($b, $axisOkForCleanup, true)
            && !($c === $blank && $b === $blank)
            && ($c === $n || $b === $n)) {
            return ['legacy_cleanup_only', ['usable_normalized_fk_present_legacy_blank_on_at_least_one_axis_no_mismatch']];
        }

        if ($hasL && !$crossLN) {
            $allowed = [$a, $n, $l, $blank];
            if (in_array($c, $allowed, true) && in_array($b, $allowed, true)) {
                return ['normalization_missing_only', ['legacy_text_present_normalized_fk_absent_on_one_or_both_axes']];
            }
        }

        return ['mixed_taxonomy_anomaly', $this->mixedReasons($c, $b)];
    }

    /** @return list<string> */
    private function mixedReasons(string $c, string $b): array
    {
        return ['category_axis_' . $c, 'brand_axis_' . $b];
    }

    /** @return list<string> */
    private function unusableReasons(string $c, string $b): array
    {
        $out = [];
        if ($c === 'normalized_reference_unusable') {
            $out[] = 'category_normalized_reference_unusable';
        }
        if ($b === 'normalized_reference_unusable') {
            $out[] = 'brand_normalized_reference_unusable';
        }
        if ($out === []) {
            $out[] = 'unusable_normalized_reference';
        }

        return $out;
    }

    /** @return list<string> */
    private function mismatchReasons(string $c, string $b): array
    {
        $out = [];
        if ($c === 'text_mismatch') {
            $out[] = 'category_legacy_normalized_name_mismatch';
        }
        if ($b === 'text_mismatch') {
            $out[] = 'brand_legacy_normalized_name_mismatch';
        }

        return $out !== [] ? $out : ['legacy_normalized_mismatch'];
    }

    private function legacyText(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }

    private function axisStatus(
        ?string $legacy,
        ?int $fk,
        ?int $rowId,
        bool $rowDeleted,
        bool $assignableLive,
        ?string $normalizedName
    ): string {
        $fkSet = $fk !== null;
        $legacyEmpty = $legacy === null;
        $rowMissing = $fkSet && $rowId === null;
        $unusable = $fkSet && ($rowMissing || $rowDeleted || !$assignableLive);

        if ($unusable) {
            return 'normalized_reference_unusable';
        }
        if (!$fkSet && $legacyEmpty) {
            return 'blank_on_both';
        }
        if (!$fkSet) {
            return 'legacy_only';
        }

        $usableName = $normalizedName ?? '';
        if ($legacyEmpty) {
            return 'normalized_only';
        }
        if ($this->namesMatchCi($legacy, $usableName)) {
            return 'aligned';
        }

        return 'text_mismatch';
    }

    private function namesMatchCi(string $a, string $b): bool
    {
        return strcasecmp(trim($a), trim($b)) === 0;
    }

    /**
     * Mirrors {@see ProductTaxonomyAssignabilityService::assertTaxonomyBranchMatchesProduct} for live rows.
     */
    private function taxonomyAssignableToProductBranch(?int $productBranchId, ?int $taxonomyBranchId): bool
    {
        $taxBranch = $taxonomyBranchId;
        if ($productBranchId === null) {
            return $taxBranch === null;
        }
        if ($taxBranch === null) {
            return true;
        }

        return $taxBranch === $productBranchId;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    private function isDeletedAtSet(mixed $v): bool
    {
        return $v !== null && (string) $v !== '';
    }
}
