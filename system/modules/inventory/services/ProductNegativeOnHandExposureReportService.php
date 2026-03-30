<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Core\App\Database;

/**
 * Read-only report: products with negative on-hand and conservative exposure classification from movement history.
 * Does not apply {@see ProductStockQuantityPolicy} or mutate stock.
 */
final class ProductNegativeOnHandExposureReportService
{
    public const EXPOSURE_SCHEMA_VERSION = '1.0.0';

    public const RECENT_WINDOW_DAYS = 90;

    public const EXAMPLE_CAP = 15;

    public const CLASS_ADJUSTMENT_TAIL_LIKELY = 'adjustment_tail_likely';

    public const CLASS_COUNT_ADJUSTMENT_TAIL_LIKELY = 'count_adjustment_tail_likely';

    public const CLASS_MIXED_HISTORY_UNPROVEN = 'mixed_history_unproven';

    public const CLASS_SUSPICIOUS_POLICY_BREACH_HISTORY = 'suspicious_policy_breach_history';

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $generatedAt = gmdate('Y-m-d\TH:i:s\Z');

        $products = $this->db->fetchAll(
            'SELECT id, sku, name, branch_id, stock_quantity
             FROM products
             WHERE deleted_at IS NULL AND stock_quantity < 0
             ORDER BY id ASC'
        );

        $productIds = array_map(fn (array $p) => (int) $p['id'], $products);
        $latestByProduct = $this->fetchLatestMovementByProduct($productIds);
        $recentCounts = $this->fetchRecentMovementCountsByProduct($productIds);

        $rows = [];
        $classCounts = [
            self::CLASS_ADJUSTMENT_TAIL_LIKELY => 0,
            self::CLASS_COUNT_ADJUSTMENT_TAIL_LIKELY => 0,
            self::CLASS_MIXED_HISTORY_UNPROVEN => 0,
            self::CLASS_SUSPICIOUS_POLICY_BREACH_HISTORY => 0,
        ];

