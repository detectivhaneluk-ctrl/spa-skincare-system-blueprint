<?php

declare(strict_types=1);

/**
 * Build nested JSON for OlliraServicesMap (React Flow) from category tree + services.
 *
 * @param array<int, list<array<string, mixed>>> $childrenByParent
 * @param array<int, array<string, mixed>>       $categoryById
 * @param array<int, list<array<string, mixed>>> $servicesByCategory
 * @param list<array<string, mixed>>             $uncategorized
 */
function ollira_services_build_flow_tree(
    array $childrenByParent,
    array $categoryById,
    array $servicesByCategory,
    array $uncategorized,
    string $rootLabel = 'Ollira',
    ?int $subtreeCategoryId = null,
): array {
    $svcPayload = static function (array $s): array {
        return [
            'id'              => 'svc-' . (int) ($s['id'] ?? 0),
            'type'            => 'service',
            'name'            => (string) ($s['name'] ?? ''),
            'durationMinutes' => (int) ($s['duration_minutes'] ?? 0),
            'price'           => (float) ($s['price'] ?? 0),
            'currency'        => 'AMD',
            'sku'             => (string) ($s['sku'] ?? ''),
        ];
    };

    $buildCat = null;
    $buildCat = function (int $id) use (&$buildCat, $childrenByParent, $servicesByCategory, $categoryById, $svcPayload): ?array {
        if (!isset($categoryById[$id])) {
            return null;
        }
        $row  = $categoryById[$id];
        $kids = [];

        foreach ($childrenByParent[$id] ?? [] as $ch) {
            $cid = (int) ($ch['id'] ?? 0);
            $n   = $buildCat($cid);
            if ($n !== null) {
                $kids[] = $n;
            }
        }
        foreach ($servicesByCategory[$id] ?? [] as $s) {
            $kids[] = $svcPayload($s);
        }

        return [
            'id'       => 'cat-' . $id,
            'type'     => 'category',
            'name'     => (string) ($row['name'] ?? ''),
            'children' => $kids,
        ];
    };

    $rootChildren = [];

    if ($subtreeCategoryId !== null && isset($categoryById[$subtreeCategoryId])) {
        $one = $buildCat($subtreeCategoryId);
        if ($one !== null) {
            $rootChildren[] = $one;
        }
    } else {
        foreach ($childrenByParent[0] ?? [] as $rc) {
            $cid = (int) ($rc['id'] ?? 0);
            $n   = $buildCat($cid);
            if ($n !== null) {
                $rootChildren[] = $n;
            }
        }
        if ($uncategorized !== []) {
            $uc = [];
            foreach ($uncategorized as $s) {
                $uc[] = $svcPayload($s);
            }
            $rootChildren[] = [
                'id'       => 'cat-uncategorized',
                'type'     => 'category',
                'name'     => 'Uncategorized',
                'children' => $uc,
            ];
        }
    }

    return [
        'id'       => 'root',
        'type'     => 'root',
        'name'     => $rootLabel,
        'children' => $rootChildren,
    ];
}
