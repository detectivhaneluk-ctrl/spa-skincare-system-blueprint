<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Repositories\ProductCategoryRepository;

/**
 * Audit-only: find **multi-node** directed cycles in the live category parent graph (child → parent),
 * group into SCC clusters, and emit a deterministic manual fix plan (no DB writes).
 *
 * Self-parent (single-node) edges are **omitted** from this graph so those rows are not cycle clusters here
 * (handled by {@see ProductCategoryTreeIntegrityAuditService}).
 *
 * **Fix-plan rule (stable):** within each cluster, choose **max(member id)** as {@see suggested_break_category_id};
 * recommended action: **`SET parent_id = NULL`** on that row only — removes one edge of the cycle.
 */
final class ProductCategoryTreeCycleClusterAuditService
{
    private const CYCLE_CLUSTER_EXAMPLE_CAP = 24;

    /** Matches {@see ProductCategoryRepository::ancestorChainContainsId} guard. */
    private const ANCESTOR_GUARD_CAP = 64;

    public function __construct(private ProductCategoryRepository $categories)
    {
    }

    /**
     * Live multi-node SCC clusters (same graph as {@see run()}); used by safe-break apply.
     *
     * @return array{
     *     categories_scanned: int,
     *     live_categories_with_parent_count: int,
     *     cycle_cluster_count: int,
     *     clusters: list<list<int>>,
     *     liveById: array<int, array<string, mixed>>,
     *     rawParentId: array<int, int|null>
     * }
     */
    public function discoverLiveMultiNodeCycleClusters(): array
    {
        $rows = $this->categories->listLiveForParentGraphAuditInResolvedTenantCatalogScope();
        /** @var array<int, array<string, mixed>> $liveById */
        $liveById = [];
        foreach ($rows as $r) {
            $liveById[(int) $r['id']] = $r;
        }

        $n = count($liveById);
        $withParent = 0;
        /** @var array<int, int|null> $parentTarget live child → live parent, or null if no multi-node edge */
        $parentTarget = [];
        /** @var array<int, int|null> $rawParentId category_id → stored parent_id (may be self / missing) */
        $rawParentId = [];

        foreach ($liveById as $id => $r) {
            $p = $this->normalizeParentId($r['parent_id'] ?? null);
            $rawParentId[$id] = $p;
            if ($p !== null && $p > 0) {
                $withParent++;
            }
            if ($p === null || $p <= 0 || $p === $id || !isset($liveById[$p])) {
                $parentTarget[$id] = null;
            } else {
                $parentTarget[$id] = $p;
            }
        }

        $sccs = $this->tarjanSccsAtLeastTwo($liveById, $parentTarget);
        usort($sccs, static function (array $a, array $b): int {
            return min($a) <=> min($b);
        });
        foreach ($sccs as &$comp) {
            sort($comp);
        }
        unset($comp);

        return [
            'categories_scanned' => $n,
            'live_categories_with_parent_count' => $withParent,
            'cycle_cluster_count' => count($sccs),
            'clusters' => $sccs,
            'liveById' => $liveById,
            'rawParentId' => $rawParentId,
        ];
    }

    /**
     * @return array{
     *     categories_scanned: int,
     *     live_categories_with_parent_count: int,
     *     cycle_cluster_count: int,
     *     categories_in_cycle_clusters_count: int,
     *     largest_cycle_cluster_size: int,
     *     over_cap_ancestor_walk_count: int,
     *     cycle_cluster_examples: list<array<string, mixed>>
     * }
     */
    public function run(): array
    {
        $d = $this->discoverLiveMultiNodeCycleClusters();
        $sccs = $d['clusters'];
        $liveById = $d['liveById'];
        $rawParentId = $d['rawParentId'];

        $inClusters = 0;
        $largest = 0;
        foreach ($sccs as $comp) {
            $c = count($comp);
            $inClusters += $c;
            $largest = max($largest, $c);
        }

        $overCap = 0;
        foreach (array_keys($liveById) as $cid) {
            if ($this->ancestorWalkStillContinuesAfterGuardCap($cid, $liveById)) {
                $overCap++;
            }
        }

        $examples = [];
        foreach ($sccs as $comp) {
            if (count($examples) >= self::CYCLE_CLUSTER_EXAMPLE_CAP) {
                break;
            }
            sort($comp);
            $examples[] = $this->buildClusterExample($comp, $liveById, $rawParentId);
        }

        return [
            'categories_scanned' => $d['categories_scanned'],
            'live_categories_with_parent_count' => $d['live_categories_with_parent_count'],
            'cycle_cluster_count' => $d['cycle_cluster_count'],
            'categories_in_cycle_clusters_count' => $inClusters,
            'largest_cycle_cluster_size' => $largest,
            'over_cap_ancestor_walk_count' => $overCap,
            'cycle_cluster_examples' => $examples,
        ];
    }

