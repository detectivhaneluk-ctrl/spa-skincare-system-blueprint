<?php

declare(strict_types=1);

/**
 * Read-only: scan recent users for access shapes that need repair (orphans, contradictions).
 * Usage: php scripts/audit_user_access_shape_repair_candidates.php [limit]
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

$limit = isset($argv[1]) ? max(1, min(500, (int) $argv[1])) : 150;

$db = app(\Core\App\Database::class);
$rows = $db->fetchAll(
    "SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id DESC LIMIT {$limit}"
);
$eval = app(\Core\Auth\UserAccessShapeService::class);
$candidates = [];
foreach ($rows as $r) {
    $id = (int) ($r['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $s = $eval->evaluate($id);
    $contr = $s['contradictions'] ?? [];
    $state = (string) ($s['canonical_state'] ?? '');
    if ($contr !== [] || $state === 'tenant_orphan_blocked' || $state === 'tenant_suspended_organization') {
        $candidates[] = [
            'user_id' => $id,
            'email' => $s['email'] ?? null,
            'canonical_state' => $state,
            'contradictions' => $contr,
            'suggested_repairs' => $s['suggested_repairs'] ?? [],
            'expected_home_path' => $s['expected_home_path'] ?? null,
        ];
    }
}

echo json_encode(['scanned' => count($rows), 'candidates' => $candidates], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