        foreach ($products as $p) {
            $pid = (int) $p['id'];
            $latest = $latestByProduct[$pid] ?? null;
            $latestAt = $latest ? (string) ($latest['created_at'] ?? '') : null;
            $latestType = $latest ? (string) ($latest['movement_type'] ?? '') : null;

            $rc = $recentCounts[$pid] ?? [];
            $manual = (int) ($rc['manual_adjustment'] ?? 0);
            $countAdj = (int) ($rc['count_adjustment'] ?? 0);
            $sale = (int) ($rc['sale'] ?? 0);
            $internal = (int) ($rc['internal_usage'] ?? 0);
            $damaged = (int) ($rc['damaged'] ?? 0);

            [$exposureClass, $reasonCodes] = $this->classify(
                $latestType,
                $manual,
                $countAdj,
                $sale,
                $internal,
                $damaged
            );

            $classCounts[$exposureClass]++;

            $rows[] = [
                'product_id' => $pid,
                'sku' => (string) ($p['sku'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'branch_id' => isset($p['branch_id']) && $p['branch_id'] !== '' && $p['branch_id'] !== null
                    ? (int) $p['branch_id']
                    : null,
                'stock_quantity' => (float) ($p['stock_quantity'] ?? 0),
                'latest_movement_at' => $latestAt,
                'latest_movement_type' => $latestType,
                'recent_manual_adjustment_count' => $manual,
                'recent_count_adjustment_count' => $countAdj,
                'recent_sale_count' => $sale,
                'recent_internal_usage_count' => $internal,
                'recent_damaged_count' => $damaged,
                'exposure_class' => $exposureClass,
                'reason_codes' => $reasonCodes,
            ];
        }

        $criticalExposureCount = $classCounts[self::CLASS_SUSPICIOUS_POLICY_BREACH_HISTORY];

        $examples = array_slice($rows, 0, self::EXAMPLE_CAP);

        $productsScanned = (int) ($this->db->fetchOne('SELECT COUNT(*) AS c FROM products WHERE deleted_at IS NULL')['c'] ?? 0);

        return [
            'exposure_schema_version' => self::EXPOSURE_SCHEMA_VERSION,
            'generated_at_utc' => $generatedAt,
            'recent_window_days' => self::RECENT_WINDOW_DAYS,
            'products_scanned' => $productsScanned,
            'negative_on_hand_products_count' => count($rows),
            'exposure_class_counts' => $classCounts,
            'critical_exposure_count' => $criticalExposureCount,
            'examples' => $examples,
            'products' => $rows,
        ];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array{created_at: string, movement_type: string}>
     */
    private function fetchLatestMovementByProduct(array $productIds): array
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
                "SELECT product_id, created_at, movement_type
                 FROM (
                     SELECT sm.product_id,
                            sm.created_at,
                            sm.movement_type,
                            ROW_NUMBER() OVER (PARTITION BY sm.product_id ORDER BY sm.created_at DESC, sm.id DESC) AS rn
                     FROM stock_movements sm
                     WHERE sm.product_id IN ({$ph})
                 ) t
                 WHERE t.rn = 1",
                $chunk
            );
            foreach ($rows as $row) {
                $pid = (int) ($row['product_id'] ?? 0);
                if ($pid > 0) {
                    $out[$pid] = [
                        'created_at' => (string) ($row['created_at'] ?? ''),
                        'movement_type' => (string) ($row['movement_type'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, array<string, int>>
     */
    private function fetchRecentMovementCountsByProduct(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id) => $id > 0)));
        if ($productIds === []) {
            return [];
        }

        $out = [];
        $chunkSize = 400;
        $days = (int) self::RECENT_WINDOW_DAYS;
        for ($i = 0, $n = count($productIds); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($productIds, $i, $chunkSize);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $params = $chunk;
            $rows = $this->db->fetchAll(
                "SELECT sm.product_id, sm.movement_type, COUNT(*) AS c
                 FROM stock_movements sm
                 WHERE sm.product_id IN ({$ph})
                   AND sm.created_at >= (UTC_TIMESTAMP() - INTERVAL {$days} DAY)
                   AND sm.movement_type IN ('manual_adjustment', 'count_adjustment', 'sale', 'internal_usage', 'damaged')
                 GROUP BY sm.product_id, sm.movement_type",
                $params
            );
            foreach ($rows as $row) {
                $pid = (int) ($row['product_id'] ?? 0);
                $mt = (string) ($row['movement_type'] ?? '');
                if ($pid <= 0 || $mt === '') {
                    continue;
                }
                if (!isset($out[$pid])) {
                    $out[$pid] = [];
                }
                $out[$pid][$mt] = (int) ($row['c'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function classify(
        ?string $latestType,
        int $manual,
        int $countAdj,
        int $sale,
        int $internal,
        int $damaged
    ): array {
        $reasons = [];
        $guardedRecent = $sale + $internal + $damaged;

        if ($latestType === null || $latestType === '') {
            $reasons[] = 'no_movement_rows_for_product';
            return [self::CLASS_MIXED_HISTORY_UNPROVEN, $this->sortReasons($reasons)];
        }

        if (in_array($latestType, ['sale', 'internal_usage', 'damaged'], true)) {
            $reasons[] = 'latest_movement_is_policy_guarded_deduction_while_on_hand_negative';
            if ($guardedRecent > 0) {
                $reasons[] = 'recent_window_includes_guarded_deduction_types';
            }

            return [self::CLASS_SUSPICIOUS_POLICY_BREACH_HISTORY, $this->sortReasons($reasons)];
        }

        if ($latestType === 'count_adjustment') {
            $reasons[] = 'latest_movement_is_count_adjustment';
            if ($countAdj > 0) {
                $reasons[] = 'recent_count_adjustment_activity_present';
            }

            return [self::CLASS_COUNT_ADJUSTMENT_TAIL_LIKELY, $this->sortReasons($reasons)];
        }

        if ($latestType === 'manual_adjustment') {
            $reasons[] = 'latest_movement_is_manual_adjustment';
            if ($manual > 0) {
                $reasons[] = 'recent_manual_adjustment_activity_present';
            }

            return [self::CLASS_ADJUSTMENT_TAIL_LIKELY, $this->sortReasons($reasons)];
        }

        $reasons[] = 'latest_movement_type_not_a_simple_negative_on_hand_explanation';
        if ($guardedRecent > 0 && $manual === 0 && $countAdj === 0) {
            $reasons[] = 'recent_guarded_deductions_without_manual_or_count_in_window';
        }

        return [self::CLASS_MIXED_HISTORY_UNPROVEN, $this->sortReasons($reasons)];
    }

    /**
     * @param list<string> $reasons
     * @return list<string>
     */
    private function sortReasons(array $reasons): array
    {
        $reasons = array_values(array_unique($reasons));
        sort($reasons, SORT_STRING);

        return $reasons;
    }
}