    /**
     * Tarjan SCC on graph with at most one outgoing edge per node (to another live category, never self).
     *
     * @param array<int, array<string, mixed>> $liveById
     * @param array<int, int|null> $parentTarget
     * @return list<list<int>> components with >= 2 vertices
     */
    private function tarjanSccsAtLeastTwo(array $liveById, array $parentTarget): array
    {
        $index = 0;
        /** @var list<int> $stack */
        $stack = [];
        /** @var array<int, bool> $onStack */
        $onStack = [];
        /** @var array<int, int> $indices */
        $indices = [];
        /** @var array<int, int> $lowlink */
        $lowlink = [];
        /** @var list<list<int>> $sccs */
        $sccs = [];

        $strongConnect = null;
        $strongConnect = function (int $v) use (
            &$strongConnect,
            &$index,
            &$stack,
            &$onStack,
            &$indices,
            &$lowlink,
            &$sccs,
            $parentTarget,
            $liveById
        ): void {
            $indices[$v] = $index;
            $lowlink[$v] = $index;
            $index++;
            $stack[] = $v;
            $onStack[$v] = true;

            $w = $parentTarget[$v] ?? null;
            if ($w !== null && isset($liveById[$w])) {
                if (!isset($indices[$w])) {
                    $strongConnect($w);
                    $lowlink[$v] = min($lowlink[$v], $lowlink[$w]);
                } elseif (!empty($onStack[$w])) {
                    $lowlink[$v] = min($lowlink[$v], $indices[$w]);
                }
            }

            if ($lowlink[$v] === $indices[$v]) {
                /** @var list<int> $comp */
                $comp = [];
                while ($stack !== []) {
                    $w = array_pop($stack);
                    $onStack[$w] = false;
                    $comp[] = $w;
                    if ($w === $v) {
                        break;
                    }
                }
                if (count($comp) >= 2) {
                    $sccs[] = $comp;
                }
            }
        };

        foreach (array_keys($liveById) as $v) {
            if (!isset($indices[$v])) {
                $strongConnect($v);
            }
        }

        return $sccs;
    }

    /**
     * True when walking live parent_id chains from {@param $startCategoryId} can take
     * {@see ANCESTOR_GUARD_CAP} hops and still has another live parent hop (same failure mode as a capped
     * ancestor walk: deep tree or unresolved cycle from the guard’s perspective).
     *
     * @param array<int, array<string, mixed>> $liveById
     */
    private function ancestorWalkStillContinuesAfterGuardCap(int $startCategoryId, array $liveById): bool
    {
        $current = $startCategoryId;
        for ($i = 0; $i < self::ANCESTOR_GUARD_CAP; $i++) {
            $row = $liveById[$current] ?? null;
            if ($row === null) {
                return false;
            }
            $p = $this->normalizeParentId($row['parent_id'] ?? null);
            if ($p === null || $p <= 0) {
                return false;
            }
            if ($p === $current) {
                return false;
            }
            if (!isset($liveById[$p])) {
                return false;
            }
            $current = $p;
        }
        $row = $liveById[$current] ?? null;
        if ($row === null) {
            return false;
        }
        $p = $this->normalizeParentId($row['parent_id'] ?? null);

        return $p !== null && $p > 0 && $p !== $current && isset($liveById[$p]);
    }

    /**
     * @param list<int> $memberIds sorted ascending
     * @param array<int, array<string, mixed>> $liveById
     * @param array<int, int|null> $rawParentId
     * @return array<string, mixed>
     */
    private function buildClusterExample(array $memberIds, array $liveById, array $rawParentId): array
    {
        $memberNames = [];
        foreach ($memberIds as $mid) {
            $memberNames[] = (string) ($liveById[$mid]['name'] ?? '');
        }

        $parentMap = [];
        foreach ($memberIds as $mid) {
            $rp = $rawParentId[$mid] ?? null;
            $parentMap[$mid] = $rp;
        }
        ksort($parentMap, SORT_NUMERIC);

        $breakId = max($memberIds);
        $breakParent = $rawParentId[$breakId] ?? null;

        $branchTokens = [];
        foreach ($memberIds as $mid) {
            $b = $liveById[$mid]['branch_id'] ?? null;
            $branchTokens[] = ($b === null || $b === '') ? 'global' : 'branch:' . (int) $b;
        }
        $branchTokens = array_values(array_unique($branchTokens));
        sort($branchTokens, SORT_STRING);
        $branchSummary = implode(',', $branchTokens);

        return [
            'cluster_id' => 'scc_min_' . min($memberIds),
            'branch_scope_summary' => $branchSummary,
            'member_ids' => $memberIds,
            'member_names' => $memberNames,
            'current_parent_map' => $parentMap,
            'suggested_break_category_id' => $breakId,
            'suggested_break_current_parent_id' => $breakParent,
            'suggested_fix' => 'set parent_id = NULL',
            'reason_for_choice' => 'Highest numeric category id in the cluster; clearing its parent removes one edge of the directed cycle (deterministic, manual).',
        ];
    }

    private function normalizeParentId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }
}
